<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Equipment;
use App\Entity\Invoice;
use App\Entity\Property;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deterministic property/customer health scoring engine (Phase 10A).
 *
 * Health is calculated from existing CRM data without mutating any records.
 */
final class CustomerHealthCalculatorService
    implements PropertyHealthCalculatorInterface
{
    /* ── Health categories ──────────────────────────────────────────── */
    public const CATEGORY_HEALTHY          = 'healthy';
    public const CATEGORY_NEEDS_ATTENTION  = 'needs_attention';
    public const CATEGORY_AT_RISK          = 'at_risk';
    public const CATEGORY_DORMANT          = 'dormant';
    public const CATEGORY_LOST             = 'lost';

    /* ── Thresholds (tweak these to retune scoring) ─────────────────── */
    private int $maxDaysSinceLastJob         = 365;
    private int $maxDaysSinceLastCall        = 180;
    private int $equipmentAgeWarningDays      = 10 * 365;
    private int $warrantyExpiryWarningDays   = 90;
    private int $healthyScoreMin             = 80;
    private int $maxUnresolvedForNonHealthy  = 1;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /* ── Public API ─────────────────────────────────────────────────── */

    /**
     * Calculate health for a single property.
     *
     * @return array{score: int<0,100>, category: self::CATEGORY_*, factors: list<array{key:string,label:string,impact:int}>}
     */
    public function calculate(Property $property): array
    {
        $tenant = $property->getTenant();

        /** @var Equipment[] $equipmentList */
        $equipmentList = $this->entityManager
            ->getRepository(Equipment::class)
            ->createQueryBuilder('e')
            ->select('e')
            ->where('e.tenant = :tenant')
            ->andWhere('e.property = :property')
            ->andWhere('e.isArchived = false')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->getQuery()
            ->getResult();

        /** @var Invoice[] $invoices */
        $invoices = $this->entityManager
            ->getRepository(Invoice::class)
            ->createQueryBuilder('i')
            ->select('i')
            ->where('i.tenant = :tenant')
            ->andWhere('i.property = :property')
            ->andWhere('i.status != :paid')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->setParameter('paid', Invoice::STATUS_PAID)
            ->getQuery()
            ->getResult();

        [$lastJobCompletedAt] = $this->getLastJobCompletedAt($tenant, $property);
        $lastCallAt           = $this->getLastCallAt($tenant, $property);

        $factors  = [];
        $score    = 100;

        // 1. Equipment age penalty
        $equipmentAgeScore = $this->evaluateEquipmentAge($equipmentList, $factors);
        $score -= (100 - $equipmentAgeScore) / 5;

        // 2. Days since last completed job
        $jobScore = $this->evaluateServiceRecency($lastJobCompletedAt, $factors);
        $score -= (100 - $jobScore) / 4;

        // 3. Days since last call
        $callScore = $this->evaluateCallRecency($lastCallAt, $factors);
        $score -= (100 - $callScore) / 4;

        // 4. Unresolved issues
        $unresolvedScore = $this->evaluateUnresolvedIssues($property, $tenant, $factors);
        $score -= (100 - $unresolvedScore) / 6;

        // 5. Open invoices penalty
        $invoiceScore = $this->evaluateOpenInvoices($invoices, $factors);
        $score -= (100 - $invoiceScore) / 4;

        // 6. Warranty status
        $warrantyScore = $this->evaluateWarrantyStatus($equipmentList, $factors);
        $score -= (100 - $warrantyScore) / 8;

        // Clamp to 0-100
        $score = max(0, min(100, (int) round($score)));

        return [
            'score'    => $score,
            'category' => $this->determineCategory($score),
            'factors'  => $factors,
        ];
    }

    /* ── Internal evaluation methods ─────────────────────────────── */

    private function evaluateEquipmentAge(array $equipmentList, array &$factors): int
    {
        if (0 === \count($equipmentList)) {
            $factors[] = ['key' => 'equipment_age', 'label' => 'No active equipment on file', 'impact' => -5];

            return 95;
        }

        $now        = new \DateTimeImmutable('now');
        $oldestDays = 0;

        foreach ($equipmentList as $equip) {
            if (null !== $equip->getInstalledAt()) {
                $ageDays = $now->diff($equip->getInstalledAt())->days;
                if ($ageDays > $oldestDays) {
                    $oldestDays = $ageDays;
                }
            }
        }

        if ($oldestDays === 0) {
            return 100;
        }

        if ($oldestDays < 365) {
            $factors[] = ['key' => 'equipment_age', 'label' => 'Equipment is less than 1 year old', 'impact' => 0];

            return 100;
        } elseif ($oldestDays < $this->equipmentAgeWarningDays) {
            $years  = (int) floor($oldestDays / 365);
            if ($years >= 8) {
                $factors[] = ['key' => 'equipment_age', 'label' => sprintf('Oldest equipment is %d years old', $years), 'impact' => -10];

                return 90;
            }
            $factors[] = ['key' => 'equipment_age', 'label' => sprintf('Oldest equipment is %d years old', $years), 'impact' => -5];

            return 95;
        } else {
            $years  = (int) floor($oldestDays / 365);
            $factors[] = ['key' => 'equipment_age', 'label' => sprintf('Oldest equipment is %d+ years old — replacement recommended', $years), 'impact' => -15];

            return 85;
        }
    }

    private function evaluateServiceRecency(?\DateTimeImmutable $lastJobCompletedAt, array &$factors): int
    {
        if (null === $lastJobCompletedAt) {
            $factors[] = ['key' => 'service_recency', 'label' => 'No completed jobs on record', 'impact' => -20];

            return 80;
        }

        $now    = new \DateTimeImmutable('now');
        $days   = $lastJobCompletedAt->diff($now)->days;

        if ($days < 90) {
            $factors[] = ['key' => 'service_recency', 'label' => sprintf('Last service %d days ago — recent', (int) $days), 'impact' => 0];

            return 100;
        } elseif ($days <= $this->maxDaysSinceLastJob) {
            $years = (int) floor($days / 365);
            if ($years > 0) {
                $factors[] = ['key' => 'service_recency', 'label' => sprintf('Last service %d day(s) ago (>1 yr)', $days), 'impact' => -10];

                return 90;
            }
            $factors[] = ['key' => 'service_recency', 'label' => sprintf('Last service %d days ago (>6 mo)', (int) $days), 'impact' => -5];

            return 95;
        } else {
            $factors[] = ['key' => 'service_recency', 'label' => sprintf('No service in %d days — overdue for maintenance', $days), 'impact' => -15];

            return 85;
        }
    }

    private function evaluateCallRecency(?\DateTimeImmutable $lastCallAt, array &$factors): int
    {
        if (null === $lastCallAt) {
            $factors[] = ['key' => 'call_recency', 'label' => 'No calls on record for this property', 'impact' => -10];

            return 90;
        }

        $now    = new \DateTimeImmutable('now');
        $days   = $lastCallAt->diff($now)->days;

        if ($days < 90) {
            $factors[] = ['key' => 'call_recency', 'label' => sprintf('Last call %d days ago — active contact', (int) $days), 'impact' => 0];

            return 100;
        } elseif ($days <= $this->maxDaysSinceLastCall) {
            $factors[] = ['key' => 'call_recency', 'label' => sprintf('Last call %d days ago (>6 mo)', (int) $days), 'impact' => -5];

            return 95;
        } else {
            $factors[] = ['key' => 'call_recency', 'label' => sprintf('No calls in %d days — dormant contact channel', (int) $days), 'impact' => -10];

            return 90;
        }
    }

    private function evaluateUnresolvedIssues(Property $property, Tenant $tenant, array &$factors): int
    {
        $unresolved = [];

        // Check EquipmentServiceRecord for unresolved issues
        $serviceRecords = $this->entityManager
            ->getRepository(\App\Entity\EquipmentServiceRecord::class)
            ->createQueryBuilder('s')
            ->select('IDENTITY(s.equipment) AS equipment_id, s.technicianNotes, s.recommendedRepairNotes, s.recommendedReplacementNotes')
            ->where('s.tenant = :tenant')
            ->andWhere('s.property = :property')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->getQuery()
            ->getResult();

        foreach ($serviceRecords as $record) {
            $repairNotes = $record['recommendedRepairNotes'] ?? '';
            $replacementN = $record['recommendedReplacementNotes'] ?? '';
            if (trim((string) $repairNotes) !== '' || trim((string) $replacementN) !== '') {
                $unresolved[] = [
                    'equipment_id' => $record['equipment_id'] ?? null,
                    'notes'        => trim((string) $repairNotes . (string) $replacementN),
                ];
            }
        }

        // Check Job for unresolved issues
        $jobs = $this->entityManager
            ->getRepository(\App\Entity\Job::class)
            ->createQueryBuilder('j')
            ->select('j.id, j.unresolvedIssueNotes')
            ->where('j.tenant = :tenant')
            ->andWhere('j.property = :property')
            ->andWhere('j.status != :cancelled')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->setParameter('cancelled', \App\Entity\Job::STATUS_CANCELLED)
            ->getQuery()
            ->getResult();

        foreach ($jobs as $jobRow) {
            $issueNotes = $jobRow['unresolvedIssueNotes'] ?? '';
            if (trim((string) $issueNotes) !== '') {
                $unresolved[] = [
                    'equipment_id' => null,
                    'notes'        => trim((string) $issueNotes),
                ];
            }
        }

        $count = \count($unresolved);

        if ($count <= 0) {
            $factors[] = ['key' => 'unresolved_issues', 'label' => 'No unresolved issues', 'impact' => 0];

            return 100;
        } elseif ($count <= $this->maxUnresolvedForNonHealthy) {
            $factors[] = ['key' => 'unresolved_issues', 'label' => sprintf('%d unresolved issue(s)', $count), 'impact' => -5];

            return 95;
        } else {
            $factors[] = ['key' => 'unresolved_issues', 'label' => sprintf('%d+ unresolved issue(s) — action needed', $count), 'impact' => -10];

            return 90;
        }
    }

    private function evaluateOpenInvoices(array $invoices, array &$factors): int
    {
        $totalOutstanding = 0;
        $openCount        = 0;

        foreach ($invoices as $inv) {
            if (!$inv instanceof Invoice) {
                continue;
            }

            $balance = method_exists($inv, 'getBalanceCents')
                ? $inv->getBalanceCents()
                : ($inv->getTotalCents() - $inv->getAmountPaidCents());

            if ($balance > 0) {
                $totalOutstanding += $balance;
                ++$openCount;
            }
        }

        if ($openCount <= 0) {
            $factors[] = ['key' => 'open_invoices', 'label' => 'No outstanding invoices', 'impact' => 0];

            return 100;
        } elseif ($totalOutstanding < 50000) {
            $factors[] = ['key' => 'open_invoices', 'label' => sprintf('%d invoice(s), total < $500 outstanding', $openCount), 'impact' => -3];

            return 97;
        } elseif ($totalOutstanding < 100000) {
            $factors[] = ['key' => 'open_invoices', 'label' => sprintf('%d invoice(s), total ~$%d outstanding', $openCount, (int)($totalOutstanding / 100)), 'impact' => -5];

            return 95;
        } else {
            $factors[] = ['key' => 'open_invoices', 'label' => sprintf('%d invoice(s), total >$%d outstanding — high risk', $openCount, (int)($totalOutstanding / 100)), 'impact' => -8];

            return 92;
        }
    }

    private function evaluateWarrantyStatus(array $equipmentList, array &$factors): int
    {
        $expiredSoon = 0;
        $expired     = 0;
        $active      = 0;

        $now = new \DateTimeImmutable('now');

        foreach ($equipmentList as $equip) {
            $warrantyExpiry = $equip->getWarrantyExpiresAt();

            if (null === $warrantyExpiry) {
                continue;
            }

            $interval = $now->diff($warrantyExpiry);
            $daysUntilExpiry = (int) $interval->days * (1 === $interval->invert ? -1 : 1);

            if ($daysUntilExpiry < 0) {
                ++$expired;
            } elseif ($daysUntilExpiry <= $this->warrantyExpiryWarningDays) {
                ++$expiredSoon;
            } else {
                ++$active;
            }
        }

        if ($expired + $expiredSoon === 0) {
            $factors[] = ['key' => 'warranty_status', 'label' => 'No warranty data on file', 'impact' => 0];

            return 100;
        }

        if ($expired > 0 && $active === 0) {
            $factors[] = ['key' => 'warranty_status', 'label' => sprintf('%d expired, no active warranty', $expired), 'impact' => -8];

            return 92;
        }

        if ($expiredSoon > 0) {
            $factors[] = ['key' => 'warranty_status', 'label' => sprintf('%d warranty expiring within %d days', $expiredSoon, $this->warrantyExpiryWarningDays), 'impact' => -3];

            return 97;
        }

        $factors[] = ['key' => 'warranty_status', 'label' => 'All warranties active', 'impact' => 0];

        return 100;
    }

    /* ── Category determination ───────────────────────────────────── */

    private function determineCategory(int $score): string
    {
        if ($score >= $this->healthyScoreMin) {
            return self::CATEGORY_HEALTHY;
        } elseif ($score >= 70) {
            return self::CATEGORY_NEEDS_ATTENTION;
        } elseif ($score >= 50) {
            return self::CATEGORY_AT_RISK;
        } elseif ($score >= 20) {
            return self::CATEGORY_DORMANT;
        }

        return self::CATEGORY_LOST;
    }

    /* ── Helper queries ───────────────────────────────────────────── */

    private function getLastJobCompletedAt(Tenant $tenant, Property $property): array
    {
        $maxCompletedAt = $this->entityManager
            ->getRepository(\App\Entity\Job::class)
            ->createQueryBuilder('j')
            ->select('MAX(j.completedAt)')
            ->where('j.tenant = :tenant')
            ->andWhere('j.property = :property')
            ->andWhere('j.status = :completed')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->setParameter('completed', \App\Entity\Job::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        if ($maxCompletedAt instanceof \DateTimeImmutable) {
            return [$maxCompletedAt];
        }

        if ('' === (string) $maxCompletedAt) {
            return [null];
        }

        return [new \DateTimeImmutable((string) $maxCompletedAt)];
    }

    private function getLastCallAt(Tenant $tenant, Property $property): ?\DateTimeImmutable
    {
        $lastCallAt = $this->entityManager
            ->getRepository(\App\Entity\CallSession::class)
            ->createQueryBuilder('c')
            ->select('MAX(c.updatedAt)')
            ->where('c.tenant = :tenant')
            ->andWhere('c.property = :property')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->getQuery()
            ->getSingleScalarResult();

        if ($lastCallAt instanceof \DateTimeImmutable) {
            return $lastCallAt;
        }

        if ('' === (string) $lastCallAt) {
            return null;
        }

        return new \DateTimeImmutable((string) $lastCallAt);
    }

    /* ── Threshold tuners (for config/DI injection if needed later) ─ */

    public function setMaxDaysSinceLastJob(int $days): void
    {
        $this->maxDaysSinceLastJob = $days;
    }

    public function setMaxDaysSinceLastCall(int $days): void
    {
        $this->maxDaysSinceLastCall = $days;
    }

    public function setHealthyScoreMin(int $min): void
    {
        $this->healthyScoreMin = max(0, min(100, $min));
    }

    public function getMaxDaysSinceLastJob(): int
    {
        return $this->maxDaysSinceLastJob;
    }

    public function getMaxDaysSinceLastCall(): int
    {
        return $this->maxDaysSinceLastCall;
    }

    public function getHealthyScoreMin(): int
    {
        return $this->healthyScoreMin;
    }
}
