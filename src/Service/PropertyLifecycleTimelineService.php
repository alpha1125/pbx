<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CustomerSentimentHistory;
use App\Entity\Equipment;
use App\Entity\EquipmentServiceRecord;
use App\Entity\Estimate;
use App\Entity\Invoice;
use App\Entity\Job;
use App\Entity\Property;
use App\Entity\PropertyMaintenancePlan;
use App\Entity\Quote;
use App\Entity\Rfq;
use App\Entity\RetentionOpportunity;
use App\Repository\CallSessionRepository;
use App\Repository\CustomerSentimentHistoryRepository;
use App\Repository\EquipmentRepository;
use App\Repository\EquipmentServiceRecordRepository;
use App\Repository\EstimateRepository;
use App\Repository\InvoiceRepository;
use App\Repository\JobRepository;
use App\Repository\PropertyMaintenancePlanRepository;
use App\Repository\QuoteRepository;
use App\Repository\RfqRepository;
use App\Repository\RetentionOpportunityRepository;

final class PropertyLifecycleTimelineService
{
    /**
     * @var array<string, string>
     */
    private const TYPE_LABELS = [
        'rfq' => 'RFQ',
        'estimate' => 'Estimate',
        'quote' => 'Quote',
        'job' => 'Job',
        'equipment_install' => 'Equipment Install',
        'service_visit' => 'Service Visit',
        'invoice' => 'Invoice',
        'call' => 'Call',
        'maintenance_plan' => 'Maintenance Plan',
        'retention_opportunity' => 'Retention Opportunity',
        'sentiment' => 'Sentiment',
    ];

    public function __construct(
        private readonly RfqRepository $rfqRepository,
        private readonly EstimateRepository $estimateRepository,
        private readonly QuoteRepository $quoteRepository,
        private readonly JobRepository $jobRepository,
        private readonly EquipmentRepository $equipmentRepository,
        private readonly EquipmentServiceRecordRepository $serviceRecordRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly CallSessionRepository $callSessionRepository,
        private readonly PropertyMaintenancePlanRepository $maintenancePlanRepository,
        private readonly RetentionOpportunityRepository $retentionOpportunityRepository,
        private readonly CustomerSentimentHistoryRepository $sentimentHistoryRepository,
    ) {
    }

