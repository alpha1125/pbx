<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\RetentionOpportunity;
use App\Entity\Tenant;
use App\Entity\UserTenantMembership;
use App\Repository\CallSessionRepository;
use App\Repository\EstimateRepository;
use App\Repository\InvoiceRepository;
use App\Repository\JobRepository;
use App\Repository\QuoteRepository;
use App\Repository\RetentionOpportunityRepository;
use App\Repository\UserTenantMembershipRepository;

final class CrmReportingDashboardService
{
    public function __construct(
        private readonly EstimateRepository $estimateRepository,
        private readonly QuoteRepository $quoteRepository,
        private readonly CallSessionRepository $callSessionRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly JobRepository $jobRepository,
        private readonly RetentionOpportunityRepository $retentionOpportunityRepository,
        private readonly UserTenantMembershipRepository $membershipRepository,
    ) {
    }

    /**
     * @return array{
     *   periodDays:int,
     *   from:\DateTimeImmutable,
     *   to:\DateTimeImmutable,
     *   summary:array<string, mixed>,
     *   pipelineRows:list<array<string, mixed>>,
     *   callVolumeByProperty:list<array<string, mixed>>,
     *   callVolumeByContact:list<array<string, mixed>>,
     *   throughputByTechnician:list<array<string, mixed>>,
     *   throughputByDispatcher:list<array<string, mixed>>,
     *   revenueOpportunityCards:list<array<string, mixed>>
     * }
     */
    public function buildReport(Tenant $tenant, int $periodDays = 90, ?\DateTimeImmutable $now = null): array
    {
        $periodDays = max(7, $periodDays);
        $to = $now ?? new \DateTimeImmutable('now');
        $from = $to->modify(sprintf('-%d days', $periodDays));
        $membershipMap = $this->membershipMap($tenant);

        $estimatesCreated = $this->estimateRepository->countCreatedBetween($tenant, $from, $to);
        $estimatesConverted = $this->estimateRepository->countConvertedToQuoteBetween($tenant, $from, $to);
        $quotesSent = $this->quoteRepository->countSentBetweenForTenant($tenant, $from, $to);
        $quotesAccepted = $this->quoteRepository->countAcceptedBetweenForTenant($tenant, $from, $to);
        $callsTotal = $this->callSessionRepository->countBetween($tenant, $from, $to);
        $jobsCompleted = $this->jobRepository->countCompletedBetweenForTenant($tenant, $from, $to);
        $jobsAssigned = $this->jobRepository->countAssignedBetweenForTenant($tenant, $from, $to);

        $pipelineRows = $this->quoteRepository->summarizePipelineBetweenForTenant($tenant, $from, $to);
        $pipelineTotals = $this->pipelineTotals($pipelineRows);
        $callVolumeByProperty = $this->callSessionRepository->countByPropertyBetween($tenant, $from, $to, 5);
        $callVolumeByContact = $this->callSessionRepository->countByContactBetween($tenant, $from, $to, 5);

        $throughputByTechnician = $this->buildThroughputRows(
            $membershipMap,
            $this->jobRepository->findCompletedByAssigneeBetween($tenant, $from, $to, 10),
            'technician',
        );
        $throughputByDispatcher = $this->buildThroughputRows(
            $membershipMap,
            $this->jobRepository->findAssignedByAssigneeBetween($tenant, $from, $to, 10),
            'dispatcher',
        );
        $revenueOpportunityCards = $this->buildRevenueOpportunityCards($tenant);

        return [
            'periodDays' => $periodDays,
            'from' => $from,
            'to' => $to,
            'summary' => [
                'estimatesCreated' => $estimatesCreated,
                'estimatesConverted' => $estimatesConverted,
                'leadToQuoteConversionRate' => $this->ratioOrNull($estimatesConverted, $estimatesCreated),
                'quotesSent' => $quotesSent,
                'quotesAccepted' => $quotesAccepted,
                'quoteAcceptanceRate' => $this->ratioOrNull($quotesAccepted, $quotesSent),
                'pipelineValueCents' => $pipelineTotals['totalCents'],
                'openPipelineCents' => $pipelineTotals['openCents'],
                'wonPipelineCents' => $pipelineTotals['wonCents'],
                'lostPipelineCents' => $pipelineTotals['lostCents'],
                'callsTotal' => $callsTotal,
                'jobsCompleted' => $jobsCompleted,
                'jobsAssigned' => $jobsAssigned,
            ],
            'pipelineRows' => $this->labelPipelineRows($pipelineRows),
            'callVolumeByProperty' => $callVolumeByProperty,
            'callVolumeByContact' => $callVolumeByContact,
            'throughputByTechnician' => $throughputByTechnician,
            'throughputByDispatcher' => $throughputByDispatcher,
            'revenueOpportunityCards' => $revenueOpportunityCards,
        ];
    }

