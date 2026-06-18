<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Equipment;
use App\Entity\EquipmentServiceRecord;
use App\Entity\Invoice;
use App\Entity\NextBestActionSuggestion;
use App\Entity\Property;
use App\Entity\RetentionOpportunity;
use App\Repository\CallSessionRepository;
use App\Repository\EquipmentRepository;
use App\Repository\EquipmentServiceRecordRepository;
use App\Repository\InvoiceRepository;
use App\Repository\NextBestActionSuggestionRepository;
use App\Repository\PropertyMaintenancePlanRepository;
use App\Repository\RetentionOpportunityRepository;
use Doctrine\ORM\EntityManagerInterface;

final class NextBestActionEngineService
{
    public function __construct(
        private readonly PropertyHealthCalculatorInterface $healthCalculator,
        private readonly EquipmentRepository $equipmentRepository,
        private readonly EquipmentServiceRecordRepository $serviceRecordRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly CallSessionRepository $callSessionRepository,
        private readonly PropertyMaintenancePlanRepository $maintenancePlanRepository,
        private readonly RetentionOpportunityRepository $retentionOpportunityRepository,
        private readonly NextBestActionSuggestionRepository $suggestionRepository,
    ) {
    }

    /**
     * @return array{created:list<NextBestActionSuggestion>, updated:list<NextBestActionSuggestion>}
     */
    public function generateForProperty(Property $property, ?EntityManagerInterface $entityManager = null): array
    {
        $tenant = $property->getTenant();
        $health = $this->healthCalculator->calculate($property);
        $openOpportunities = $this->openOpportunityMap($tenant, $property);
        $activePlans = $this->maintenancePlanRepository->findByProperty($property);
        $equipmentFlags = $this->equipmentFlags($property);
        $invoices = $this->invoiceRepository->findByProperty($property);
        [$openInvoiceCount, $openInvoiceOutstanding] = $this->invoiceSummary($invoices);
        $latestServiceRecord = $this->latestServiceRecord($property);
        $latestCallAt = $this->callSessionRepository->findLatestUpdatedAtByProperty($property);
        $followUpOpportunities = array_filter([
            $openOpportunities[RetentionOpportunity::TYPE_DORMANT_CUSTOMER] ?? null,
            $openOpportunities[RetentionOpportunity::TYPE_NO_RECENT_CALLS] ?? null,
        ]);

        $created = [];
        $updated = [];

        if ($openInvoiceCount > 0) {
            $this->createIfMissing(
                $created,
                $entityManager,
                $property,
                NextBestActionSuggestion::TYPE_REVIEW_OVERDUE_INVOICE,
                'review_overdue_invoice',
                $this->buildOverdueInvoiceReason($openInvoiceCount, $openInvoiceOutstanding),
                $this->confidenceForInvoice($openInvoiceCount, $openInvoiceOutstanding, $openOpportunities),
                $openOpportunities[RetentionOpportunity::TYPE_OPEN_INVOICE] ?? null,
            );
        }

        if (0 === \count($activePlans)) {
            $this->createIfMissing(
                $created,
                $entityManager,
                $property,
                NextBestActionSuggestion::TYPE_OFFER_MAINTENANCE_PLAN,
                'offer_maintenance_plan',
                'No active maintenance plan is assigned to this property.',
                $this->confidenceFromHealth($health['category'], NextBestActionSuggestion::CONFIDENCE_MEDIUM, NextBestActionSuggestion::CONFIDENCE_HIGH),
                $openOpportunities[RetentionOpportunity::TYPE_MAINTENANCE_PLAN_MISSING] ?? null,
            );
        }

        if (null === $latestCallAt || $this->daysSince($latestCallAt) >= 180) {
            $this->createIfMissing(
                $created,
                $entityManager,
                $property,
                NextBestActionSuggestion::TYPE_CALL_CUSTOMER,
                'call_customer',
                null === $latestCallAt
                    ? 'There is no call activity recorded for this property.'
                    : sprintf('Last call activity was %d days ago.', $this->daysSince($latestCallAt)),
                null === $latestCallAt || $this->daysSince($latestCallAt) >= 365
                    ? NextBestActionSuggestion::CONFIDENCE_HIGH
                    : NextBestActionSuggestion::CONFIDENCE_MEDIUM,
                $openOpportunities[RetentionOpportunity::TYPE_NO_RECENT_CALLS] ?? $openOpportunities[RetentionOpportunity::TYPE_DORMANT_CUSTOMER] ?? null,
            );
        }

        if (null !== $latestServiceRecord) {
            $serviceAgeDays = $this->daysSince($this->serviceRecordedAt($latestServiceRecord));
            if ($serviceAgeDays >= 180) {
                $this->createIfMissing(
                    $created,
                    $entityManager,
                    $property,
                    NextBestActionSuggestion::TYPE_BOOK_MAINTENANCE,
                    'book_maintenance',
                    sprintf('The last completed service visit was %d days ago.', $serviceAgeDays),
                    $serviceAgeDays >= 365 ? NextBestActionSuggestion::CONFIDENCE_HIGH : NextBestActionSuggestion::CONFIDENCE_MEDIUM,
                    $openOpportunities[RetentionOpportunity::TYPE_NO_RECENT_SERVICE] ?? null,
                );
            }
        } else {
            $this->createIfMissing(
                $created,
                $entityManager,
                $property,
                NextBestActionSuggestion::TYPE_BOOK_MAINTENANCE,
                'book_maintenance',
                'No completed service visit is on file for this property.',
                NextBestActionSuggestion::CONFIDENCE_HIGH,
                $openOpportunities[RetentionOpportunity::TYPE_NO_RECENT_SERVICE] ?? null,
            );
        }

        if ([] !== $equipmentFlags) {
            $topFlag = $equipmentFlags[0];
            $this->createIfMissing(
                $created,
                $entityManager,
                $property,
                NextBestActionSuggestion::TYPE_REPLACE_EQUIPMENT,
                'replace_equipment',
                sprintf('%s: %s', $topFlag['displayName'], $topFlag['reason']),
                'high' === ($topFlag['severity'] ?? '') ? NextBestActionSuggestion::CONFIDENCE_HIGH : NextBestActionSuggestion::CONFIDENCE_MEDIUM,
                $openOpportunities[RetentionOpportunity::TYPE_OLD_EQUIPMENT] ?? $openOpportunities[RetentionOpportunity::TYPE_WARRANTY_NEARING_EXPIRATION] ?? null,
            );
        }

        if (in_array($health['category'], ['at_risk', 'dormant', 'lost'], true) || $this->hasUnresolvedHealthFactor($health)) {
            $this->createIfMissing(
                $created,
                $entityManager,
                $property,
                NextBestActionSuggestion::TYPE_INSPECT_SYSTEM,
                'inspect_system',
                $this->buildInspectReason($health),
                in_array($health['category'], ['dormant', 'lost'], true) ? NextBestActionSuggestion::CONFIDENCE_HIGH : NextBestActionSuggestion::CONFIDENCE_MEDIUM,
                $openOpportunities[RetentionOpportunity::TYPE_NO_RECENT_SERVICE] ?? null,
            );
        }

        if ([] !== $followUpOpportunities) {
            $opportunity = $followUpOpportunities[0];
            $this->createIfMissing(
                $created,
                $entityManager,
                $property,
                NextBestActionSuggestion::TYPE_SCHEDULE_FOLLOW_UP,
                'schedule_follow_up',
                sprintf('Open retention opportunity: %s', $opportunity->getDetectedReason()),
                NextBestActionSuggestion::CONFIDENCE_HIGH,
                $opportunity,
            );
        }

        return [
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * @return array<string, RetentionOpportunity>
     */
    private function openOpportunityMap(\App\Entity\Tenant $tenant, Property $property): array
    {
        $opportunities = [];
        foreach ($this->retentionOpportunityRepository->findOpenByTenantAndProperty($tenant, $property) as $opportunity) {
            $opportunities[$opportunity->getOpportunityType()] = $opportunity;
        }

        return $opportunities;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function equipmentFlags(Property $property): array
    {
        $flags = [];
        foreach ($this->equipmentRepository->findByProperty($property) as $equipment) {
            $flag = $this->evaluateEquipment($equipment);
            if (null !== $flag) {
                $flags[] = $flag;
            }
        }

        usort($flags, static function (array $left, array $right): int {
            return ['high' => 0, 'medium' => 1, 'low' => 2][$left['severity'] ?? 'medium'] <=> ['high' => 0, 'medium' => 1, 'low' => 2][$right['severity'] ?? 'medium'];
        });

        return $flags;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function evaluateEquipment(Equipment $equipment): ?array
    {
        if (in_array($equipment->getStatus(), [Equipment::STATUS_REPLACED, Equipment::STATUS_REMOVED], true)) {
            return null;
        }

        $ageYears = null !== $equipment->getInstalledAt()
            ? (int) $equipment->getInstalledAt()->diff(new \DateTimeImmutable('today'))->y
            : null;
        $reason = null;
        $severity = 'medium';

        if (null !== $equipment->getWarrantyExpiresAt() && $equipment->getWarrantyExpiresAt() < new \DateTimeImmutable('today')) {
            $reason = 'Warranty has expired.';
            $severity = 'medium';
        } elseif (null !== $equipment->getWarrantyExpiresAt() && $equipment->getWarrantyExpiresAt() <= new \DateTimeImmutable('+180 days')) {
            $reason = 'Warranty expires within 6 months.';
            $severity = 'medium';
        } elseif (null !== $ageYears && $ageYears >= 15) {
            $reason = sprintf('Installed %d years ago, which exceeds the 15-year replacement threshold.', $ageYears);
            $severity = 'high';
        }

        if (null === $reason) {
            return null;
        }

        return [
            'equipmentId' => $equipment->getId(),
            'displayName' => ucfirst(str_replace('_', ' ', $equipment->getEquipmentType())),
            'ageYears' => $ageYears,
            'severity' => $severity,
            'reason' => $reason,
        ];
    }

    /**
     * @param list<Invoice> $invoices
     */
    private function buildOverdueInvoiceReason(int $count, int $outstanding): string
    {
        return sprintf(
            '%d invoice(s) remain open with a total outstanding balance of $%s.',
            $count,
            number_format($outstanding / 100, 2),
        );
    }

    /**
     * @param list<Invoice> $invoices
     * @param array<string, RetentionOpportunity> $openOpportunities
     */
    private function confidenceForInvoice(int $count, int $outstanding, array $openOpportunities): string
    {
        if (isset($openOpportunities[RetentionOpportunity::TYPE_OPEN_INVOICE])) {
            return NextBestActionSuggestion::CONFIDENCE_HIGH;
        }

        return $outstanding >= 100000 ? NextBestActionSuggestion::CONFIDENCE_HIGH : NextBestActionSuggestion::CONFIDENCE_MEDIUM;
    }

    /**
     * @param list<Invoice> $invoices
     *
     * @return array{0:int,1:int}
     */
    private function invoiceSummary(array $invoices): array
    {
        $count = 0;
        $outstanding = 0;
        foreach ($invoices as $invoice) {
            $balance = $invoice->getBalanceCents();
            if ($balance <= 0) {
                continue;
            }

            ++$count;
            $outstanding += $balance;
        }

        return [$count, $outstanding];
    }

    private function confidenceFromHealth(string $category, string $defaultConfidence, string $highConfidence): string
    {
        return in_array($category, ['at_risk', 'dormant', 'lost'], true) ? $highConfidence : $defaultConfidence;
    }

    /**
     * @param array{score:int,category:string,factors:list<array{key:string,label:string,impact:int}>} $health
     */
    private function buildInspectReason(array $health): string
    {
        foreach ($health['factors'] as $factor) {
            if (str_contains(mb_strtolower((string) $factor['label']), 'unresolved issue')) {
                return 'Health scoring shows unresolved issues that should be inspected.';
            }
        }

        return sprintf('Property health score is %d and category is %s.', $health['score'], $health['category']);
    }

    /**
     * @param array{score:int,category:string,factors:list<array{key:string,label:string,impact:int}>} $health
     */
    private function hasUnresolvedHealthFactor(array $health): bool
    {
        foreach ($health['factors'] as $factor) {
            if (str_contains(mb_strtolower((string) $factor['label']), 'unresolved issue')) {
                return true;
            }
        }

        return false;
    }

    private function latestServiceRecord(Property $property): ?EquipmentServiceRecord
    {
        $records = $this->serviceRecordRepository->findByProperty($property, 1);

        return $records[0] ?? null;
    }

    private function serviceRecordedAt(EquipmentServiceRecord $record): \DateTimeImmutable
    {
        return $record->getCompletedAt() ?? $record->getArrivedAt() ?? $record->getCreatedAt();
    }

    private function daysSince(\DateTimeImmutable $dateTime): int
    {
        return (int) $dateTime->diff(new \DateTimeImmutable('today'))->days;
    }

    /**
     * @return list<NextBestActionSuggestion>
     */
    private function createIfMissing(
        array &$created,
        ?EntityManagerInterface $entityManager,
        Property $property,
        string $type,
        string $sourceKeySuffix,
        string $reason,
        string $confidence,
        ?RetentionOpportunity $opportunity,
    ): void {
        $sourceKey = sprintf('property:%d:%s', (int) $property->getId(), $sourceKeySuffix);
        $existing = $this->suggestionRepository->findOneByTenantPropertyTypeAndSourceKey($property->getTenant(), $property, $type, $sourceKey);
        if (null !== $existing) {
            return;
        }

        $suggestion = new NextBestActionSuggestion(
            $property->getTenant(),
            $property,
            $type,
            $sourceKey,
            $reason,
            $confidence,
            $opportunity,
        );

        if (null !== $entityManager) {
            $entityManager->persist($suggestion);
        }

        $created[] = $suggestion;
    }
}