    /**
     * @return array{
     *   items: list<array{
     *     type:string,
     *     typeLabel:string,
     *     title:string,
     *     detail:string,
     *     occurredAt:\DateTimeImmutable,
     *     route:?string,
     *     routeParams: array<string, int|string>,
     *     badgeClass:string,
     *     searchText:string
     *   }>,
     *   typeOptions: array<string, string>,
     *   activeType: string,
     *   search: string
     * }
     */
    public function buildForProperty(Property $property, string $type = 'all', string $search = '', int $limit = 100): array
    {
        $items = [];
        $tenant = $property->getTenant();

        foreach ($this->rfqRepository->findByPropertyAddress($property, 10) as $rfq) {
            $this->addItem($items, $property, 'rfq', $this->rfqTitle($rfq), $this->rfqDetail($rfq), $rfq->getCreatedAt(), 'crm_rfq_show', ['id' => $rfq->getId()]);
        }

        foreach ($this->estimateRepository->findByProperty($property) as $estimate) {
            $this->addItem($items, $property, 'estimate', $this->estimateTitle($estimate), $this->estimateDetail($estimate), $estimate->getUpdatedAt(), 'crm_estimate_show', ['id' => $estimate->getId()]);
        }

        foreach ($this->quoteRepository->findByProperty($property) as $quote) {
            $this->addItem($items, $property, 'quote', $this->quoteTitle($quote), $this->quoteDetail($quote), $this->quoteOccurredAt($quote), 'crm_quote_show', ['id' => $quote->getId()]);
        }

        foreach ($this->jobRepository->findByProperty($property, 20) as $job) {
            $this->addItem($items, $property, 'job', $this->jobTitle($job), $this->jobDetail($job), $this->jobOccurredAt($job), 'crm_job_show', ['id' => $job->getId()]);
        }

        foreach ($this->equipmentRepository->findByProperty($property) as $equipment) {
            $this->addItem($items, $property, 'equipment_install', $this->equipmentTitle($equipment), $this->equipmentDetail($equipment), $this->equipmentOccurredAt($equipment), 'crm_equipment_edit', ['propertyId' => $property->getId() ?? 0, 'equipmentId' => $equipment->getId() ?? 0]);
        }

        foreach ($this->serviceRecordRepository->findByProperty($property, 20) as $record) {
            $this->addItem($items, $property, 'service_visit', $this->serviceVisitTitle($record), $this->serviceVisitDetail($record), $this->serviceVisitOccurredAt($record), null, []);
        }

        foreach ($this->invoiceRepository->findByProperty($property) as $invoice) {
            $this->addItem($items, $property, 'invoice', $this->invoiceTitle($invoice), $this->invoiceDetail($invoice), $this->invoiceOccurredAt($invoice), 'crm_invoice_show', ['id' => $invoice->getId()]);
        }

        foreach ($this->callSessionRepository->findByTenantAndProperty($tenant, $property) as $callSession) {
            $this->addItem($items, $property, 'call', $this->callTitle($callSession), $this->callDetail($callSession), $this->callOccurredAt($callSession), null, []);
        }

        foreach ($this->maintenancePlanRepository->findHistoryByProperty($property) as $assignment) {
            $this->addItem($items, $property, 'maintenance_plan', $this->maintenancePlanTitle($assignment), $this->maintenancePlanDetail($assignment), $this->maintenancePlanOccurredAt($assignment), 'crm_maintenance_plan_edit', ['id' => $assignment->getMaintenancePlan()->getId() ?? 0]);
        }

        foreach ($this->retentionOpportunityRepository->findByTenantAndProperty($tenant, $property) as $opportunity) {
            $this->addItem($items, $property, 'retention_opportunity', $this->retentionOpportunityTitle($opportunity), $this->retentionOpportunityDetail($opportunity), $opportunity->getDetectedAt(), null, []);
        }

        foreach ($this->sentimentHistoryRepository->findByTenantAndProperty($tenant, $property, 20) as $sentiment) {
            $this->addItem($items, $property, 'sentiment', $this->sentimentTitle($sentiment), $this->sentimentDetail($sentiment), $sentiment->getRecordedAt(), null, []);
        }

        $items = array_values(array_filter($items, static fn (array $item): bool => 'all' === $type || $item['type'] === $type));
        if ('' !== trim($search)) {
            $term = mb_strtolower(trim($search));
            $items = array_values(array_filter($items, static fn (array $item): bool => str_contains(mb_strtolower($item['searchText']), $term)));
        }

        usort($items, static function (array $left, array $right): int {
            $leftOccurredAt = $left['occurredAt']->getTimestamp();
            $rightOccurredAt = $right['occurredAt']->getTimestamp();
            if ($leftOccurredAt === $rightOccurredAt) {
                return strcmp($right['title'], $left['title']);
            }

            return $rightOccurredAt <=> $leftOccurredAt;
        });

        return [
            'items' => array_slice($items, 0, $limit),
            'typeOptions' => $this->typeOptions(),
            'activeType' => $type,
            'search' => $search,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function typeOptions(): array
    {
        return ['all' => 'All types'] + self::TYPE_LABELS;
    }

    private function addItem(array &$items, Property $property, string $type, string $title, string $detail, \DateTimeImmutable $occurredAt, ?string $route, array $routeParams): void
    {
        $items[] = [
            'type' => $type,
            'typeLabel' => self::TYPE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type)),
            'title' => $title,
            'detail' => $detail,
            'occurredAt' => $occurredAt,
            'route' => $route,
            'routeParams' => $routeParams,
            'badgeClass' => $this->badgeClass($type),
            'searchText' => implode(' ', [$property->getDisplayAddress(), $title, $detail, self::TYPE_LABELS[$type] ?? $type]),
        ];
    }

