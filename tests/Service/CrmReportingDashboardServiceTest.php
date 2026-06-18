<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Invoice;
use App\Entity\Property;
use App\Entity\Quote;
use App\Entity\RetentionOpportunity;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\CallSessionRepository;
use App\Repository\EstimateRepository;
use App\Repository\InvoiceRepository;
use App\Repository\JobRepository;
use App\Repository\QuoteRepository;
use App\Repository\RetentionOpportunityRepository;
use App\Repository\UserTenantMembershipRepository;
use App\Service\CrmReportingDashboardService;
use PHPUnit\Framework\TestCase;

final class CrmReportingDashboardServiceTest extends TestCase
{
    public function testBuildReportAggregatesRevenueCallAndThroughputMetrics(): void
    {
        $tenant = new Tenant('Tenant One');
        $technician = $this->userWithId(101, 'Taylor', 'Tech');
        $dispatcher = $this->userWithId(102, 'Drew', 'Dispatch');
        $technicianMembership = (new UserTenantMembership($technician, $tenant))
            ->setRoles([UserTenantMembership::ROLE_TECHNICIAN]);
        $dispatcherMembership = (new UserTenantMembership($dispatcher, $tenant))
            ->setRoles([UserTenantMembership::ROLE_DISPATCH]);

        $estimateRepository = $this->createMock(EstimateRepository::class);
        $estimateRepository->expects(self::once())
            ->method('countCreatedBetween')
            ->with($tenant, self::isInstanceOf(\DateTimeImmutable::class), self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(4);
        $estimateRepository->expects(self::once())
            ->method('countConvertedToQuoteBetween')
            ->with($tenant, self::isInstanceOf(\DateTimeImmutable::class), self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(2);

        $quoteRepository = $this->createMock(QuoteRepository::class);
        $quoteRepository->expects(self::once())
            ->method('countSentBetweenForTenant')
            ->with($tenant, self::isInstanceOf(\DateTimeImmutable::class), self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(3);
        $quoteRepository->expects(self::once())
            ->method('countAcceptedBetweenForTenant')
            ->with($tenant, self::isInstanceOf(\DateTimeImmutable::class), self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(1);
        $quoteRepository->expects(self::once())
            ->method('summarizePipelineBetweenForTenant')
            ->with($tenant, self::isInstanceOf(\DateTimeImmutable::class), self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn([
                ['status' => Quote::STATUS_ACCEPTED, 'count' => 1, 'totalCents' => 40000],
                ['status' => Quote::STATUS_DECLINED, 'count' => 1, 'totalCents' => 10000],
                ['status' => Quote::STATUS_DRAFT, 'count' => 1, 'totalCents' => 5000],
            ]);

        $callSessionRepository = $this->createMock(CallSessionRepository::class);
        $callSessionRepository->expects(self::once())
            ->method('countBetween')
            ->with($tenant, self::isInstanceOf(\DateTimeImmutable::class), self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(7);
        $callSessionRepository->expects(self::once())
            ->method('countByPropertyBetween')
            ->with($tenant, self::isInstanceOf(\DateTimeImmutable::class), self::isInstanceOf(\DateTimeImmutable::class), 5)
            ->willReturn([
                ['propertyId' => 9, 'propertyLabel' => '10 Heat Street, Toronto, ON M1M1M1', 'callCount' => 5],
            ]);
        $callSessionRepository->expects(self::once())
            ->method('countByContactBetween')
            ->with($tenant, self::isInstanceOf(\DateTimeImmutable::class), self::isInstanceOf(\DateTimeImmutable::class), 5)
            ->willReturn([
                ['contactId' => 11, 'contactLabel' => 'Tenant Contact', 'callCount' => 4],
            ]);

        $jobRepository = $this->createMock(JobRepository::class);
        $jobRepository->expects(self::once())
            ->method('countCompletedBetweenForTenant')
            ->with($tenant, self::isInstanceOf(\DateTimeImmutable::class), self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(2);
        $jobRepository->expects(self::once())
            ->method('countAssignedBetweenForTenant')
            ->with($tenant, self::isInstanceOf(\DateTimeImmutable::class), self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(5);
        $jobRepository->expects(self::once())
            ->method('findCompletedByAssigneeBetween')
            ->with($tenant, self::isInstanceOf(\DateTimeImmutable::class), self::isInstanceOf(\DateTimeImmutable::class), 10)
            ->willReturn([
                ['userId' => $technician->getId(), 'userLabel' => 'Taylor Tech', 'jobCount' => 2],
                ['userId' => $dispatcher->getId(), 'userLabel' => 'Drew Dispatch', 'jobCount' => 1],
            ]);
        $jobRepository->expects(self::once())
            ->method('findAssignedByAssigneeBetween')
            ->with($tenant, self::isInstanceOf(\DateTimeImmutable::class), self::isInstanceOf(\DateTimeImmutable::class), 10)
            ->willReturn([
                ['userId' => $dispatcher->getId(), 'userLabel' => 'Drew Dispatch', 'jobCount' => 5],
                ['userId' => $technician->getId(), 'userLabel' => 'Taylor Tech', 'jobCount' => 1],
            ]);

        $membershipRepository = $this->createMock(UserTenantMembershipRepository::class);
        $membershipRepository->expects(self::once())
            ->method('findByTenantOrdered')
            ->with($tenant, 1, 500)
            ->willReturn([$technicianMembership, $dispatcherMembership]);

        $property1 = $this->propertyWithId($tenant, 201, '201 Dormant St');
        $property2 = $this->propertyWithId($tenant, 202, '202 Service Rd');
        $property3 = $this->propertyWithId($tenant, 203, '203 Call Ave');
        $property4 = $this->propertyWithId($tenant, 204, '204 Maintenance Blvd');
        $property5 = $this->propertyWithId($tenant, 205, '205 Replacement Ln');
        $property6 = $this->propertyWithId($tenant, 206, '206 Warranty Dr');
        $property7 = $this->propertyWithId($tenant, 207, '207 Invoice Way');
        $property8 = $this->propertyWithId($tenant, 208, '208 Invoice Way');

        $openOpportunities = [
            new RetentionOpportunity($tenant, $property1, RetentionOpportunity::TYPE_DORMANT_CUSTOMER, 'dormant-1', 'Dormant customer'),
            new RetentionOpportunity($tenant, $property2, RetentionOpportunity::TYPE_NO_RECENT_SERVICE, 'service-1', 'No recent service'),
            new RetentionOpportunity($tenant, $property3, RetentionOpportunity::TYPE_NO_RECENT_CALLS, 'calls-1', 'No recent calls'),
            new RetentionOpportunity($tenant, $property4, RetentionOpportunity::TYPE_MAINTENANCE_PLAN_MISSING, 'plan-1', 'Maintenance plan missing'),
            new RetentionOpportunity($tenant, $property5, RetentionOpportunity::TYPE_OLD_EQUIPMENT, 'replace-1', 'Old equipment'),
            new RetentionOpportunity($tenant, $property6, RetentionOpportunity::TYPE_WARRANTY_NEARING_EXPIRATION, 'warranty-1', 'Warranty nearing expiration'),
            new RetentionOpportunity($tenant, $property7, RetentionOpportunity::TYPE_OPEN_INVOICE, 'invoice-1', 'Open invoice'),
            new RetentionOpportunity($tenant, $property8, RetentionOpportunity::TYPE_OPEN_INVOICE, 'invoice-2', 'Open invoice'),
        ];
        $openOpportunities[6]->setDetectedAt(new \DateTimeImmutable('2026-06-16 11:00:00'));
        $openOpportunities[7]->setDetectedAt(new \DateTimeImmutable('2026-06-16 10:00:00'));

        $retentionOpportunityRepository = $this->createMock(RetentionOpportunityRepository::class);
        $retentionOpportunityRepository->expects(self::once())
            ->method('findOpenByTenant')
            ->with($tenant)
            ->willReturn($openOpportunities);

        $invoiceRepository = $this->createMock(InvoiceRepository::class);
        $invoiceRepository->expects(self::once())
            ->method('summarizeOpenBalancesByTenantAndPropertyIds')
            ->with(
                $tenant,
                self::callback(static fn (array $propertyIds): bool => [207, 208] === array_values(array_unique(array_map('intval', $propertyIds))))
            )
            ->willReturn([
                ['propertyId' => 207, 'invoiceCount' => 2, 'totalCents' => 15000],
                ['propertyId' => 208, 'invoiceCount' => 1, 'totalCents' => 10000],
            ]);

        $service = new CrmReportingDashboardService(
            $estimateRepository,
            $quoteRepository,
            $callSessionRepository,
            $invoiceRepository,
            $jobRepository,
            $retentionOpportunityRepository,
            $membershipRepository,
        );

        $report = $service->buildReport($tenant, 30, new \DateTimeImmutable('2026-06-16 12:00:00'));

        self::assertSame(30, $report['periodDays']);
        self::assertSame(4, $report['summary']['estimatesCreated']);
        self::assertSame(2, $report['summary']['estimatesConverted']);
        self::assertEqualsWithDelta(0.5, $report['summary']['leadToQuoteConversionRate'], 0.0001);
        self::assertSame(3, $report['summary']['quotesSent']);
        self::assertSame(1, $report['summary']['quotesAccepted']);
        self::assertEqualsWithDelta(1 / 3, $report['summary']['quoteAcceptanceRate'], 0.0001);
        self::assertSame(55000, $report['summary']['pipelineValueCents']);
        self::assertSame(5000, $report['summary']['openPipelineCents']);
        self::assertSame(40000, $report['summary']['wonPipelineCents']);
        self::assertSame(10000, $report['summary']['lostPipelineCents']);
        self::assertSame(7, $report['summary']['callsTotal']);
        self::assertSame(2, $report['summary']['jobsCompleted']);
        self::assertSame(5, $report['summary']['jobsAssigned']);

        self::assertSame('Accepted', $report['pipelineRows'][0]['label']);
        self::assertSame('Declined', $report['pipelineRows'][1]['label']);
        self::assertSame('Draft', $report['pipelineRows'][2]['label']);
        self::assertSame(1, $report['pipelineRows'][0]['count']);
        self::assertSame(10000, $report['pipelineRows'][1]['totalCents']);

        self::assertSame('10 Heat Street, Toronto, ON M1M1M1', $report['callVolumeByProperty'][0]['propertyLabel']);
        self::assertSame('Tenant Contact', $report['callVolumeByContact'][0]['contactLabel']);
        self::assertCount(1, $report['throughputByTechnician']);
        self::assertSame('Taylor Tech', $report['throughputByTechnician'][0]['displayName']);
        self::assertCount(1, $report['throughputByDispatcher']);
        self::assertSame('Drew Dispatch', $report['throughputByDispatcher'][0]['displayName']);

        self::assertCount(5, $report['revenueOpportunityCards']);
        self::assertSame('Dormant Customers', $report['revenueOpportunityCards'][0]['label']);
        self::assertSame(1, $report['revenueOpportunityCards'][0]['count']);
        self::assertSame('Maintenance Opportunities', $report['revenueOpportunityCards'][1]['label']);
        self::assertSame(3, $report['revenueOpportunityCards'][1]['count']);
        self::assertSame('Replacement Opportunities', $report['revenueOpportunityCards'][2]['label']);
        self::assertSame(1, $report['revenueOpportunityCards'][2]['count']);
        self::assertSame('Warranty Opportunities', $report['revenueOpportunityCards'][3]['label']);
        self::assertSame(1, $report['revenueOpportunityCards'][3]['count']);
        self::assertSame('Overdue Invoice Opportunities', $report['revenueOpportunityCards'][4]['label']);
        self::assertSame(2, $report['revenueOpportunityCards'][4]['count']);
        self::assertSame(25000, $report['revenueOpportunityCards'][4]['estimatedValueCents']);
        self::assertSame('207 Invoice Way, Toronto, ON M1M07', $report['revenueOpportunityCards'][4]['items'][0]['propertyLabel']);
    }

    private function userWithId(int $id, string $firstName, string $lastName): User
    {
        $user = (new User())
            ->setEmail(strtolower($firstName).'.'.strtolower($lastName).'@example.com')
            ->setPassword('unused')
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setRoles(['ROLE_USER']);
        $this->setId($user, $id);

        return $user;
    }

    private function setId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity, 'id');
        $reflection->setValue($entity, $id);
    }

    private function propertyWithId(Tenant $tenant, int $id, string $addressLine1): Property
    {
        $parts = explode(', ', $addressLine1, 2);
        $property = new Property(
            $tenant,
            $parts[0],
            'Toronto',
            'ON',
            sprintf('M1M%02d', $id % 100),
        );
        $this->setId($property, $id);

        return $property;
    }
}
