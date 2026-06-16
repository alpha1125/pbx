<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Repository\JobRepository;
use App\Repository\QuoteRepository;
use App\Repository\RfqInvitationRepository;
use App\Repository\RfqRepository;
use App\Service\ProcurementIntelligenceService;
use App\Service\RfqVendorAnalyticsService;
use PHPUnit\Framework\TestCase;

final class ProcurementIntelligenceServiceTest extends TestCase
{
    public function testBuildReportProducesTrendRowsRankingsAndRecommendations(): void
    {
        $tenantA = $this->tenantWithId(101, 'Alpha Vendor');
        $tenantB = $this->tenantWithId(102, 'Beta Vendor');

        $vendorAnalyticsService = $this->createMock(RfqVendorAnalyticsService::class);
        $vendorAnalyticsService->expects(self::once())
            ->method('buildReport')
            ->with(null)
            ->willReturn([
                'vendors' => [
                    [
                        'tenant' => $tenantA,
                        'invitationsSent' => 10,
                        'invitationsViewed' => 8,
                        'invitationsAccepted' => 6,
                        'quotesCreated' => 5,
                        'jobsCreated' => 4,
                        'jobsCompleted' => 3,
                        'firstResponseMinutesTotal' => 120.0,
                        'firstResponseSamples' => 4,
                        'completionMinutesTotal' => 360.0,
                        'completionSamples' => 3,
                    ],
                    [
                        'tenant' => $tenantB,
                        'invitationsSent' => 8,
                        'invitationsViewed' => 3,
                        'invitationsAccepted' => 2,
                        'quotesCreated' => 1,
                        'jobsCreated' => 1,
                        'jobsCompleted' => 0,
                        'firstResponseMinutesTotal' => 30.0,
                        'firstResponseSamples' => 1,
                        'completionMinutesTotal' => 0.0,
                        'completionSamples' => 0,
                    ],
                ],
                'summary' => [],
            ]);

        $rfqRepository = $this->createMock(RfqRepository::class);
        $rfqRepository->expects(self::exactly(6))
            ->method('countCreatedBetween')
            ->willReturnOnConsecutiveCalls(2, 3, 4, 5, 6, 7);

        $invitationRepository = $this->createMock(RfqInvitationRepository::class);
        $invitationRepository->expects(self::exactly(6))
            ->method('countInvitedBetween')
            ->willReturnOnConsecutiveCalls(4, 4, 5, 5, 6, 6);

        $quoteRepository = $this->createMock(QuoteRepository::class);
        $quoteRepository->expects(self::exactly(6))
            ->method('countSentBetween')
            ->willReturnOnConsecutiveCalls(1, 1, 2, 2, 3, 3);

        $jobRepository = $this->createMock(JobRepository::class);
        $jobRepository->expects(self::exactly(6))
            ->method('countCompletedBetween')
            ->willReturnOnConsecutiveCalls(0, 1, 1, 2, 2, 3);

        $service = new ProcurementIntelligenceService(
            $vendorAnalyticsService,
            $rfqRepository,
            $invitationRepository,
            $quoteRepository,
            $jobRepository,
        );

        $report = $service->buildReport(null, new \DateTimeImmutable('2026-06-16 12:00:00'));

        self::assertSame(2, $report['summary']['vendors']);
        self::assertSame(27, $report['summary']['rfqsCreated']);
        self::assertSame(30, $report['summary']['invitationsSent']);
        self::assertSame(12, $report['summary']['quotesCreated']);
        self::assertSame(9, $report['summary']['jobsCompleted']);
        self::assertCount(6, $report['trendRows']);
        self::assertSame('Alpha Vendor', $report['rankedVendors'][0]['tenant']->getName());
        self::assertGreaterThan($report['rankedVendors'][1]['score'], $report['rankedVendors'][0]['score']);
        self::assertNotEmpty($report['recommendations']);
        self::assertSame('Primary', $report['recommendations'][0]['badge']);
    }

    private function tenantWithId(int $id, string $name): Tenant
    {
        $tenant = (new Tenant($name))->setRfqVendorEnabled(true);
        $reflection = new \ReflectionProperty(Tenant::class, 'id');
        $reflection->setValue($tenant, $id);

        return $tenant;
    }
}
