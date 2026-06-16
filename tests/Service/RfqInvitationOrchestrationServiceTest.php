<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Rfq;
use App\Entity\RfqInvitation;
use App\Entity\Tenant;
use App\Repository\RfqInvitationRepository;
use App\Service\AuditLogger;
use App\Service\RfqInvitationOrchestrationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class RfqInvitationOrchestrationServiceTest extends TestCase
{
    public function testCreateInvitationsInitializesLifecycleMetadata(): void
    {
        $rfq = (new Rfq('100 Intake Street', 'Toronto', 'ON', 'M5V2T6'))->setCountry('CA');
        $tenant = (new Tenant('Vendor One'))->setRfqVendorEnabled(true);
        $now = new \DateTimeImmutable('2026-06-20 10:00:00');

        $repository = $this->createMock(RfqInvitationRepository::class);
        $repository->expects(self::once())
            ->method('findOneByTenantAndRfq')
            ->with($tenant, $rfq)
            ->willReturn(null);

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $func) => $func());
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $object) use (&$persisted): void {
                $persisted[] = $object;
            });
        $entityManager->expects(self::exactly(2))->method('flush');

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('log');

        $service = new RfqInvitationOrchestrationService($entityManager, $repository, $auditLogger);
        $created = $service->createInvitationsForRfq(
            $rfq,
            [$tenant],
            new \DateInterval('P7D'),
            new \DateInterval('P3D'),
            'Reminder in three days.',
            $now,
        );

        self::assertCount(1, $created);
        self::assertSame($persisted[0], $created[0]);
        self::assertSame(RfqInvitation::STATUS_SENT, $created[0]->getStatus());
        self::assertSame('2026-06-20 10:00:00', $created[0]->getInvitedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-27 10:00:00', $created[0]->getExpiresAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-23 10:00:00', $created[0]->getReminderAt()?->format('Y-m-d H:i:s'));
        self::assertSame('Reminder in three days.', $created[0]->getReminderNotes());
    }

    public function testCreateInvitationsSkipsExistingInvitations(): void
    {
        $rfq = new Rfq('100 Intake Street', 'Toronto', 'ON', 'M5V2T6');
        $tenant = (new Tenant('Vendor One'))->setRfqVendorEnabled(true);
        $existing = new RfqInvitation($tenant, $rfq);

        $repository = $this->createMock(RfqInvitationRepository::class);
        $repository->expects(self::once())
            ->method('findOneByTenantAndRfq')
            ->with($tenant, $rfq)
            ->willReturn($existing);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $func) => $func());
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::never())->method('log');

        $service = new RfqInvitationOrchestrationService($entityManager, $repository, $auditLogger);
        $created = $service->createInvitationsForRfq($rfq, [$tenant]);

        self::assertSame([], $created);
    }

    public function testScheduleViewedAndExpiryTransitionsUpdateLifecycleState(): void
    {
        $rfq = new Rfq('100 Intake Street', 'Toronto', 'ON', 'M5V2T6');
        $tenant = (new Tenant('Vendor One'))->setRfqVendorEnabled(true);
        $invitation = new RfqInvitation($tenant, $rfq);
        $invitation->setExpiresAt(new \DateTimeImmutable('2026-06-19 10:00:00'));

        $repository = $this->createMock(RfqInvitationRepository::class);
        $repository->expects(self::once())
            ->method('findDueForExpiry')
            ->with(self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn([$invitation]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $func) => $func());
        $entityManager->expects(self::exactly(3))->method('flush');
        $entityManager->expects(self::exactly(2))->method('persist');

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::exactly(3))->method('log');

        $service = new RfqInvitationOrchestrationService($entityManager, $repository, $auditLogger);
        $service->scheduleReminder($invitation, new \DateTimeImmutable('2026-06-21 09:30:00'), 'Reminder notes.');
        $service->markViewed($invitation, new \DateTimeImmutable('2026-06-20 11:15:00'));
        $expired = $service->expireDueInvitations(new \DateTimeImmutable('2026-06-20 12:00:00'));

        self::assertSame(RfqInvitation::STATUS_EXPIRED, $invitation->getStatus());
        self::assertSame('2026-06-20 11:15:00', $invitation->getViewedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-21 09:30:00', $invitation->getReminderAt()?->format('Y-m-d H:i:s'));
        self::assertSame('Reminder notes.', $invitation->getReminderNotes());
        self::assertCount(1, $expired);
        self::assertSame(RfqInvitation::STATUS_EXPIRED, $expired[0]->getStatus());
        self::assertSame('2026-06-20 12:00:00', $expired[0]->getExpiredAt()?->format('Y-m-d H:i:s'));
    }
}
