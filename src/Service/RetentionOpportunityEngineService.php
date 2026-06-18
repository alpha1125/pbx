<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\Equipment;
use App\Entity\Invoice;
use App\Entity\Property;
use App\Entity\RetentionOpportunity;
use App\Repository\CallSessionRepository;
use App\Repository\EquipmentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\JobRepository;
use App\Repository\PropertyContactRepository;
use App\Repository\PropertyMaintenancePlanRepository;
use App\Repository\RetentionOpportunityRepository;

final class RetentionOpportunityEngineService
{
    private const MAX_DAYS_SINCE_LAST_SERVICE = 365;
    private const MAX_DAYS_SINCE_LAST_CALL = 180;
    private const OLD_EQUIPMENT_AGE_DAYS = 3650;
    private const WARRANTY_WARNING_DAYS = 90;

    public function __construct(
        private readonly PropertyContactRepository $propertyContactRepository,
        private readonly EquipmentRepository $equipmentRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly JobRepository $jobRepository,
        private readonly CallSessionRepository $callSessionRepository,
        private readonly PropertyMaintenancePlanRepository $maintenancePlanRepository,
        private readonly PropertyHealthCalculatorInterface $healthCalculator,
        private readonly RetentionOpportunityRepository $retentionOpportunityRepository,
    ) {
    }

