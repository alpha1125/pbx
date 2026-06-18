<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Equipment;
use App\Entity\EquipmentServiceRecord;
use App\Entity\Estimate;
use App\Entity\Invoice;
use App\Entity\Job;
use App\Entity\Property;
use App\Entity\Quote;
use App\Entity\Rfq;
use App\Entity\RetentionOpportunity;
use App\Repository\EquipmentRepository;
use App\Repository\EquipmentServiceRecordRepository;
use App\Repository\EstimateRepository;
use App\Repository\InvoiceRepository;
use App\Repository\JobRepository;
use App\Repository\PropertyMaintenancePlanRepository;
use App\Repository\QuoteRepository;
use App\Repository\RfqRepository;
use App\Repository\RetentionOpportunityRepository;

final class CustomerJourneyDashboardService
{
    public function __construct(
        private readonly RfqRepository $rfqRepository,
        private readonly EstimateRepository $estimateRepository,
        private readonly QuoteRepository $quoteRepository,
        private readonly JobRepository $jobRepository,
        private readonly EquipmentRepository $equipmentRepository,
        private readonly EquipmentServiceRecordRepository $serviceRecordRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PropertyMaintenancePlanRepository $maintenancePlanRepository,
        private readonly RetentionOpportunityRepository $retentionOpportunityRepository,
    ) {
    }

    /**
     * @return array{
     *   stages:list<array<string, mixed>>,
     *   currentStage:?string,
     *   completedCount:int,
     *   upcomingCount:int,
     *   currentLabel:?string
     * }
     */
    public function buildForProperty(Property $property): array
    {
        $rfq = $this->latestRfq($property);
        $estimate = $this->latestEstimate($property);
        $quote = $this->latestQuote($property);
        $install = $this->latestInstall($property);
        $invoice = $this->latestInvoice($property);
        $service = $this->latestService($property);
        $maintenance = $this->latestMaintenance($property);
        $renewal = $this->latestRenewal($property, $maintenance);
        $replacement = $this->latestReplacement($property);

        $stages = [
            $this->stage(
                'rfq',
                'RFQ',
                'Request for quote received.',
                null !== $rfq,
                null !== $rfq ? $this->routeStage('crm_rfq_show', ['id' => $rfq->getId() ?? 0]) : null,
                null !== $rfq ? sprintf('RFQ #%d', $rfq->getId() ?? 0) : 'No RFQ recorded yet.',
                null !== $rfq ? $rfq->getCreatedAt() : null,
            ),
            $this->stage(
                'estimate',
                'Estimate',
                'Estimate created for this property.',
                null !== $estimate,
                null !== $estimate ? $this->routeStage('crm_estimate_show', ['id' => $estimate->getId() ?? 0]) : null,
                null !== $estimate ? sprintf('Estimate #%d', $estimate->getId() ?? 0) : 'No estimate recorded yet.',
                null !== $estimate ? $estimate->getUpdatedAt() : null,
            ),
            $this->stage(
                'quote',
                'Quote',
                'Quote issued to the customer.',
                null !== $quote,
                null !== $quote ? $this->routeStage('crm_quote_show', ['id' => $quote->getId() ?? 0]) : null,
                null !== $quote ? sprintf('Quote %s', $quote->getQuoteNumber()) : 'No quote issued yet.',
                null !== $quote ? $this->quoteOccurredAt($quote) : null,
            ),
            $this->stage(
                'install',
                'Install',
                'Installation work or equipment placement recorded.',
                null !== $install,
                null !== $install ? $install['link'] : null,
                null !== $install ? $install['label'] : 'No installation record yet.',
                null !== $install ? $install['occurredAt'] : null,
            ),
            $this->stage(
                'invoice',
                'Invoice',
                'Billing has been issued.',
                null !== $invoice,
                null !== $invoice ? $this->routeStage('crm_invoice_show', ['id' => $invoice->getId() ?? 0]) : null,
                null !== $invoice ? sprintf('Invoice %s', $invoice->getInvoiceNumber()) : 'No invoice recorded yet.',
                null !== $invoice ? $this->invoiceOccurredAt($invoice) : null,
            ),
            $this->stage(
                'service',
                'Service',
                'Service visit or job follow-up completed.',
                null !== $service,
                null !== $service ? $service['link'] : $this->propertyAnchor('service-history-card'),
                null !== $service ? $service['label'] : 'No service visit recorded yet.',
                null !== $service ? $service['occurredAt'] : null,
            ),
            $this->stage(
                'maintenance',
                'Maintenance',
                'Active maintenance plan in place.',
                null !== $maintenance,
                null !== $maintenance ? $this->propertyAnchor('assigned-maintenance-plans-card') : null,
                null !== $maintenance ? $maintenance['label'] : 'No maintenance plan assigned yet.',
                null !== $maintenance ? $maintenance['occurredAt'] : null,
            ),
            $this->stage(
                'renewal',
                'Renewal',
                'Plan renewal date or renewal-ready state recorded.',
                null !== $renewal,
                null !== $renewal ? $this->propertyAnchor('assigned-maintenance-plans-card') : null,
                null !== $renewal ? $renewal['label'] : 'No renewal record yet.',
                null !== $renewal ? $renewal['occurredAt'] : null,
            ),
            $this->stage(
                'replacement',
                'Replacement',
                'Replacement risk or replacement discussion recorded.',
                null !== $replacement,
                null !== $replacement ? $replacement['link'] : $this->propertyAnchor('retention-opportunities-card'),
                null !== $replacement ? $replacement['label'] : 'No replacement signal yet.',
                null !== $replacement ? $replacement['occurredAt'] : null,
            ),
        ];

        $currentIndex = null;
        foreach ($stages as $index => $stage) {
            if ($stage['completed']) {
                $currentIndex = $index;
            }
        }

        if (null !== $currentIndex) {
            foreach ($stages as $index => &$stage) {
                if ($index < $currentIndex) {
                    $stage['status'] = 'complete';
                } elseif ($index === $currentIndex) {
                    $stage['status'] = 'current';
                } else {
                    $stage['status'] = 'upcoming';
                }
            }
            unset($stage);
        } else {
            foreach ($stages as &$stage) {
                $stage['status'] = 'upcoming';
            }
            unset($stage);
        }

        $currentStage = null !== $currentIndex ? $stages[$currentIndex]['key'] : null;

        return [
            'stages' => $stages,
            'currentStage' => $currentStage,
            'currentLabel' => null !== $currentIndex ? $stages[$currentIndex]['label'] : null,
            'completedCount' => \count(array_filter($stages, static fn (array $stage): bool => 'complete' === $stage['status'])),
            'upcomingCount' => \count(array_filter($stages, static fn (array $stage): bool => 'upcoming' === $stage['status'])),
        ];
    }