    private function badgeClass(string $type): string
    {
        return match ($type) {
            'rfq' => 'text-bg-secondary',
            'estimate' => 'text-bg-info',
            'quote' => 'text-bg-primary',
            'job' => 'text-bg-success',
            'equipment_install' => 'text-bg-dark',
            'service_visit' => 'text-bg-warning',
            'invoice' => 'text-bg-danger',
            'call' => 'text-bg-secondary',
            'maintenance_plan' => 'text-bg-success',
            'retention_opportunity' => 'text-bg-warning',
            'sentiment' => 'text-bg-secondary',
            default => 'text-bg-secondary',
        };
    }

    private function rfqTitle(Rfq $rfq): string
    {
        return 'RFQ'.(null !== $rfq->getId() ? ' #'.$rfq->getId() : '');
    }

    private function rfqDetail(Rfq $rfq): string
    {
        $parts = [];
        if (null !== $rfq->getCustomerName()) {
            $parts[] = $rfq->getCustomerName();
        }
        if (null !== $rfq->getProjectType()) {
            $parts[] = $rfq->getProjectType();
        }

        $parts[] = trim(sprintf('%s, %s %s', $rfq->getCity(), $rfq->getProvince(), $rfq->getPostalCode()));

        return implode(' · ', array_filter($parts));
    }

    private function estimateTitle(Estimate $estimate): string
    {
        return 'Estimate'.(null !== $estimate->getId() ? ' #'.$estimate->getId() : '');
    }

    private function estimateDetail(Estimate $estimate): string
    {
        $parts = [$estimate->getStatus()];
        if (null !== $estimate->getContact()) {
            $parts[] = $estimate->getContact()->getDisplayName();
        }

        return implode(' · ', array_filter($parts));
    }

    private function quoteTitle(Quote $quote): string
    {
        return 'Quote '.$quote->getQuoteNumber();
    }

    private function quoteDetail(Quote $quote): string
    {
        $parts = [$quote->getStatus()];
        if (null !== $quote->getContact()) {
            $parts[] = $quote->getContact()->getDisplayName();
        }

        return implode(' · ', array_filter($parts));
    }

    private function quoteOccurredAt(Quote $quote): \DateTimeImmutable
    {
        return $quote->getAcceptedAt()
            ?? $quote->getDeclinedAt()
            ?? $quote->getViewedAt()
            ?? $quote->getSentAt()
            ?? $quote->getUpdatedAt();
    }

    private function jobTitle(Job $job): string
    {
        return null !== $job->getTitle() ? $job->getTitle() : ('Job #'.($job->getId() ?? 0));
    }

    private function jobDetail(Job $job): string
    {
        $parts = [$job->getStatusLabel()];
        if (null !== $job->getEquipment()) {
            $parts[] = $job->getEquipment()->getEquipmentType();
        }

        return implode(' · ', array_filter($parts));
    }

    private function jobOccurredAt(Job $job): \DateTimeImmutable
    {
        return $job->getCompletedAt()
            ?? $job->getStartedAt()
            ?? $job->getScheduledStartAt()
            ?? $job->getUpdatedAt();
    }

    private function equipmentTitle(Equipment $equipment): string
    {
        return sprintf('%s installed', ucfirst(str_replace('_', ' ', $equipment->getEquipmentType())));
    }

    private function equipmentDetail(Equipment $equipment): string
    {
        $parts = [];
        if (null !== $equipment->getBrand()) {
            $parts[] = $equipment->getBrand();
        }
        if (null !== $equipment->getModelNumber()) {
            $parts[] = $equipment->getModelNumber();
        }

        return implode(' · ', array_filter($parts));
    }

