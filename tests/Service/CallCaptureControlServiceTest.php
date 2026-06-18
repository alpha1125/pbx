<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Capture\CapturePolicy;
use App\Entity\AuditLog;
use App\Entity\CallLeg;
use App\Entity\CallSession;
use App\Entity\Tenant;
use App\Repository\CallActionRepository;
use App\Repository\CallRecordingRepository;
use App\Repository\TranscriptionJobRepository;
use App\Service\AuditLogger;
use App\Service\CallCaptureControlService;
use App\Service\TelnyxCallControlService;
use App\Transcription\Provider\TelnyxTranscriptionConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CallCaptureControlServiceTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateDatabase();
        $this->entityManager->clear();
    }

    public function testConsentMessageMovesRecordingStateThroughPlayback(): void
    {
        $session = $this->sessionWithId(55);
        $leg = $this->legWithControlId($session, 'leg-1', 'control-1');
        $recorder = new class {
            /** @var list<array{string,string,array<string, mixed>}> */
            public array $requests = [];
        };
        $service = $this->service($recorder);
        $sessionId = $session->getId();
        self::assertNotNull($sessionId);

        $service->playConsentMessage($session, $leg, 'This call will be recorded for transcription and quality purposes.', 'capture-55');

        self::assertSame(CallSession::RECORDING_STATE_INACTIVE, $session->getRecordingState());
        self::assertCount(1, $recorder->requests);
        self::assertStringEndsWith('/calls/control-1/actions/speak', $recorder->requests[0][1]);
        self::assertSame('This call will be recorded for transcription and quality purposes.', $recorder->requests[0][2]['payload']);
        self::assertSame('capture-55:'.$sessionId.':consent', $recorder->requests[0][2]['command_id']);
    }

    public function testRecordingLifecycleUsesSharedStateMachine(): void
    {
        $session = $this->sessionWithId(56);
        $leg = $this->legWithControlId($session, 'leg-2', 'control-2');
        $recorder = new class {
            /** @var list<array{string,string,array<string, mixed>}> */
            public array $requests = [];
        };
        $service = $this->service($recorder);
        $sessionId = $session->getId();
        self::assertNotNull($sessionId);

        $service->startRecording($session, $leg, new CapturePolicy(true, false), 'capture-56');
        self::assertSame(CallSession::RECORDING_STATE_ACTIVE, $session->getRecordingState());

        $service->stopRecording($session, $leg, 'capture-56');
        self::assertSame(CallSession::RECORDING_STATE_STOPPED, $session->getRecordingState());
        self::assertCount(2, $recorder->requests);
        self::assertStringEndsWith('/calls/control-2/actions/record_start', $recorder->requests[0][1]);
        self::assertSame('capture-56:'.$sessionId.':record-start', $recorder->requests[0][2]['command_id']);
        self::assertStringEndsWith('/calls/control-2/actions/record_stop', $recorder->requests[1][1]);
        self::assertSame('capture-56:'.$sessionId.':record-stop', $recorder->requests[1][2]['command_id']);
    }

    public function testTranscriptionLifecycleUsesSharedStateMachine(): void
    {
        $session = $this->sessionWithId(57);
        $leg = $this->legWithControlId($session, 'leg-3', 'control-3');
        $recorder = new class {
            /** @var list<array{string,string,array<string, mixed>}> */
            public array $requests = [];
        };
        $service = $this->service($recorder);
        $sessionId = $session->getId();
        self::assertNotNull($sessionId);

        $service->startTranscription($session, $leg, new CapturePolicy(false, true), 'capture-57');
        self::assertSame(CallSession::TRANSCRIPTION_STATE_ACTIVE, $session->getTranscriptionState());

        $service->stopTranscription($session, $leg, 'capture-57');
        self::assertSame(CallSession::TRANSCRIPTION_STATE_STOPPED, $session->getTranscriptionState());
        self::assertCount(2, $recorder->requests);
        self::assertStringEndsWith('/calls/control-3/actions/transcription_start', $recorder->requests[0][1]);
        self::assertSame('capture-57:'.$sessionId.':transcription-start', $recorder->requests[0][2]['command_id']);
        self::assertStringEndsWith('/calls/control-3/actions/transcription_stop', $recorder->requests[1][1]);
        self::assertSame('capture-57:'.$sessionId.':transcription-stop', $recorder->requests[1][2]['command_id']);
    }

    private function service(object $recorder): CallCaptureControlService
    {
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use ($recorder): MockResponse {
            $recorder->requests[] = [$method, $url, json_decode($options['body'], true)];

            return new MockResponse('{}', ['http_code' => 200]);
        });
        $callControl = new TelnyxCallControlService($httpClient, new NullLogger(), 'test-key');

        $auditLogger = $this->createStub(AuditLogger::class);
        $auditLogger->method('log')->willReturnCallback(
            static fn (...$args): AuditLog => new AuditLog((string) $args[1], (string) $args[2], (string) $args[3]),
        );

        $config = new TelnyxTranscriptionConfiguration(true, 'gpt-4o-mini-transcribe', 'en', 'both', 'telnyx', false, false);

        return new CallCaptureControlService(
            $callControl,
            static::getContainer()->get(CallActionRepository::class),
            static::getContainer()->get(CallRecordingRepository::class),
            static::getContainer()->get(TranscriptionJobRepository::class),
            $this->entityManager,
            $auditLogger,
            new NullLogger(),
            'wav',
            'dual',
            $config,
        );
    }

    private function sessionWithId(int $id): CallSession
    {
        $tenant = new Tenant('Tenant '.$id);
        $session = new CallSession('session-'.$id);
        $session->setTenant($tenant);
        $this->entityManager->persist($tenant);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function legWithControlId(CallSession $session, string $providerLegId, string $callControlId): CallLeg
    {
        $leg = new CallLeg($session, $providerLegId);
        $leg->setCallControlId($callControlId);
        $this->entityManager->persist($leg);
        $this->entityManager->flush();

        return $leg;
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