    /**
     * @return array{created:list<RetentionOpportunity>, updated:list<RetentionOpportunity>}
     */
    public function generateForProperty(Property $property): array
    {
        $tenant = $property->getTenant();
        $detectedAt = new \DateTimeImmutable('now');
        $primaryContact = $this->findPrimaryContact($property);
        $equipmentList = $this->equipmentRepository->findByProperty($property);
        $created = [];
        $updated = [];

        foreach ($this->buildPropertyCandidates($property, $primaryContact, $equipmentList) as $candidate) {
            $existing = $this->retentionOpportunityRepository->findOpenByTenantPropertyTypeAndSourceKey(
                $tenant,
                $property,
                $candidate['type'],
                $candidate['sourceKey'],
            );

            if ($existing instanceof RetentionOpportunity) {
                $existing
                    ->setDetectedReason($candidate['reason'])
                    ->setDetectedAt($detectedAt)
                    ->setContact($candidate['contact'])
                    ->setEquipment($candidate['equipment']);
                $updated[] = $existing;

                continue;
            }

            $created[] = new RetentionOpportunity(
                $tenant,
                $property,
                $candidate['type'],
                $candidate['sourceKey'],
                $candidate['reason'],
                $candidate['contact'],
                $candidate['equipment'],
                $detectedAt,
            );
        }

        return [
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * @return list<array{type:string,sourceKey:string,reason:string,contact:?Contact,equipment:?Equipment}>
     */
    private function buildPropertyCandidates(Property $property, ?Contact $primaryContact, array $equipmentList): array
    {
        $candidates = [];

        $lastCompletedAt = $this->jobRepository->findLatestCompletedAtByProperty($property);
        if (null === $lastCompletedAt) {
            $candidates[] = [
                'type' => RetentionOpportunity::TYPE_NO_RECENT_SERVICE,
                'sourceKey' => $this->propertySourceKey($property, RetentionOpportunity::TYPE_NO_RECENT_SERVICE),
                'reason' => 'No completed jobs on record for this property.',
                'contact' => $primaryContact,
                'equipment' => null,
            ];
        } else {
            $daysSinceLastService = (int) $lastCompletedAt->diff(new \DateTimeImmutable('today'))->format('%a');
            if ($daysSinceLastService > self::MAX_DAYS_SINCE_LAST_SERVICE) {
                $candidates[] = [
                    'type' => RetentionOpportunity::TYPE_NO_RECENT_SERVICE,
                    'sourceKey' => $this->propertySourceKey($property, RetentionOpportunity::TYPE_NO_RECENT_SERVICE),
                    'reason' => sprintf('Last completed job was %d days ago.', $daysSinceLastService),
                    'contact' => $primaryContact,
                    'equipment' => null,
                ];
            }
        }

        $lastCallAt = $this->callSessionRepository->findLatestUpdatedAtByProperty($property);
        if (null === $lastCallAt) {
            $candidates[] = [
                'type' => RetentionOpportunity::TYPE_NO_RECENT_CALLS,
                'sourceKey' => $this->propertySourceKey($property, RetentionOpportunity::TYPE_NO_RECENT_CALLS),
                'reason' => 'No calls on record for this property.',
                'contact' => $primaryContact,
                'equipment' => null,
            ];
        } else {
            $daysSinceLastCall = (int) $lastCallAt->diff(new \DateTimeImmutable('today'))->format('%a');
            if ($daysSinceLastCall > self::MAX_DAYS_SINCE_LAST_CALL) {
                $candidates[] = [
                    'type' => RetentionOpportunity::TYPE_NO_RECENT_CALLS,
                    'sourceKey' => $this->propertySourceKey($property, RetentionOpportunity::TYPE_NO_RECENT_CALLS),
                    'reason' => sprintf('Last call was %d days ago.', $daysSinceLastCall),
                    'contact' => $primaryContact,
                    'equipment' => null,
                ];
            }
        }

        foreach ($equipmentList as $equipment) {
            if (!$equipment instanceof Equipment) {
                continue;
            }

            if (null !== $equipment->getInstalledAt()) {
                $ageDays = (int) $equipment->getInstalledAt()->diff(new \DateTimeImmutable('today'))->format('%a');
                if ($ageDays >= self::OLD_EQUIPMENT_AGE_DAYS) {
                    $candidates[] = [
                        'type' => RetentionOpportunity::TYPE_OLD_EQUIPMENT,
                        'sourceKey' => $this->equipmentSourceKey($property, $equipment, RetentionOpportunity::TYPE_OLD_EQUIPMENT),
                        'reason' => sprintf('Equipment is approximately %d years old.', (int) floor($ageDays / 365)),
                        'contact' => $primaryContact,
                        'equipment' => $equipment,
                    ];
                }
            }

            if (null !== $equipment->getWarrantyExpiresAt()) {
                $daysUntilExpiry = (int) $equipment->getWarrantyExpiresAt()->diff(new \DateTimeImmutable('today'))->format('%r%a');
                if ($daysUntilExpiry <= self::WARRANTY_WARNING_DAYS) {
                    $candidates[] = [
                        'type' => RetentionOpportunity::TYPE_WARRANTY_NEARING_EXPIRATION,
                        'sourceKey' => $this->equipmentSourceKey($property, $equipment, RetentionOpportunity::TYPE_WARRANTY_NEARING_EXPIRATION),
                        'reason' => $daysUntilExpiry < 0
                            ? sprintf('Warranty expired %d days ago.', abs($daysUntilExpiry))
                            : sprintf('Warranty expires in %d days.', $daysUntilExpiry),
                        'contact' => $primaryContact,
                        'equipment' => $equipment,
                    ];
                }
            }
        }

        $health = $this->healthCalculator->calculate($property);
        if (in_array($health['category'], [CustomerHealthCalculatorService::CATEGORY_DORMANT, CustomerHealthCalculatorService::CATEGORY_LOST], true)) {
            $candidates[] = [
                'type' => RetentionOpportunity::TYPE_DORMANT_CUSTOMER,
                'sourceKey' => $this->propertySourceKey($property, RetentionOpportunity::TYPE_DORMANT_CUSTOMER),
                'reason' => sprintf('Health score is %d and category is %s.', (int) $health['score'], (string) $health['category']),
                'contact' => $primaryContact,
                'equipment' => null,
            ];
        }

        $openBalanceCents = 0;
        $openInvoiceCount = 0;
        foreach ($this->invoiceRepository->findByProperty($property) as $invoice) {
            if (!$invoice instanceof Invoice) {
                continue;
            }

            $balance = $invoice->getBalanceCents();
            if ($balance <= 0) {
                continue;
            }

            $openBalanceCents += $balance;
            ++$openInvoiceCount;
        }

        if ($openInvoiceCount > 0) {
            $candidates[] = [
                'type' => RetentionOpportunity::TYPE_OPEN_INVOICE,
                'sourceKey' => $this->propertySourceKey($property, RetentionOpportunity::TYPE_OPEN_INVOICE),
                'reason' => sprintf('%d open invoice(s), roughly $%d outstanding.', $openInvoiceCount, (int) round($openBalanceCents / 100)),
                'contact' => $primaryContact,
                'equipment' => null,
            ];
        }

        if ([] === $this->maintenancePlanRepository->findByProperty($property)) {
            $candidates[] = [
                'type' => RetentionOpportunity::TYPE_MAINTENANCE_PLAN_MISSING,
                'sourceKey' => $this->propertySourceKey($property, RetentionOpportunity::TYPE_MAINTENANCE_PLAN_MISSING),
                'reason' => 'No active maintenance plan is assigned to this property.',
                'contact' => $primaryContact,
                'equipment' => null,
            ];
        }

        return $this->uniqueCandidates($candidates);
    }

    private function findPrimaryContact(Property $property): ?Contact
    {
        $primaryLink = $this->propertyContactRepository->findPrimaryByProperty($property);

        return null !== $primaryLink ? $primaryLink->getContact() : null;
    }

    /**
     * @param list<array{type:string,sourceKey:string,reason:string,contact:?Contact,equipment:?Equipment}> $candidates
     *
     * @return list<array{type:string,sourceKey:string,reason:string,contact:?Contact,equipment:?Equipment}>
     */
    private function uniqueCandidates(array $candidates): array
    {
        $unique = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $key = $candidate['type'].'|'.$candidate['sourceKey'];
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    private function propertySourceKey(Property $property, string $opportunityType): string
    {
        return sprintf('property:%d:%s', (int) ($property->getId() ?? 0), $opportunityType);
    }

    private function equipmentSourceKey(Property $property, Equipment $equipment, string $opportunityType): string
    {
        return sprintf('property:%d:equipment:%d:%s', (int) ($property->getId() ?? 0), (int) ($equipment->getId() ?? 0), $opportunityType);
    }
}
