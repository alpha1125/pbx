<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Estimate;
use App\Entity\Equipment;
use App\Entity\Property;
use App\Repository\CommunicationTimelineItemRepository;
use App\Repository\EstimateLineItemRepository;
use App\Repository\EquipmentRepository;
use App\Repository\EquipmentServiceRecordRepository;

final class CrmSuggestionService
{
    private const EQUIPMENT_REPLACEMENT_AGE_YEARS = [
        Equipment::TYPE_FURNACE => 15,
        Equipment::TYPE_AIR_CONDITIONER => 12,
        Equipment::TYPE_HEAT_PUMP => 12,
        Equipment::TYPE_EVAPORATOR_COIL => 12,
        Equipment::TYPE_WATER_HEATER => 10,
        Equipment::TYPE_HUMIDIFIER => 15,
        Equipment::TYPE_ERV => 15,
        Equipment::TYPE_HRV => 15,
        Equipment::TYPE_THERMOSTAT => 20,
    ];

    public function __construct(
        private readonly CommunicationTimelineItemRepository $timelineItems,
        private readonly EstimateLineItemRepository $estimateLineItems,
        private readonly EquipmentRepository $equipmentRepository,
        private readonly EquipmentServiceRecordRepository $serviceRecordRepository,
    ) {
    }

    /**
     * @return array{
     *   lineItemSuggestions:list<array<string, mixed>>,
     *   followUpSuggestions:list<array<string, mixed>>,
     *   equipmentFlags:list<array<string, mixed>>,
     *   context:array<string, mixed>
     * }
     */
    public function buildForEstimate(Estimate $estimate): array
    {
        return $this->build($estimate->getProperty(), $estimate);
    }

    /**
     * @return array{
     *   lineItemSuggestions:list<array<string, mixed>>,
     *   followUpSuggestions:list<array<string, mixed>>,
     *   equipmentFlags:list<array<string, mixed>>,
     *   context:array<string, mixed>
     * }
     */
    public function buildForProperty(Property $property): array
    {
        return $this->build($property, null);
    }