    /**
     * @return array<int, array{displayName:string,roles:list<string>}>
     */
    private function membershipMap(Tenant $tenant): array
    {
        $map = [];
        foreach ($this->membershipRepository->findByTenantOrdered($tenant, 1, 500) as $membership) {
            $userId = $membership->getUser()->getId();
            if (null === $userId) {
                continue;
            }

            $map[$userId] = [
                'displayName' => $membership->getUser()->getDisplayName(),
                'roles' => $membership->getRoles(),
            ];
        }

        return $map;
    }

    /**
     * @param list<array{status:string,count:int,totalCents:int}> $pipelineRows
     * @return array{totalCents:int,openCents:int,wonCents:int,lostCents:int}
     */
    private function pipelineTotals(array $pipelineRows): array
    {
        $totals = [
            'totalCents' => 0,
            'openCents' => 0,
            'wonCents' => 0,
            'lostCents' => 0,
        ];

        foreach ($pipelineRows as $row) {
            $totals['totalCents'] += $row['totalCents'];
            if (in_array($row['status'], [\App\Entity\Quote::STATUS_DRAFT, \App\Entity\Quote::STATUS_IN_REVIEW, \App\Entity\Quote::STATUS_SENT, \App\Entity\Quote::STATUS_VIEWED], true)) {
                $totals['openCents'] += $row['totalCents'];
            } elseif (\App\Entity\Quote::STATUS_ACCEPTED === $row['status']) {
                $totals['wonCents'] += $row['totalCents'];
            } else {
                $totals['lostCents'] += $row['totalCents'];
            }
        }

        return $totals;
    }

    /**
     * @param list<array{status:string,count:int,totalCents:int}> $pipelineRows
     * @return list<array{status:string,label:string,count:int,totalCents:int}>
     */
    private function labelPipelineRows(array $pipelineRows): array
    {
        return array_map(static fn (array $row): array => [
            'status' => $row['status'],
            'label' => ucfirst(str_replace('_', ' ', $row['status'])),
            'count' => $row['count'],
            'totalCents' => $row['totalCents'],
        ], $pipelineRows);
    }

    /**
     * @param array<int, array{displayName:string,roles:list<string>}> $membershipMap
     * @param list<array{userId:int|null,userLabel:string,jobCount:int}> $rows
     * @return list<array<string, mixed>>
     */
    private function buildThroughputRows(array $membershipMap, array $rows, string $preferredBucket): array
    {
        $bucketRows = [];
        foreach ($rows as $row) {
            $userId = $row['userId'];
            $roles = $membershipMap[$userId]['roles'] ?? [];
            $displayName = $membershipMap[$userId]['displayName'] ?? $row['userLabel'];
            $bucket = $this->throughputBucket($roles);
            if ($preferredBucket !== $bucket) {
                continue;
            }

            $bucketRows[] = [
                'userId' => $userId,
                'displayName' => $displayName,
                'jobCount' => $row['jobCount'],
                'roles' => $roles,
            ];
        }

        return $bucketRows;
    }

    /**
     * @param list<string> $roles
     */
    private function throughputBucket(array $roles): string
    {
        if (in_array(UserTenantMembership::ROLE_TECHNICIAN, $roles, true)) {
            return 'technician';
        }

        return 'dispatcher';
    }

    private function ratioOrNull(int $numerator, int $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }

