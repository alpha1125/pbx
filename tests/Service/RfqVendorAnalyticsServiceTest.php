<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Job;
use App\Entity\Property;
use App\Entity\Quote;
use App\Entity\Rfq;
use App\Entity\RfqInvitation;
use App\Entity\Tenant;
use App\Repository\JobRepository;
use App\Repository\QuoteRepository;
use App\Repository\RfqInvitationRepository;
use App\Repository\TenantRepository;
use App\Service\RfqVendorAnalyticsService;
use PHPUnit\Framework\TestCase;

final class RfqVendorAnalyticsServiceTest extends TestCase
{
    public function testBuildReportAggregatesVendorEngagementMetrics(): void
    {
        $tenantA = $this->tenantWithId(101, 'Alpha Vendor');
        $tenantB = $this->tenantWithId(102, 'Beta Vendor');
        $propertyA = new Property($tenantA, '10 Alpha St', 'Toronto', 'ON', 'M1M1M1');
        $propertyB = new Property($tenantB, '20 Beta St', 'Toronto', 'ON', 'M2M2M2');
        $rfqA1 = new Rfq('100 Alpha St', 'Toronto', 'ON', 'M1M1M9');
        $rfqA2 = new Rfq('101 Alpha St', 'Toronto', 'ON', 'M1M1M8');
        $rfqB1 = new Rfq('200 Beta St', 'Toronto', 'ON', 'M2M2M9');

        $invitationA1 = (new RfqInvitation($tenantA, $rfqA1))
            ->setInvitedAt(new \DateTimeImmutable('2026-06-16 10:00:00'))
            ->setViewedAt(new \DateTimeImmutable('2026-06-16 10:30:00'))
            ->setAcceptedAt(new \DateTimeImmutable('2026-06-16 10:45:00'))
            ->setStatus(RfqInvitation::STATUS_ACCEPTED_FOR_QUOTE);
        $invitationA1->setCreatedEstimate(new \App\Entity\Estimate($tenantA, $propertyA));

        $invitationA2 = (new RfqInvitation($tenantA, $rfqA2))
            ->setInvitedAt(new \DateTimeImmutable('2026-06-16 11:00:00'))
            ->setDeclinedAt(new \DateTimeImmutable('2026-06-16 12:00:00'))
            ->setStatus(RfqInvitation::STATUS_DECLINED);

        $invitationB1 = (new RfqInvitation($tenantB, $rfqB1))
            ->setInvitedAt(new \DateTimeImmutable('2026-06-16 13:00:00'))
            ->setViewedAt(new \DateTimeImmutable('2026-06-16 13:15:00'))
            ->setStatus(RfqInvitation::STATUS_VIEWED);

        $quoteA1 = (new Quote($tenantA, $propertyA, 'Q-A-1'))
            ->setEstimate($invitationA1->getCreatedEstimate())
            ->setStatus(Quote::STATUS_ACCEPTED)
            ->setSentAt(new \DateTimeImmutable('2026-06-16 11:30:00'))
            ->setViewedAt(new \DateTimeImmutable('2026-06-16 11:45:00'))
            ->setAcceptedAt(new \DateTimeImmutable('2026-06-16 12:05:00'));

        $jobA1 = (new Job($tenantA, $propertyA))
            ->setQuote($quoteA1)
            ->setStartedAt(new \DateTimeImmutable('2026-06-16 14:00:00'))
            ->setCompletedAt(new \DateTimeImmutable('2026-06-16 15:30:00'))
            ->setStatus(Job::STATUS_COMPLETED);

        $tenantRepository = $this->createMock(TenantRepository::class);
        $tenantRepository->expects(self::once())
            ->method('findForVendorAnalytics')
            ->with(null)
            ->willReturn([$tenantA, $tenantB]);

        $invitationRepository = $this->createMock(RfqInvitationRepository::class);
        $invitationRepository->expects(self::once())
            ->method('findForVendorAnalyticsByTenantIds')
            ->with([$tenantA->getId(), $tenantB->getId()])
            ->willReturn([$invitationA1, $invitationA2, $invitationB1]);

        $quoteRepository = $this->createMock(QuoteRepository::class);
        $quoteRepository->expects(self::once())
            ->method('findForVendorAnalyticsByTenantIds')
            ->with([$tenantA->getId(), $tenantB->getId()])
            ->willReturn([$quoteA1]);

        $jobRepository = $this->createMock(JobRepository::class);
        $jobRepository->expects(self::once())
            ->method('findForVendorAnalyticsByTenantIds')
            ->with([$tenantA->getId(), $tenantB->getId()])
            ->willReturn([$jobA1]);

        $service = new RfqVendorAnalyticsService(
            $tenantRepository,
            $invitationRepository,
            $quoteRepository,
            $jobRepository,
        );

        $report = $service->buildReport(null);

        self::assertSame(2, $report['summary']['vendors']);
        self::assertSame(3, $report['summary']['invitationsSent']);
        self::assertSame(2, $report['summary']['invitationsViewed']);
        self::assertSame(1, $report['summary']['invitationsAccepted']);
        self::assertSame(1, $report['summary']['quotesCreated']);
        self::assertSame(1, $report['summary']['jobsCompleted']);
        self::assertSame(3, $report['summary']['firstResponseSamples']);
        self::assertEqualsWithDelta(35.0, $report['summary']['averageFirstResponseMinutes'], 0.0001);
        self::assertSame('Alpha Vendor', $report['vendors'][0]['tenant']->getName());
        self::assertSame(2, $report['vendors'][0]['invitationsSent']);
        self::assertSame(1, $report['vendors'][0]['quotesCreated']);
        self::assertSame(1, $report['vendors'][0]['jobsCompleted']);
        self::assertSame(1, $report['vendors'][1]['invitationsSent']);
        self::assertSame('Beta Vendor', $report['vendors'][1]['tenant']->getName());
        self::assertEqualsWithDelta(2 / 3, $report['summary']['openRate'], 0.0001);
        self::assertEqualsWithDelta(1 / 3, $report['summary']['acceptRate'], 0.0001);
        self::assertEqualsWithDelta(1.0, $report['summary']['quoteRate'], 0.0001);
        self::assertEqualsWithDelta(1.0, $report['summary']['completionRate'], 0.0001);
    }

    private function tenantWithId(int $id, string $name): Tenant
    {
        $tenant = (new Tenant($name))->setRfqVendorEnabled(true);
        $reflection = new \ReflectionProperty(Tenant::class, 'id');
        $reflection->setValue($tenant, $id);

        return $tenant;
    }
}