    private function equipmentOccurredAt(Equipment $equipment): \DateTimeImmutable
    {
        return $equipment->getInstalledAt() ?? $equipment->getCreatedAt();
    }

    private function serviceVisitTitle(EquipmentServiceRecord $record): string
    {
        return null !== $record->getServiceType() ? $record->getServiceType() : 'Service visit';
    }

    private function serviceVisitDetail(EquipmentServiceRecord $record): string
    {
        $parts = [];
        if (null !== $record->getEquipment()) {
            $parts[] = ucfirst(str_replace('_', ' ', $record->getEquipment()->getEquipmentType()));
        }
        if (null !== $record->getTechnician()) {
            $parts[] = $record->getTechnician()->getDisplayName();
        }

        return implode(' · ', array_filter($parts));
    }

    private function serviceVisitOccurredAt(EquipmentServiceRecord $record): \DateTimeImmutable
    {
        return $record->getCompletedAt() ?? $record->getArrivedAt() ?? $record->getCreatedAt();
    }

    private function invoiceTitle(Invoice $invoice): string
    {
        return 'Invoice '.$invoice->getInvoiceNumber();
    }

    private function invoiceDetail(Invoice $invoice): string
    {
        return $invoice->getStatus();
    }

    private function invoiceOccurredAt(Invoice $invoice): \DateTimeImmutable
    {
        return $invoice->getSentAt()
            ?? $invoice->getIssuedAt()
            ?? $invoice->getUpdatedAt();
    }

    private function callTitle(\App\Entity\CallSession $callSession): string
    {
        return sprintf('%s call', null !== $callSession->getCallMode() ? ucfirst(str_replace('_', ' ', $callSession->getCallMode())) : 'Call');
    }

    private function callDetail(\App\Entity\CallSession $callSession): string
    {
        $parts = [];
        if (null !== $callSession->getContact()) {
            $parts[] = $callSession->getContact()->getDisplayName();
        }
        if (null !== $callSession->getCallState()) {
            $parts[] = ucfirst(str_replace('_', ' ', $callSession->getCallState()));
        }

        return implode(' · ', array_filter($parts));
    }

    private function callOccurredAt(\App\Entity\CallSession $callSession): \DateTimeImmutable
    {
        return $callSession->getEndedAt() ?? $callSession->getStartedAt() ?? $callSession->getUpdatedAt();
    }

    private function maintenancePlanTitle(PropertyMaintenancePlan $assignment): string
    {
        return $assignment->getMaintenancePlan()->getName();
    }

    private function maintenancePlanDetail(PropertyMaintenancePlan $assignment): string
    {
        return $assignment->isCancelled()
            ? sprintf('Cancelled%s', null !== $assignment->getCancellationDate() ? ' on '.$assignment->getCancellationDate()->format('Y-m-d') : '')
            : 'Active';
    }

    private function maintenancePlanOccurredAt(PropertyMaintenancePlan $assignment): \DateTimeImmutable
    {
        return $assignment->getCancellationDate() ?? $assignment->getCreatedAt();
    }

    private function retentionOpportunityTitle(RetentionOpportunity $opportunity): string
    {
        return $opportunity->getOpportunityTypeLabel();
    }

    private function retentionOpportunityDetail(RetentionOpportunity $opportunity): string
    {
        return $opportunity->getDetectedReason();
    }

    private function sentimentTitle(CustomerSentimentHistory $sentiment): string
    {
        return $sentiment->getSentimentLabel();
    }

    private function sentimentDetail(CustomerSentimentHistory $sentiment): string
    {
        $parts = [];
        if (null !== $sentiment->getContact()) {
            $parts[] = $sentiment->getContact()->getDisplayName();
        }
        if (null !== $sentiment->getNote()) {
            $parts[] = $sentiment->getNote();
        }

        return implode(' · ', array_filter($parts));
    }
}