        return $numerator / $denominator;
    }

    /**
     * @return list<array{
     *   key:string,
     *   label:string,
     *   description:string,
     *   count:int,
     *   estimatedValueCents:?int,
     *   items:list<array<string, mixed>>
     * }>
     */
    private function buildRevenueOpportunityCards(Tenant $tenant): array
    {
        $groups = [
            'dormant_customers' => [
                'label' => 'Dormant Customers',
                'description' => 'Re-engage customers with little recent activity.',
                'types' => [RetentionOpportunity::TYPE_DORMANT_CUSTOMER],
            ],
            'maintenance_opportunities' => [
                'label' => 'Maintenance Opportunities',
                'description' => 'Signals pointing to overdue service or an uncovered maintenance plan.',
                'types' => [
                    RetentionOpportunity::TYPE_NO_RECENT_SERVICE,
                    RetentionOpportunity::TYPE_NO_RECENT_CALLS,
                    RetentionOpportunity::TYPE_MAINTENANCE_PLAN_MISSING,
                ],
            ],
            'replacement_opportunities' => [
                'label' => 'Replacement Opportunities',
                'description' => 'Properties with aging or flagged equipment.',
                'types' => [RetentionOpportunity::TYPE_OLD_EQUIPMENT],
            ],
            'warranty_opportunities' => [
                'label' => 'Warranty Opportunities',
                'description' => 'Equipment approaching warranty expiration.',
                'types' => [RetentionOpportunity::TYPE_WARRANTY_NEARING_EXPIRATION],
            ],
            'overdue_invoice_opportunities' => [
                'label' => 'Overdue Invoice Opportunities',
                'description' => 'Open balances that need follow-up.',
                'types' => [RetentionOpportunity::TYPE_OPEN_INVOICE],
            ],
        ];

        $openOpportunities = $this->retentionOpportunityRepository->findOpenByTenant($tenant);
        $grouped = [];
        foreach ($groups as $key => $definition) {
            $grouped[$key] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'count' => 0,
                'estimatedValueCents' => null,
                'items' => [],
            ];
        }

        foreach ($openOpportunities as $opportunity) {
            foreach ($groups as $key => $definition) {
                if (!in_array($opportunity->getOpportunityType(), $definition['types'], true)) {
                    continue;
                }

                $grouped[$key]['items'][] = $this->formatRevenueOpportunityItem($opportunity);
                ++$grouped[$key]['count'];
            }
        }

        $invoiceBalancesByProperty = [];
        $invoiceOpportunityGroup = 'overdue_invoice_opportunities';
        $invoicePropertyIds = array_values(array_unique(array_map(
            static fn (array $item): int => (int) $item['propertyId'],
            $grouped[$invoiceOpportunityGroup]['items'],
        )));
        if ([] !== $invoicePropertyIds) {
            foreach ($this->invoiceRepository->summarizeOpenBalancesByTenantAndPropertyIds($tenant, $invoicePropertyIds) as $row) {
                $invoiceBalancesByProperty[$row['propertyId']] = $row;
            }

            $grouped[$invoiceOpportunityGroup]['estimatedValueCents'] = array_reduce(
                $grouped[$invoiceOpportunityGroup]['items'],
                static function (int $carry, array $item) use ($invoiceBalancesByProperty): int {
                    $balance = $invoiceBalancesByProperty[$item['propertyId']]['totalCents'] ?? null;

                    return $carry + (int) ($balance ?? 0);
                },
                0,
            );

            foreach ($grouped[$invoiceOpportunityGroup]['items'] as $index => $item) {
                $balance = $invoiceBalancesByProperty[$item['propertyId']]['totalCents'] ?? null;
                $grouped[$invoiceOpportunityGroup]['items'][$index]['estimatedValueCents'] = null !== $balance ? (int) $balance : null;
                $grouped[$invoiceOpportunityGroup]['items'][$index]['invoiceCount'] = $invoiceBalancesByProperty[$item['propertyId']]['invoiceCount'] ?? null;
            }
        }

        return array_values(array_map(
            static function (array $card): array {
                usort(
                    $card['items'],
                    static fn (array $left, array $right): int => ($right['detectedAt'] <=> $left['detectedAt'])
                        ?: (($right['propertyLabel'] ?? '') <=> ($left['propertyLabel'] ?? '')),
                );

                return $card;
            },
            $grouped,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRevenueOpportunityItem(RetentionOpportunity $opportunity): array
    {
        return [
            'id' => $opportunity->getId(),
            'propertyId' => (int) ($opportunity->getProperty()->getId() ?? 0),
            'propertyLabel' => $opportunity->getProperty()->getDisplayAddress(),
            'opportunityType' => $opportunity->getOpportunityType(),
            'opportunityTypeLabel' => $opportunity->getOpportunityTypeLabel(),
            'reason' => $opportunity->getDetectedReason(),
            'detectedAt' => $opportunity->getDetectedAt(),
            'status' => $opportunity->getStatus(),
            'statusLabel' => $opportunity->getStatusLabel(),
            'contactLabel' => null !== $opportunity->getContact() ? $opportunity->getContact()->getDisplayName() : null,
            'equipmentLabel' => null !== $opportunity->getEquipment() ? $opportunity->getEquipment()->getEquipmentType() : null,
            'estimatedValueCents' => null,
            'invoiceCount' => null,
        ];
    }
}
