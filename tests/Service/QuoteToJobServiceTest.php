<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Job;
use App\Entity\Quote;
use App\Entity\Property;
use App\Entity\Tenant;
use App\Repository\JobRepository;
use App\Service\AuditLogger;
use App\Service\CommunicationTimelineProjector;
use App\Service\QuoteToJobService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class QuoteToJobServiceTest extends TestCase
{
    public function testCreateFromAcceptedQuotePersistsJobAndTimelineEntry(): void
    {
        $tenant = new Tenant('Tenant One');
        $property = new Property($tenant, '10 Test St', 'Toronto', 'ON', 'M1M1M1');
        $quote = (new Quote($tenant, $property, 'Q-1'))
            ->setStatus(Quote::STATUS_ACCEPTED)
            ->setAcceptedAt(new \DateTimeImmutable('2026-06-20 10:00:00'));

        $jobRepository = $this->createMock(JobRepository::class);
        $jobRepository->expects(self::once())
            ->method('findOneByQuote')
            ->with($quote)
            ->willReturn(null);

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $object) use (&$persisted): void {
                $persisted[] = $object;
            });
        $entityManager->expects(self::once())->method('flush');

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('log');

        $timelineProjector = $this->createMock(CommunicationTimelineProjector::class);
        $timelineProjector->expects(self::once())->method('recordJobEvent');

        $service = new QuoteToJobService($jobRepository, $entityManager, $auditLogger, $timelineProjector);
        $job = $service->createFromAcceptedQuote($quote);

        self::assertCount(1, $persisted);
        self::assertSame($job, $persisted[0]);
        self::assertSame($quote, $job->getQuote());
        self::assertSame('Work order for quote Q-1', $job->getTitle());
        self::assertSame($property, $job->getProperty());
    }

    public function testCreateFromAcceptedQuoteReturnsExistingJobWhenAlreadyCreated(): void
    {
        $tenant = new Tenant('Tenant One');
        $property = new Property($tenant, '10 Test St', 'Toronto', 'ON', 'M1M1M1');
        $quote = (new Quote($tenant, $property, 'Q-1'))->setStatus(Quote::STATUS_ACCEPTED);
        $existingJob = (new Job($tenant, $property))->setQuote($quote)->setTitle('Work order for quote Q-1');

        $jobRepository = $this->createMock(JobRepository::class);
        $jobRepository->expects(self::once())
            ->method('findOneByQuote')
            ->with($quote)
            ->willReturn($existingJob);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::never())->method('log');

        $timelineProjector = $this->createMock(CommunicationTimelineProjector::class);
        $timelineProjector->expects(self::never())->method('recordJobEvent');

        $service = new QuoteToJobService($jobRepository, $entityManager, $auditLogger, $timelineProjector);
        $job = $service->createFromAcceptedQuote($quote);

        self::assertSame($existingJob, $job);
    }
}
