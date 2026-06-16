<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Job;
use App\Entity\Property;
use App\Entity\Task;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Service\AuditLogger;
use App\Service\JobFollowUpService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class JobFollowUpServiceTest extends TestCase
{
    public function testGeneratesFollowUpTasksForRecommendations(): void
    {
        $tenant = new Tenant('Tenant One');
        $property = new Property($tenant, '10 Test St', 'Toronto', 'ON', 'M1M1M1');
        $technician = (new User())->setEmail('tech@example.com')->setPassword('unused')->setRoles(['ROLE_USER']);
        $job = (new Job($tenant, $property))
            ->setTitle('Replace furnace')
            ->setAssignedTo($technician)
            ->setAssignedAt(new \DateTimeImmutable('2026-06-20 10:00:00'))
            ->setCompletedAt(new \DateTimeImmutable('2026-06-20 10:00:00'))
            ->setStatus(Job::STATUS_COMPLETED)
            ->setRecommendedRepairNotes('Seal duct connections.')
            ->setRecommendedReplacementNotes('Consider replacing aging furnace next season.');

        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository->expects(self::never())->method('findFollowUpsByJob');

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function (object $object) use (&$persisted): void {
                $persisted[] = $object;
            });
        $entityManager->expects(self::once())->method('flush');

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('log');

        $service = new JobFollowUpService($taskRepository, $entityManager, $auditLogger);
        $tasks = $service->generateForCompletedJob($job);

        self::assertCount(2, $tasks);
        self::assertCount(2, $persisted);
        self::assertSame('Generated 2 follow-up task(s).', $job->getFollowUpSummary());
        self::assertNotNull($job->getFollowUpGeneratedAt());
        self::assertSame(Task::KIND_FOLLOW_UP, $tasks[0]->getKind());
        self::assertSame(Task::STATUS_SCHEDULED, $tasks[0]->getStatus());
        self::assertSame($technician, $tasks[0]->getAssignedTo());
        self::assertSame('Repair follow-up', $tasks[0]->getTitle());
        self::assertSame('Replacement follow-up', $tasks[1]->getTitle());
    }

    public function testGeneratesDefaultServiceReminderWhenNoIssuesRemain(): void
    {
        $tenant = new Tenant('Tenant One');
        $property = new Property($tenant, '10 Test St', 'Toronto', 'ON', 'M1M1M1');
        $job = (new Job($tenant, $property))
            ->setTitle('Annual service')
            ->setCompletedAt(new \DateTimeImmutable('2026-06-20 10:00:00'))
            ->setStatus(Job::STATUS_COMPLETED);

        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository->expects(self::never())->method('findFollowUpsByJob');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('log');

        $service = new JobFollowUpService($taskRepository, $entityManager, $auditLogger);
        $tasks = $service->generateForCompletedJob($job);

        self::assertCount(1, $tasks);
        self::assertSame(Task::KIND_SERVICE_REMINDER, $tasks[0]->getKind());
        self::assertSame('Service reminder', $tasks[0]->getTitle());
        self::assertSame('Generated 1 follow-up task(s).', $job->getFollowUpSummary());
    }

    public function testGeneratesUnresolvedIssueFollowUpTaskWhenAnIssueRemains(): void
    {
        $tenant = new Tenant('Tenant One');
        $property = new Property($tenant, '10 Test St', 'Toronto', 'ON', 'M1M1M1');
        $job = (new Job($tenant, $property))
            ->setTitle('Annual service')
            ->setCompletedAt(new \DateTimeImmutable('2026-06-20 10:00:00'))
            ->setStatus(Job::STATUS_COMPLETED)
            ->setUnresolvedIssueNotes('Customer still needs a quote for the replacement motor.');

        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository->expects(self::never())->method('findFollowUpsByJob');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('log');

        $service = new JobFollowUpService($taskRepository, $entityManager, $auditLogger);
        $tasks = $service->generateForCompletedJob($job);

        self::assertCount(1, $tasks);
        self::assertSame(Task::KIND_FOLLOW_UP, $tasks[0]->getKind());
        self::assertSame('Unresolved issue follow-up', $tasks[0]->getTitle());
        self::assertSame('Customer still needs a quote for the replacement motor.', $tasks[0]->getDescription());
        self::assertSame('Generated 1 follow-up task(s).', $job->getFollowUpSummary());
    }

    public function testGeneratesExplicitServiceReminderWhenRequested(): void
    {
        $tenant = new Tenant('Tenant One');
        $property = new Property($tenant, '10 Test St', 'Toronto', 'ON', 'M1M1M1');
        $job = (new Job($tenant, $property))
            ->setTitle('Annual service')
            ->setCompletedAt(new \DateTimeImmutable('2026-06-20 10:00:00'))
            ->setStatus(Job::STATUS_COMPLETED)
            ->setServiceReminderAt(new \DateTimeImmutable('2026-09-20 09:30:00'))
            ->setServiceReminderNotes('Schedule the next seasonal maintenance visit.');

        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository->expects(self::never())->method('findFollowUpsByJob');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('log');

        $service = new JobFollowUpService($taskRepository, $entityManager, $auditLogger);
        $tasks = $service->generateForCompletedJob($job);

        self::assertCount(1, $tasks);
        self::assertSame(Task::KIND_SERVICE_REMINDER, $tasks[0]->getKind());
        self::assertSame('Service reminder', $tasks[0]->getTitle());
        self::assertSame('Schedule the next seasonal maintenance visit.', $tasks[0]->getDescription());
        self::assertSame('2026-09-20 09:30:00', $tasks[0]->getScheduledStartAt()?->format('Y-m-d H:i:s'));
        self::assertSame('Generated 1 follow-up task(s).', $job->getFollowUpSummary());
    }
}
