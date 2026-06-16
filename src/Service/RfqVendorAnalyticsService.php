<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Job;
use App\Entity\Quote;
use App\Entity\RfqInvitation;
use App\Entity\Tenant;
use App\Repository\JobRepository;
use App\Repository\QuoteRepository;
use App\Repository\RfqInvitationRepository;
use App\Repository\TenantRepository;

class RfqVendorAnalyticsService
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly RfqInvitationRepository $invitationRepository,
        private readonly QuoteRepository $quoteRepository,
        private readonly JobRepository $jobRepository,
    ) {
    }

    /**
     * @return array{
     *   vendors: list<array<string, mixed>>,
     *   summary: array<string, mixed>
     * }
     */
    public function buildReport(?string $search = null): array
    {
        $tenants = $this->tenantRepository->findForVendorAnalytics($search);
        $tenantIds = array_values(array_filter(array_map(static fn (Tenant $tenant): ?int => $tenant->getId(), $tenants)));

        $rowsByTenantId = [];
        foreach ($tenants as $tenant) {
            $tenantId = $tenant->getId();
            if (null === $tenantId) {
                continue;
            }

            $rowsByTenantId[$tenantId] = $this->createEmptyRow($tenant);
        }

        foreach ($this->invitationRepository->findForVendorAnalyticsByTenantIds($tenantIds) as $invitation) {
            $this->accumulateInvitationMetrics($rowsByTenantId, $invitation);
        }

        foreach ($this->quoteRepository->findForVendorAnalyticsByTenantIds($tenantIds) as $quote) {
            $this->accumulateQuoteMetrics($rowsByTenantId, $quote);
        }

        foreach ($this->jobRepository->findForVendorAnalyticsByTenantIds($tenantIds) as $job) {
            $this->accumulateJobMetrics($rowsByTenantId, $job);
        }

        $rows = array_values($rowsByTenantId);
        usort($rows, static function (array $left, array $right): int {
            $bySent = $right['invitationsSent'] <=> $left['invitationsSent'];
            if (0 !== $bySent) {
                return $bySent;
            }

            return strcmp($left['tenant']->getName(), $right['tenant']->getName());
        });

        return [
            'vendors' => $rows,
            'summary' => $this->buildSummary($rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createEmptyRow(Tenant $tenant): array
    {
        return [
            'tenant' => $tenant,
            'invitationsSent' => 0,
            'invitationsViewed' => 0,
            'invitationsAccepted' => 0,
            'invitationsDeclined' => 0,
            'quotesCreated' => 0,
            'jobsCreated' => 0,
            'jobsCompleted' => 0,
            'firstResponseMinutesTotal' => 0.0,
            'firstResponseSamples' => 0,
            'completionMinutesTotal' => 0.0,
            'completionSamples' => 0,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rowsByTenantId
     */
    private function accumulateInvitationMetrics(array &$rowsByTenantId, RfqInvitation $invitation): void
    {
        $tenantId = $invitation->getTenant()->getId();
        if (null === $tenantId || !isset($rowsByTenantId[$tenantId])) {
            return;
        }

        $row = &$rowsByTenantId[$tenantId];
        ++$row['invitationsSent'];

        if (null !== $invitation->getViewedAt()) {
            ++$row['invitationsViewed'];
        }

        if (null !== $invitation->getAcceptedAt()) {
            ++$row['invitationsAccepted'];
        }

        if (null !== $invitation->getDeclinedAt()) {
            ++$row['invitationsDeclined'];
        }

        $responseAt = $invitation->getViewedAt()
            ?? $invitation->getAcceptedAt()
            ?? $invitation->getDeclinedAt();

        if (null !== $responseAt) {
            $row['firstResponseMinutesTotal'] += $this->minutesBetween(
                $invitation->getInvitedAt() ?? $invitation->getCreatedAt(),
                $responseAt,
            );
            ++$row['firstResponseSamples'];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rowsByTenantId
     */
    private function accumulateQuoteMetrics(array &$rowsByTenantId, Quote $quote): void
    {
        $tenantId = $quote->getTenant()->getId();
        if (null === $tenantId || !isset($rowsByTenantId[$tenantId])) {
            return;
        }

        $row = &$rowsByTenantId[$tenantId];
        ++$row['quotesCreated'];
    }

    /**
     * @param array<int, array<string, mixed>> $rowsByTenantId
     */
    private function accumulateJobMetrics(array &$rowsByTenantId, Job $job): void
    {
        $tenantId = $job->getTenant()->getId();
        if (null === $tenantId || !isset($rowsByTenantId[$tenantId])) {
            return;
        }

        $row = &$rowsByTenantId[$tenantId];
        ++$row['jobsCreated'];

        if (null === $job->getCompletedAt()) {
            return;
        }

        ++$row['jobsCompleted'];
        $startedAt = $job->getStartedAt() ?? $job->getAssignedAt() ?? $job->getCreatedAt();
        $row['completionMinutesTotal'] += $this->minutesBetween($startedAt, $job->getCompletedAt());
        ++$row['completionSamples'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildSummary(array $rows): array
    {
        $summary = [
            'vendors' => count($rows),
            'invitationsSent' => 0,
            'invitationsViewed' => 0,
            'invitationsAccepted' => 0,
            'quotesCreated' => 0,
            'jobsCreated' => 0,
            'jobsCompleted' => 0,
            'firstResponseMinutesTotal' => 0.0,
            'firstResponseSamples' => 0,
            'completionMinutesTotal' => 0.0,
            'completionSamples' => 0,
        ];

        foreach ($rows as $row) {
            $summary['invitationsSent'] += $row['invitationsSent'];
            $summary['invitationsViewed'] += $row['invitationsViewed'];
            $summary['invitationsAccepted'] += $row['invitationsAccepted'];
            $summary['quotesCreated'] += $row['quotesCreated'];
            $summary['jobsCreated'] += $row['jobsCreated'];
            $summary['jobsCompleted'] += $row['jobsCompleted'];
            $summary['firstResponseMinutesTotal'] += $row['firstResponseMinutesTotal'];
            $summary['firstResponseSamples'] += $row['firstResponseSamples'];
            $summary['completionMinutesTotal'] += $row['completionMinutesTotal'];
            $summary['completionSamples'] += $row['completionSamples'];
        }

        $summary['openRate'] = $this->ratioOrNull($summary['invitationsViewed'], $summary['invitationsSent']);
        $summary['acceptRate'] = $this->ratioOrNull($summary['invitationsAccepted'], $summary['invitationsSent']);
        $summary['quoteRate'] = $this->ratioOrNull($summary['quotesCreated'], $summary['invitationsAccepted']);
        $summary['completionRate'] = $this->ratioOrNull($summary['jobsCompleted'], $summary['jobsCreated']);
        $summary['averageFirstResponseMinutes'] = $this->averageOrNull($summary['firstResponseMinutesTotal'], $summary['firstResponseSamples']);
        $summary['averageCompletionMinutes'] = $this->averageOrNull($summary['completionMinutesTotal'], $summary['completionSamples']);

        return $summary;
    }

    private function ratioOrNull(int $numerator, int $denominator): ?float
    {
        if (0 === $denominator) {
            return null;
        }

        return $numerator / $denominator;
    }

    private function averageOrNull(float $total, int $samples): ?float
    {
        if (0 === $samples) {
            return null;
        }

        return $total / $samples;
    }

    private function minutesBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): float
    {
        return max(0, $end->getTimestamp() - $start->getTimestamp()) / 60;
    }
}
