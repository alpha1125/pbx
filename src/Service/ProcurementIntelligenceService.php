<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\JobRepository;
use App\Repository\QuoteRepository;
use App\Repository\RfqInvitationRepository;
use App\Repository\RfqRepository;

final class ProcurementIntelligenceService
{
    public function __construct(
        private readonly RfqVendorAnalyticsService $vendorAnalyticsService,
        private readonly RfqRepository $rfqRepository,
        private readonly RfqInvitationRepository $invitationRepository,
        private readonly QuoteRepository $quoteRepository,
        private readonly JobRepository $jobRepository,
    ) {
    }

    /**
     * @return array{
     *   summary: array<string, int>,
     *   trendRows: list<array<string, int|string>>,
     *   rankedVendors: list<array<string, mixed>>,
     *   recommendations: list<array<string, mixed>>
     * }
     */
    public function buildReport(?string $search = null, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $trendRows = $this->buildTrendRows($now);
        $vendorReport = $this->vendorAnalyticsService->buildReport($search);
        $rankedVendors = $this->buildRankedVendors($vendorReport['vendors']);

        return [
            'summary' => $this->buildSummary($trendRows, $rankedVendors),
            'trendRows' => $trendRows,
            'rankedVendors' => $rankedVendors,
            'recommendations' => $this->buildRecommendations($trendRows, $rankedVendors),
        ];
    }

