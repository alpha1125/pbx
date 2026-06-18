<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CallSession;
use App\Entity\Property;
use App\Entity\Tenant;
use App\Entity\TelnyxEvent;
use App\Service\AuditLogger;
use App\Service\CallEventEngineService;
use App\Service\CommunicationTimelineProjector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CallEventEngineServiceTest extends TestCase
{
    private AuditLogger $auditLogger;
    private CommunicationTimelineProjector $timelineProjector;
    private CallEventEngineService $engine;

    protected function setUp(): void
    {
        $this->auditLogger = $this->createStub(AuditLogger::class);
        $this->timelineProjector = $this->createStub(CommunicationTimelineProjector::class);
        $this->engine = new CallEventEngineService($this->auditLogger, $this->timelineProjector);
    }

    public function testNormalizeMapsRawEventsToCanonicalCallEvents(): void
    {
        foreach ($this->normalizationProvider() as [$eventType, $payload, $expected]) {
            $normalized = $this->engine->normalize($eventType, $payload);

            self::assertIsArray($normalized, $eventType);
            self::assertSame($expected['normalizedEvent'], $normalized['normalizedEvent'], $eventType);
            self::assertSame($expected['callState'], $normalized['callState'], $eventType);
            self::assertSame($expected['bodyText'], $normalized['bodyText'], $eventType);
            self::assertSame($expected['auditAction'], $normalized['auditAction'], $eventType);
        }
    }

    public function testRecordWritesNormalizedAuditAndTimelineEntries(): void
    {
        $auditLogger = $this->createMock(AuditLogger::class);
        $timelineProjector = $this->createMock(CommunicationTimelineProjector::class);
        $engine = new CallEventEngineService($auditLogger, $timelineProjector);

        $tenant = new Tenant('Tenant One');
        $property = new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1');
        $session = (new CallSession('session-1'))
            ->setTenant($tenant)
            ->setProperty($property)
            ->setStatus('answered')
            ->setCallState(CallSession::CALL_STATE_CONNECTED);
        $this->forceId($session, 88);

        $event = new TelnyxEvent('event-1', 'call.hangup', [], 'control-1');
        $eventPayload = [
            'hangup_source' => 'callee',
            'hangup_cause' => 'normal_clearing',
        ];

        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                $tenant,
                'call_session',
                '88',
                'call.event.customer_hangup',
                null,
                self::callback(static fn (?array $afterData): bool => is_array($afterData)
                    && 'answered' === ($afterData['status'] ?? null)
                    && CallSession::CALL_STATE_CONNECTED === ($afterData['callState'] ?? null)),
                self::callback(static fn (?array $metadata): bool => is_array($metadata)
                    && 'customer_hangup' === ($metadata['normalizedEvent'] ?? null)
                    && 'call.hangup' === ($metadata['eventType'] ?? null)
                    && 'event-1' === ($metadata['providerEventId'] ?? null)),
            );

        $timelineProjector->expects(self::once())
            ->method('recordCallEvent')
            ->with(
                self::identicalTo($session),
                'call.event.customer_hangup',
                'Customer ended the call.',
                self::callback(static fn (?array $metadata): bool => is_array($metadata)
                    && 'customer_hangup' === ($metadata['normalizedEvent'] ?? null)
                    && 'normal_clearing' === ($metadata['hangupCause'] ?? null)),
            );

        $timelineProjector->expects(self::once())
            ->method('syncProperty')
            ->with(self::identicalTo($property));

        $engine->record($event, $session, null, $eventPayload, new \DateTimeImmutable('2026-06-13T10:05:01+00:00'));
    }

    public static function normalizationProvider(): iterable
    {
        yield 'initiated' => [
            'call.initiated',
            [],
            [
                'normalizedEvent' => 'initiated',
                'callState' => CallSession::CALL_STATE_INITIATED,
                'bodyText' => 'Call initiated.',
                'auditAction' => 'call.event.initiated',
            ],
        ];

        yield 'ringing' => [
            'call.ringing',
            [],
            [
                'normalizedEvent' => 'ringing',
                'callState' => CallSession::CALL_STATE_RINGING,
                'bodyText' => 'Call is ringing.',
                'auditAction' => 'call.event.ringing',
            ],
        ];

        yield 'answered' => [
            'call.answered',
            [],
            [
                'normalizedEvent' => 'answered',
                'callState' => CallSession::CALL_STATE_CONNECTED,
                'bodyText' => 'Call answered.',
                'auditAction' => 'call.event.answered',
            ],
        ];

        yield 'failed' => [
            'call.failed',
            [],
            [
                'normalizedEvent' => 'failed',
                'callState' => CallSession::CALL_STATE_FAILED,
                'bodyText' => 'Call failed.',
                'auditAction' => 'call.event.failed',
            ],
        ];

        yield 'completed' => [
            'call.completed',
            [],
            [
                'normalizedEvent' => 'completed',
                'callState' => CallSession::CALL_STATE_COMPLETED,
                'bodyText' => 'Call completed.',
                'auditAction' => 'call.event.completed',
            ],
        ];

        yield 'csr hangup' => [
            'call.hangup',
            ['hangup_source' => 'caller'],
            [
                'normalizedEvent' => 'csr_hangup',
                'callState' => CallSession::CALL_STATE_COMPLETED,
                'bodyText' => 'CSR ended the call.',
                'auditAction' => 'call.event.csr_hangup',
            ],
        ];

        yield 'customer hangup' => [
            'call.hangup',
            ['hangup_source' => 'callee'],
            [
                'normalizedEvent' => 'customer_hangup',
                'callState' => CallSession::CALL_STATE_COMPLETED,
                'bodyText' => 'Customer ended the call.',
                'auditAction' => 'call.event.customer_hangup',
            ],
        ];
    }

    private function forceId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity, 'id');
        $reflection->setValue($entity, $id);
    }
}
