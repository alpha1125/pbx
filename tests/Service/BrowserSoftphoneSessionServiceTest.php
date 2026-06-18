<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AuditLog;
use App\Entity\BrowserSoftphoneSession;
use App\Entity\CallSession;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\BrowserSoftphoneSessionRepository;
use App\Service\AuditLogger;
use App\Service\BrowserSoftphoneSessionService;
use App\Service\CommunicationTimelineProjector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BrowserSoftphoneSessionServiceTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private BrowserSoftphoneSessionRepository $sessions;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->sessions = static::getContainer()->get(BrowserSoftphoneSessionRepository::class);
        $this->truncateDatabase();
        $this->entityManager->clear();
    }

    public function testAllocateCreatesBrowserSessionForBrowserCall(): void
    {
        $tenant = new Tenant('Tenant One');
        $user = (new User())
            ->setEmail('csr@example.com')
            ->setPassword('unused')
            ->setDisplayName('CSR Agent');
        $session = (new CallSession('browser-session-1'))
            ->setTenant($tenant)
            ->setCsrUser($user)
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED);

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $service = $this->service();
        $browserSession = $service->allocate($session, $user);

        self::assertNotNull($browserSession->getId());
        self::assertSame($tenant, $browserSession->getTenant());
        self::assertSame($user, $browserSession->getUser());
        self::assertSame($session, $browserSession->getCallSession());
        self::assertSame(BrowserSoftphoneSession::STATUS_ALLOCATED, $browserSession->getStatus());
        self::assertNotSame('', $browserSession->getSessionToken());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $browserSession->getSessionToken());

        $stored = $this->sessions->findOneByCallSession($session);
        self::assertInstanceOf(BrowserSoftphoneSession::class, $stored);
        self::assertSame($browserSession->getId(), $stored->getId());
    }

    public function testAllocateRejectsBridgeCallSessions(): void
    {
        $tenant = new Tenant('Tenant Three');
        $user = (new User())
            ->setEmail('csr3@example.com')
            ->setPassword('unused')
            ->setDisplayName('CSR Three');
        $session = (new CallSession('bridge-session-1'))
            ->setTenant($tenant)
            ->setCsrUser($user)
            ->setCallMode(CallSession::CALL_MODE_BRIDGE)
            ->setCallState(CallSession::CALL_STATE_INITIATED);

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Browser softphone sessions can only be allocated for browser calls.');
        $this->service()->allocate($session, $user);
    }

    public function testRecordConnectionEventPersistsStateAndMarksReady(): void
    {
        $tenant = new Tenant('Tenant Four');
        $user = (new User())
            ->setEmail('csr4@example.com')
            ->setPassword('unused')
            ->setDisplayName('CSR Four');
        $session = (new CallSession('browser-session-4'))
            ->setTenant($tenant)
            ->setCsrUser($user)
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED);

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $browserSession = $this->service()->allocate($session, $user);
        $updated = $this->service()->recordConnectionEvent(
            $browserSession,
            BrowserSoftphoneSession::CONNECTION_STATE_READY,
            null,
            null,
            ['sdk' => 'telnyx'],
        );

        self::assertSame(BrowserSoftphoneSession::STATUS_ACTIVE, $updated->getStatus());
        self::assertSame(BrowserSoftphoneSession::CONNECTION_STATE_READY, $updated->getConnectionState());
        self::assertSame(['sdk' => 'telnyx'], $updated->getConnectionMeta());
        self::assertNotNull($updated->getConnectionReadyAt());
    }

    public function testRecordCallEventPersistsLifecycleAndBindsCallId(): void
    {
        $tenant = new Tenant('Tenant Five');
        $user = (new User())
            ->setEmail('csr5@example.com')
            ->setPassword('unused')
            ->setDisplayName('CSR Five');
        $session = (new CallSession('browser-session-5'))
            ->setTenant($tenant)
            ->setCsrUser($user)
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setClientPhoneNumber('+14165550123');

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $browserSession = $this->service()->allocate($session, $user);
        $updated = $this->service()->recordCallEvent(
            $browserSession,
            'call.active',
            'browser-call-id-123',
            '+14165550123',
            null,
            null,
            ['sdk' => 'telnyx'],
        );

        self::assertSame('browser-call-id-123', $updated->getCallId());
        self::assertSame('connected', $updated->getCallState());
        self::assertSame(BrowserSoftphoneSession::STATUS_ACTIVE, $updated->getStatus());
        self::assertSame('browser-call-id-123', $updated->getCallSession()->getBrowserCallId());
        self::assertSame(CallSession::CALL_STATE_CONNECTED, $updated->getCallSession()->getCallState());
        self::assertNotNull($updated->getCallAnsweredAt());
    }

    public function testRecordCallEventRejectsMismatchedCallIdAndDestination(): void
    {
        $tenant = new Tenant('Tenant Six');
        $user = (new User())
            ->setEmail('csr6@example.com')
            ->setPassword('unused')
            ->setDisplayName('CSR Six');
        $session = (new CallSession('browser-session-6'))
            ->setTenant($tenant)
            ->setCsrUser($user)
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setClientPhoneNumber('+14165550123');

        $browserSession = new BrowserSoftphoneSession($session, $tenant, $user, 'session-token-6');
        $service = $this->statelessService();
        $service->recordCallEvent($browserSession, 'call.requesting', 'browser-call-id-1', '+14165550123');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Browser call identifier does not match the active call session.');
        $service->recordCallEvent($browserSession, 'call.ringing', 'browser-call-id-2', '+14165550123');
    }

    private function service(): BrowserSoftphoneSessionService
    {
        $auditLogger = $this->createStub(AuditLogger::class);
        $auditLogger->method('log')->willReturnCallback(
            static fn (...$args): AuditLog => new AuditLog((string) $args[1], (string) $args[2], (string) $args[3]),
        );
        $timelineProjector = $this->createStub(CommunicationTimelineProjector::class);

        return new BrowserSoftphoneSessionService(
            $this->sessions,
            $this->entityManager,
            $auditLogger,
            $timelineProjector,
        );
    }

    private function statelessService(): BrowserSoftphoneSessionService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (): void {});
        $entityManager->method('flush')->willReturnCallback(static function (): void {});

        $auditLogger = $this->createStub(AuditLogger::class);
        $auditLogger->method('log')->willReturnCallback(
            static fn (...$args): AuditLog => new AuditLog((string) $args[1], (string) $args[2], (string) $args[3]),
        );
        $timelineProjector = $this->createStub(CommunicationTimelineProjector::class);
        $sessions = $this->sessions;

        return new BrowserSoftphoneSessionService(
            $sessions,
            $entityManager,
            $auditLogger,
            $timelineProjector,
        );
    }

    private function truncateDatabase(): void
    {
        $connection = $this->entityManager->getConnection();
        $platformClass = $connection->getDatabasePlatform()::class;
        if (!str_contains($platformClass, 'PostgreSQL')) {
            return;
        }

        $tables = array_filter(
            $connection->createSchemaManager()->listTableNames(),
            static fn (string $table): bool => 'doctrine_migration_versions' !== $table,
        );

        if ([] === $tables) {
            return;
        }

        $connection->executeStatement('TRUNCATE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
    }
}