    /**
     * @return list<array<string, int|string>>
     */
    private function buildTrendRows(\DateTimeImmutable $now): array
    {
        $currentWeekStart = $this->startOfWeek($now);
        $rows = [];

        for ($offset = 5; $offset >= 0; --$offset) {
            $from = $currentWeekStart->modify(sprintf('-%d weeks', $offset));
            $to = $from->modify('+1 week');
            $rows[] = [
                'label' => sprintf('%s to %s', $from->format('M j'), $to->modify('-1 day')->format('M j')),
                'rfqsCreated' => $this->rfqRepository->countCreatedBetween($from, $to),
                'invitationsSent' => $this->invitationRepository->countInvitedBetween($from, $to),
                'quotesCreated' => $this->quoteRepository->countSentBetween($from, $to),
                'jobsCompleted' => $this->jobRepository->countCompletedBetween($from, $to),
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $vendors
     * @return list<array<string, mixed>>
     */
    private function buildRankedVendors(array $vendors): array
    {
        $ranked = [];
        foreach ($vendors as $row) {
            $responseRate = $this->ratio($row['invitationsViewed'], $row['invitationsSent']);
            $acceptRate = $this->ratio($row['invitationsAccepted'], $row['invitationsSent']);
            $quoteRate = $this->ratio($row['quotesCreated'], max(1, $row['invitationsAccepted']));
            $completionRate = $this->ratio($row['jobsCompleted'], max(1, $row['jobsCreated']));
            $averageFirstResponseMinutes = $this->average($row['firstResponseMinutesTotal'], $row['firstResponseSamples']);
            $averageCompletionMinutes = $this->average($row['completionMinutesTotal'], $row['completionSamples']);

            $score = ($responseRate * 20)
                + ($acceptRate * 30)
                + ($quoteRate * 35)
                + ($completionRate * 25)
                + ($row['jobsCompleted'] * 5)
                - ($averageFirstResponseMinutes / 12);

            $ranked[] = [
                'tenant' => $row['tenant'],
                'score' => round($score, 2),
                'responseRate' => $responseRate,
                'acceptRate' => $acceptRate,
                'quoteRate' => $quoteRate,
                'completionRate' => $completionRate,
                'averageFirstResponseMinutes' => $averageFirstResponseMinutes,
                'averageCompletionMinutes' => $averageCompletionMinutes,
                'signals' => $this->buildSignals($row),
            ];
        }

        usort($ranked, static function (array $left, array $right): int {
            $byScore = $right['score'] <=> $left['score'];
            if (0 !== $byScore) {
                return $byScore;
            }

            return strcmp($left['tenant']->getName(), $right['tenant']->getName());
        });

        return array_slice($ranked, 0, 8);
    }

    /**
     * @param list<array<string, mixed>> $trendRows
     * @param list<array<string, mixed>> $rankedVendors
     * @return array<string, int>
     */
    private function buildSummary(array $trendRows, array $rankedVendors): array
    {
        $summary = [
            'vendors' => count($rankedVendors),
            'rfqsCreated' => 0,
            'invitationsSent' => 0,
            'quotesCreated' => 0,
            'jobsCompleted' => 0,
        ];

        foreach ($trendRows as $row) {
            $summary['rfqsCreated'] += $row['rfqsCreated'];
            $summary['invitationsSent'] += $row['invitationsSent'];
            $summary['quotesCreated'] += $row['quotesCreated'];
            $summary['jobsCompleted'] += $row['jobsCompleted'];
        }

        return $summary;
    }

    /**
     * @param list<array<string, mixed>> $trendRows
     * @param list<array<string, mixed>> $rankedVendors
     * @return list<array<string, mixed>>
     */
    private function buildRecommendations(array $trendRows, array $rankedVendors): array
    {
        $recommendations = [];

        if ([] !== $rankedVendors) {
            $topVendor = $rankedVendors[0];
            $recommendations[] = [
                'title' => sprintf('Prioritize %s', $topVendor['tenant']->getName()),
                'body' => sprintf(
                    'Placeholder ranking score %.2f combines response speed, invitation acceptance, quote output, and completed work.',
                    $topVendor['score'],
                ),
                'badge' => 'Primary',
            ];
        }

        $fastestVendor = null;
        foreach ($rankedVendors as $vendor) {
            if (0.0 === $vendor['averageFirstResponseMinutes']) {
                continue;
            }

            if (null === $fastestVendor || $vendor['averageFirstResponseMinutes'] < $fastestVendor['averageFirstResponseMinutes']) {
                $fastestVendor = $vendor;
            }
        }

        if (null !== $fastestVendor) {
            $recommendations[] = [
                'title' => sprintf('Route urgent RFQs to %s', $fastestVendor['tenant']->getName()),
                'body' => sprintf(
                    'Fastest recorded first-response time is %.1f minutes. This is a ranking placeholder, not an automated award decision.',
                    $fastestVendor['averageFirstResponseMinutes'],
                ),
                'badge' => 'Speed',
            ];
        }

        $latestTrend = end($trendRows);
        if (is_array($latestTrend) && ($latestTrend['invitationsSent'] ?? 0) > ($latestTrend['quotesCreated'] ?? 0)) {
            $recommendations[] = [
                'title' => 'Review open invitation backlog',
                'body' => 'Recent invitations are outpacing quote production. Use the trend view to identify where follow-up is needed.',
                'badge' => 'Trend',
            ];
        }

        return $recommendations;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function buildSignals(array $row): array
    {
        $signals = [
            sprintf('Open %s', $this->formatPercent($this->ratio($row['invitationsViewed'], $row['invitationsSent']))),
            sprintf('Accept %s', $this->formatPercent($this->ratio($row['invitationsAccepted'], $row['invitationsSent']))),
            sprintf('Quote %s', $this->formatPercent($this->ratio($row['quotesCreated'], max(1, $row['invitationsAccepted'])))),
        ];

        if ($row['jobsCompleted'] > 0) {
            $signals[] = sprintf('Completed %d job%s', $row['jobsCompleted'], 1 === $row['jobsCompleted'] ? '' : 's');
        }

        return $signals;
    }

    private function startOfWeek(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->modify('monday this week')->setTime(0, 0);
    }

    private function ratio(int $numerator, int $denominator): float
    {
        if (0 === $denominator) {
            return 0.0;
        }

        return $numerator / $denominator;
    }

    private function average(float $total, int $samples): float
    {
        if (0 === $samples) {
            return 0.0;
        }

        return $total / $samples;
    }

    private function formatPercent(float $ratio): string
    {
        return number_format($ratio * 100, 1).'%';
    }
}