    /**
     * @return array{
     *   lineItemSuggestions:list<array<string, mixed>>,
     *   followUpSuggestions:list<array<string, mixed>>,
     *   equipmentFlags:list<array<string, mixed>>,
     *   context:array<string, mixed>
     * }
     */
    private function build(Property $property, ?Estimate $estimate): array
    {
        $context = $this->latestConversationContext($property);
        $equipmentFlags = $this->buildEquipmentReplacementFlags($property);
        $existingLineItems = null !== $estimate ? $this->estimateLineItems->findByEstimate($estimate) : [];

        return [
            'lineItemSuggestions' => $this->buildLineItemSuggestions($property, $context, $existingLineItems),
            'followUpSuggestions' => $this->buildFollowUpSuggestions($property, $context, $equipmentFlags),
            'equipmentFlags' => $equipmentFlags,
            'context' => $context,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function latestConversationContext(Property $property): array
    {
        foreach ($this->timelineItems->findByTenantAndPropertyOrdered($property->getTenant(), $property, [\App\Entity\CommunicationTimelineItem::TYPE_SUMMARY, \App\Entity\CommunicationTimelineItem::TYPE_TRANSCRIPT], null, 10) as $item) {
            $metadata = $item->getMetadata() ?? [];
            $insights = $metadata['aiInsights'] ?? null;
            if (is_array($insights) && [] !== $insights) {
                return $insights;
            }
        }

        return [];
    }

    /**
     * @param list<\App\Entity\EstimateLineItem> $existingLineItems
     * @return list<array<string, mixed>>
     */
    private function buildLineItemSuggestions(Property $property, array $context, array $existingLineItems): array
    {
        $existingDescriptions = array_values(array_map(
            static fn ($lineItem): string => mb_strtolower(trim((string) $lineItem->getDescription())),
            $existingLineItems,
        ));
        $haystack = mb_strtolower(implode(' ', array_filter([
            (string) ($context['summary'] ?? ''),
            (string) ($context['customer_intent'] ?? ''),
            implode(' ', $context['equipment_mentions'] ?? []),
            implode(' ', $context['quote_or_price_mentions'] ?? []),
            implode(' ', $context['action_items'] ?? []),
        ])));

        $suggestions = [];
        foreach ($this->equipmentSuggestionCatalog() as $needle => $suggestion) {
            if (!str_contains($haystack, $needle)) {
                continue;
            }

            if ($this->alreadyHasLineItem($existingDescriptions, $suggestion['description'])) {
                continue;
            }

            $suggestions[] = $suggestion + ['source' => 'conversation'];
        }

        if ([] === $suggestions && (str_contains($haystack, 'estimate') || str_contains($haystack, 'quote') || str_contains($haystack, 'repair'))) {
            $generic = [
                'title' => 'Detailed repair estimate',
                'description' => 'Create a scoped repair estimate with diagnostic and labor items.',
                'reason' => 'Conversation mentions pricing or repair work.',
            ];
            if (!$this->alreadyHasLineItem($existingDescriptions, $generic['description'])) {
                $suggestions[] = $generic + ['source' => 'conversation'];
            }
        }

        if ([] === $suggestions && [] !== $this->buildEquipmentReplacementFlags($property)) {
            $replacement = [
                'title' => 'Replacement options estimate',
                'description' => 'Prepare replacement options and pricing for flagged equipment.',
                'reason' => 'Equipment history indicates replacement risk.',
            ];
            if (!$this->alreadyHasLineItem($existingDescriptions, $replacement['description'])) {
                $suggestions[] = $replacement + ['source' => 'equipment_history'];
            }
        }

        return array_slice($suggestions, 0, 4);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildFollowUpSuggestions(Property $property, array $context, array $equipmentFlags): array
    {
        $recommendations = [];
        $disposition = (string) ($context['recommended_disposition'] ?? '');
        $nextStep = trim((string) ($context['next_step'] ?? ''));

        if (in_array($disposition, ['quote_requested', 'follow_up_required'], true)) {
            $recommendations[] = [
                'title' => 'Call customer back',
                'description' => 'Confirm the scope, pricing, and preferred next step with the customer.',
                'reason' => 'Conversation indicates a follow-up is still pending.',
            ];
        }

        if ([] !== ($context['appointment_mentions'] ?? [])) {
            $recommendations[] = [
                'title' => 'Schedule callback or visit',
                'description' => 'Book the next service appointment or callback time.',
                'reason' => 'The transcript references an appointment or callback window.',
            ];
        }

        if ([] !== $equipmentFlags) {
            $recommendations[] = [
                'title' => 'Review replacement options',
                'description' => 'Walk the customer through replacement or upgrade options for the flagged equipment.',
                'reason' => 'Service history or equipment age suggests replacement risk.',
            ];
        }

        if ('' !== $nextStep) {
            $recommendations[] = [
                'title' => 'Advance suggested next step',
                'description' => $nextStep,
                'reason' => 'Conversation insight already suggests the next follow-up action.',
            ];
        }

        return $this->uniqueSuggestions($recommendations, 'title');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildEquipmentReplacementFlags(Property $property): array
    {
        $flags = [];
        foreach ($this->equipmentRepository->findByProperty($property) as $equipment) {
            $flag = $this->evaluateEquipment($equipment);
            if (null !== $flag) {
                $flags[] = $flag;
            }
        }

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
        $replacementThreshold = self::EQUIPMENT_REPLACEMENT_AGE_YEARS[$equipment->getEquipmentType()] ?? 15;
        $serviceRecords = $this->serviceRecordRepository->findByEquipment($equipment);
        $latestServiceRecord = $serviceRecords[0] ?? null;

        $reason = null;
        $severity = 'medium';
        if (null !== $latestServiceRecord && $this->containsReplacementLanguage($latestServiceRecord)) {
            $reason = 'Latest service history mentions replacement.';
            $severity = 'high';
        } elseif (null !== $equipment->getWarrantyExpiresAt() && $equipment->getWarrantyExpiresAt() < new \DateTimeImmutable('today')) {
            $reason = 'Warranty has expired.';
            $severity = 'medium';
        } elseif (null !== $equipment->getWarrantyExpiresAt() && $equipment->getWarrantyExpiresAt() <= new \DateTimeImmutable('+180 days')) {
            $reason = 'Warranty expires within 6 months.';
            $severity = 'medium';
        } elseif (null !== $ageYears && $ageYears >= $replacementThreshold) {
            $reason = sprintf('Installed %d years ago, which exceeds the %d-year replacement threshold.', $ageYears, $replacementThreshold);
            $severity = 'high';
        }

        if (null === $reason) {
            return null;
        }

        return [
            'equipmentId' => $equipment->getId(),
            'equipmentType' => $equipment->getEquipmentType(),
            'displayName' => $this->equipmentDisplayName($equipment),
            'ageYears' => $ageYears,
            'severity' => $severity,
            'reason' => $reason,
            'serviceHistoryCount' => count($serviceRecords),
            'source' => 'equipment_history',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function equipmentSuggestionCatalog(): array
    {
        return [
            'furnace' => [
                'title' => 'Furnace diagnostic and repair',
                'description' => 'Add furnace diagnostic, repair labor, and parts line items.',
                'reason' => 'Transcript mentions furnace work.',
            ],
            'air conditioner' => [
                'title' => 'Air conditioner diagnostic and repair',
                'description' => 'Add air conditioner diagnostic, repair labor, and parts line items.',
                'reason' => 'Transcript mentions air conditioning work.',
            ],
            'heat pump' => [
                'title' => 'Heat pump diagnostic and repair',
                'description' => 'Add heat pump diagnostic, repair labor, and parts line items.',
                'reason' => 'Transcript mentions heat pump work.',
            ],
            'water heater' => [
                'title' => 'Water heater service',
                'description' => 'Add water heater service or replacement pricing line items.',
                'reason' => 'Transcript mentions water heater work.',
            ],
            'thermostat' => [
                'title' => 'Thermostat replacement',
                'description' => 'Add thermostat replacement and setup line items.',
                'reason' => 'Transcript mentions thermostat work.',
            ],
            'coil' => [
                'title' => 'Coil inspection and repair',
                'description' => 'Add evaporator coil inspection and repair line items.',
                'reason' => 'Transcript mentions coil work.',
            ],
            'humidifier' => [
                'title' => 'Humidifier service',
                'description' => 'Add humidifier repair or replacement line items.',
                'reason' => 'Transcript mentions humidifier work.',
            ],
            'hrv' => [
                'title' => 'HRV service',
                'description' => 'Add HRV service or replacement line items.',
                'reason' => 'Transcript mentions HRV work.',
            ],
            'erv' => [
                'title' => 'ERV service',
                'description' => 'Add ERV service or replacement line items.',
                'reason' => 'Transcript mentions ERV work.',
            ],
        ];
    }

    /**
     * @param list<string> $existingDescriptions
     */
    private function alreadyHasLineItem(array $existingDescriptions, string $description): bool
    {
        $needle = mb_strtolower($description);
        foreach ($existingDescriptions as $existing) {
            if ('' !== $existing && (str_contains($existing, $needle) || str_contains($needle, $existing))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $suggestions
     * @return list<array<string, mixed>>
     */
    private function uniqueSuggestions(array $suggestions, string $field): array
    {
        $seen = [];
        $unique = [];
        foreach ($suggestions as $suggestion) {
            $key = mb_strtolower((string) ($suggestion[$field] ?? ''));
            if ('' === $key || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $suggestion;
        }

        return $unique;
    }

    private function equipmentDisplayName(Equipment $equipment): string
    {
        return match ($equipment->getEquipmentType()) {
            Equipment::TYPE_FURNACE => 'Furnace',
            Equipment::TYPE_AIR_CONDITIONER => 'Air conditioner',
            Equipment::TYPE_HEAT_PUMP => 'Heat pump',
            Equipment::TYPE_EVAPORATOR_COIL => 'Evaporator coil',
            Equipment::TYPE_THERMOSTAT => 'Thermostat',
            Equipment::TYPE_HUMIDIFIER => 'Humidifier',
            Equipment::TYPE_ERV => 'ERV',
            Equipment::TYPE_HRV => 'HRV',
            Equipment::TYPE_WATER_HEATER => 'Water heater',
            default => ucfirst(str_replace('_', ' ', $equipment->getEquipmentType())),
        };
    }

    private function containsReplacementLanguage(object $serviceRecord): bool
    {
        $notes = mb_strtolower(implode(' ', array_filter([
            (string) ($serviceRecord->getTechnicianNotes() ?? ''),
            (string) ($serviceRecord->getRecommendedRepairNotes() ?? ''),
            (string) ($serviceRecord->getRecommendedReplacementNotes() ?? ''),
        ])));

        return str_contains($notes, 'replace') || str_contains($notes, 'replacement') || str_contains($notes, 'upgrade');
    }
}