    /**
     * @return array{
     *   key:string,
     *   label:string,
     *   description:string,
     *   completed:bool,
     *   link:array{route:string,params:array<string, int|string>}|array{fragment:string}|null,
     *   summary:string,
     *   occurredAt:?\\DateTimeImmutable,
     *   status?:string
     * }
     */
    private function stage(string $key, string $label, string $description, bool $completed, ?array $link, string $summary, ?\DateTimeImmutable $occurredAt): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'completed' => $completed,
            'link' => $link,
            'summary' => $summary,
            'occurredAt' => $occurredAt,
        ];
    }

    /**
     * @return array{route:string,params:array<string, int|string>}
     */
    private function routeStage(string $route, array $params): array
    {
        return [
            'route' => $route,
            'params' => $params,
        ];
    }

    /**
     * @return array{fragment:string}
     */
    private function propertyAnchor(string $fragment): array
    {
        return ['fragment' => $fragment];
    }

    private function latestRfq(Property $property): ?Rfq
    {
        $rfqs = $this->rfqRepository->findByPropertyAddress($property, 1);

        return $rfqs[0] ?? null;
    }

    private function latestEstimate(Property $property): ?Estimate
    {
        $estimates = $this->estimateRepository->findByProperty($property);

        return $estimates[0] ?? null;
    }

    private function latestQuote(Property $property): ?Quote
    {
        $quotes = $this->quoteRepository->findByProperty($property);

        return $quotes[0] ?? null;
    }

    /**
     * @return array{label:string,occurredAt:?\\DateTimeImmutable,link:array{route:string,params:array<string, int|string>}|array{fragment:string}}|null
     */
    private function latestInstall(Property $property): ?array
    {
        $jobs = $this->jobRepository->findByProperty($property, 20);
        if ([] !== $jobs) {
            $job = $jobs[0];
            if (null !== $job->getCompletedAt()) {
                return [
                    'label' => sprintf('Job #%d', $job->getId() ?? 0),
                    'occurredAt' => $job->getCompletedAt(),
                    'link' => $this->routeStage('crm_job_show', ['id' => $job->getId() ?? 0]),
                ];
            }
        }

        $equipment = $this->equipmentRepository->findByProperty($property);
        if ([] === $equipment) {
            return null;
        }

        $latest = null;
        foreach ($equipment as $item) {
            if (null === $item->getInstalledAt()) {
                continue;
            }
            if (null === $latest || $item->getInstalledAt() > $latest->getInstalledAt()) {
                $latest = $item;
            }
        }

        if (null === $latest) {
            return null;
        }

        return [
            'label' => sprintf('%s installed', ucfirst(str_replace('_', ' ', $latest->getEquipmentType()))),
            'occurredAt' => $latest->getInstalledAt(),
            'link' => $this->routeStage('crm_equipment_edit', ['propertyId' => $property->getId() ?? 0, 'equipmentId' => $latest->getId() ?? 0]),
        ];
    }

    private function latestInvoice(Property $property): ?Invoice
    {
        $invoices = $this->invoiceRepository->findByProperty($property);

        return $invoices[0] ?? null;
    }

    /**
     * @return array{label:string,occurredAt:?\\DateTimeImmutable,link:array{route:string,params:array<string, int|string>}|array{fragment:string}}|null
     */
    private function latestService(Property $property): ?array
    {
        $records = $this->serviceRecordRepository->findByProperty($property, 20);
        if ([] === $records) {
            return null;
        }

        $record = $records[0];
        return [
            'label' => $this->serviceLabel($record),
            'occurredAt' => $record->getCompletedAt() ?? $record->getCreatedAt(),
            'link' => null !== $record->getJob()
                ? $this->routeStage('crm_job_show', ['id' => $record->getJob()->getId() ?? 0])
                : $this->propertyAnchor('service-history-card'),
        ];
    }

    /**
     * @return array{label:string,occurredAt:?\\DateTimeImmutable,link:array{route:string,params:array<string, int|string>}|array{fragment:string}}|null
     */
    private function latestMaintenance(Property $property): ?array
    {
        $assignments = $this->maintenancePlanRepository->findByProperty($property);
        if ([] === $assignments) {
            return null;
        }

        $assignment = $assignments[0];
        return [
            'label' => $assignment->getMaintenancePlan()->getName(),
            'occurredAt' => $assignment->getCreatedAt(),
            'link' => $this->propertyAnchor('assigned-maintenance-plans-card'),
        ];
    }

    /**
     * @return array{label:string,occurredAt:?\\DateTimeImmutable,link:array{route:string,params:array<string, int|string>}|array{fragment:string}}|null
     */
    private function latestRenewal(Property $property, ?array $maintenance): ?array
    {
        if (null === $maintenance) {
            return null;
        }

        $assignments = $this->maintenancePlanRepository->findByProperty($property);
        $assignment = $assignments[0] ?? null;
        if (null === $assignment) {
            return null;
        }

        $renewalDate = $assignment->getMaintenancePlan()->getRenewalDate();
        if (null === $renewalDate) {
            return null;
        }

        return [
            'label' => sprintf('Renewal due %s', $renewalDate->format('Y-m-d')),
            'occurredAt' => $renewalDate,
            'link' => $this->propertyAnchor('assigned-maintenance-plans-card'),
        ];
    }

    /**
     * @return array{label:string,occurredAt:?\\DateTimeImmutable,link:array{route:string,params:array<string, int|string>}|array{fragment:string}}|null
     */
    private function latestReplacement(Property $property): ?array
    {
        $opportunities = $this->retentionOpportunityRepository->findByTenantAndProperty($property->getTenant(), $property);
        foreach ($opportunities as $opportunity) {
            if (RetentionOpportunity::TYPE_OLD_EQUIPMENT === $opportunity->getOpportunityType()) {
                return [
                    'label' => $opportunity->getDetectedReason(),
                    'occurredAt' => $opportunity->getDetectedAt(),
                    'link' => $this->propertyAnchor('retention-opportunities-card'),
                ];
            }
        }

        $equipment = $this->equipmentRepository->findByProperty($property);
        if ([] === $equipment) {
            return null;
        }

        $latest = $equipment[0];
        return [
            'label' => sprintf('%s ready for review', ucfirst(str_replace('_', ' ', $latest->getEquipmentType()))),
            'occurredAt' => $latest->getUpdatedAt(),
            'link' => [
                'route' => 'crm_equipment_edit',
                'params' => ['propertyId' => $property->getId() ?? 0, 'equipmentId' => $latest->getId() ?? 0],
            ],
        ];
    }

    private function quoteOccurredAt(Quote $quote): \DateTimeImmutable
    {
        return $quote->getAcceptedAt()
            ?? $quote->getDeclinedAt()
            ?? $quote->getViewedAt()
            ?? $quote->getSentAt()
            ?? $quote->getUpdatedAt();
    }

    private function invoiceOccurredAt(Invoice $invoice): ?\DateTimeImmutable
    {
        return $invoice->getIssuedAt() ?? $invoice->getSentAt() ?? $invoice->getUpdatedAt();
    }

    private function serviceLabel(EquipmentServiceRecord $record): string
    {
        $parts = [];
        if (null !== $record->getServiceType()) {
            $parts[] = ucfirst(str_replace('_', ' ', $record->getServiceType()));
        }
        if (null !== $record->getJob()) {
            $parts[] = sprintf('Job #%d', $record->getJob()->getId() ?? 0);
        }
        if (null !== $record->getEquipment()) {
            $parts[] = ucfirst(str_replace('_', ' ', $record->getEquipment()->getEquipmentType()));
        }

        return [] !== $parts ? implode(' · ', $parts) : 'Service visit';
    }
}
