<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CallLeg;
use App\Entity\CallSession;
use App\Entity\TelnyxEvent;
use App\Repository\CallLegRepository;
use App\Repository\CallSessionRepository;
use App\Service\ClientStateService;
use App\Service\CallEventEngineService;
use App\Service\TelnyxCallProjectionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TelnyxCallProjectionServiceTest extends TestCase
{
    private CallSessionRepository&MockObject $sessionRepository;
    private CallLegRepository&MockObject $legRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private CallEventEngineService&MockObject $callEventEngine;
    private TelnyxCallProjectionService $projection;

    protected function setUp(): void
    {
        $this->sessionRepository = $this->createMock(CallSessionRepository::class);
        $this->legRepository = $this->createMock(CallLegRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->callEventEngine = $this->createMock(CallEventEngineService::class);
        $this->projection = new TelnyxCallProjectionService(
            $this->sessionRepository,
            $this->legRepository,
            $this->entityManager,
            new ClientStateService(),
            $this->createStub(\App\Service\CommunicationTimelineProjector::class),
            $this->callEventEngine,
        );
    }

    public function testInitiatedCreatesAndLinksSessionAndLeg(): void
    {
        $event = $this->event('event-1', 'call.initiated');
        $persisted = [];
        $this->sessionRepository->expects(self::once())
            ->method('findOneByProviderSessionId')
            ->willReturn(null);
        $this->legRepository->expects(self::once())
            ->method('findOneByProviderLegId')
            ->willReturn(null);
        $this->entityManager->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $this->entityManager->expects(self::once())->method('flush');
        $this->callEventEngine->expects(self::once())->method('record');

        $this->projection->project($event, $this->data([
            'direction' => 'incoming',
            'from' => '+14165550100',
            'to' => '+12892079888',
            'start_time' => '2026-06-13T10:00:00+00:00',
        ], '2026-06-13T10:00:01+00:00'));

        self::assertCount(2, $persisted);
        self::assertInstanceOf(CallSession::class, $persisted[0]);
        self::assertInstanceOf(CallLeg::class, $persisted[1]);
        self::assertSame($persisted[0], $event->getCallSession());
        self::assertSame($persisted[1], $event->getCallLeg());
        self::assertSame('initiated', $persisted[0]->getStatus());
        self::assertSame(CallSession::CALL_STATE_INITIATED, $persisted[0]->getCallState());
        self::assertSame('+14165550100', $persisted[0]->getInboundFrom());
        self::assertSame('+12892079888', $persisted[0]->getInboundTo());
        self::assertSame('2026-06-13T10:00:00+00:00', $persisted[0]->getStartedAt()?->format(DATE_ATOM));
    }

    public function testAnsweredUpdatesExistingEntities(): void
    {
        [$session, $leg] = $this->existingCall();
        $this->expectExistingCall($session, $leg);

        $this->projection->project(
            $this->event('event-2', 'call.answered'),
            $this->data([], '2026-06-13T10:01:00+00:00'),
        );

        self::assertSame('answered', $session->getStatus());
        self::assertSame('answered', $leg->getStatus());
        self::assertSame(CallSession::CALL_STATE_CONNECTED, $session->getCallState());
        self::assertSame('2026-06-13T10:01:00+00:00', $session->getAnsweredAt()?->format(DATE_ATOM));
        self::assertSame('2026-06-13T10:01:00+00:00', $leg->getAnsweredAt()?->format(DATE_ATOM));
    }

    public function testLateEventsDoNotRegressBridgedState(): void
    {
        [$session, $leg] = $this->existingCall();
        $session->setStatus('bridged');
        $session->setCallState(CallSession::CALL_STATE_CONNECTED);
        $leg->setStatus('bridged');
        $this->expectExistingCall($session, $leg);

        $this->projection->project(
            $this->event('event-3', 'call.answered'),
            $this->data([], '2026-06-13T09:59:00+00:00'),
        );

        self::assertSame('bridged', $session->getStatus());
        self::assertSame('bridged', $leg->getStatus());
        self::assertSame(CallSession::CALL_STATE_CONNECTED, $session->getCallState());
    }

    public function testHangupCompletesLastActiveLegAndSession(): void
    {
        [$session, $leg] = $this->existingCall();
        $this->expectExistingCall($session, $leg);
        $this->legRepository->expects(self::once())->method('hasOtherActiveLegs')->willReturn(false);

        $this->projection->project(
            $this->event('event-4', 'call.hangup'),
            $this->data([
                'end_time' => '2026-06-13T10:05:00+00:00',
                'hangup_cause' => 'normal_clearing',
                'hangup_source' => 'caller',
                'sip_hangup_cause' => '200',
            ], '2026-06-13T10:05:01+00:00'),
        );

        self::assertSame('completed', $leg->getStatus());
        self::assertSame('completed', $session->getStatus());
        self::assertSame(CallSession::CALL_STATE_COMPLETED, $session->getCallState());
        self::assertSame('normal_clearing', $leg->getHangupCause());
        self::assertSame('caller', $leg->getHangupSource());
        self::assertSame('200', $leg->getSipHangupCause());
        self::assertSame('2026-06-13T10:05:00+00:00', $leg->getEndedAt()?->format(DATE_ATOM));
        self::assertSame('2026-06-13T10:05:00+00:00', $session->getEndedAt()?->format(DATE_ATOM));
    }

    public function testHangupDoesNotCompleteSessionWithAnotherActiveLeg(): void
    {
        [$session, $leg] = $this->existingCall();
        $session->setStatus('bridged');
        $session->setCallState(CallSession::CALL_STATE_CONNECTED);
        $this->expectExistingCall($session, $leg);
        $this->legRepository->expects(self::once())->method('hasOtherActiveLegs')->willReturn(true);

        $this->projection->project(
            $this->event('event-5', 'call.hangup'),
            $this->data([], '2026-06-13T10:06:00+00:00'),
        );

        self::assertSame('completed', $leg->getStatus());
        self::assertSame('bridged', $session->getStatus());
        self::assertSame(CallSession::CALL_STATE_CONNECTED, $session->getCallState());
        self::assertNull($session->getEndedAt());
    }

    public function testUnknownEventOnlyAdvancesLastEventTime(): void
    {
        [$session, $leg] = $this->existingCall();
        $session->setStatus('answered');
        $session->setCallState(CallSession::CALL_STATE_CONNECTED);
        $leg->setStatus('answered');
        $this->expectExistingCall($session, $leg);

        $this->projection->project(
            $this->event('event-6', 'call.speak.ended'),
            $this->data([], '2026-06-13T10:07:00+00:00'),
        );

        self::assertSame('answered', $session->getStatus());
        self::assertSame('answered', $leg->getStatus());
        self::assertSame(CallSession::CALL_STATE_CONNECTED, $session->getCallState());
        self::assertSame('2026-06-13T10:07:00+00:00', $session->getLastEventAt()?->format(DATE_ATOM));
    }

    public function testRingingTransitionsCallStateToRinging(): void
    {
        [$session, $leg] = $this->existingCall();
        $this->expectExistingCall($session, $leg);

        $this->projection->project(
            $this->event('event-9', 'call.ringing'),
            $this->data([], '2026-06-13T10:00:30+00:00'),
        );

        self::assertSame('ringing', $session->getStatus());
        self::assertSame(CallSession::CALL_STATE_RINGING, $session->getCallState());
        self::assertSame('ringing', $leg->getStatus());
    }

    public function testFailedTransitionsCallStateToFailed(): void
    {
        [$session, $leg] = $this->existingCall();
        $this->expectExistingCall($session, $leg);

        $this->projection->project(
            $this->event('event-10', 'call.failed'),
            $this->data(['hangup_source' => 'callee', 'hangup_cause' => 'busy'], '2026-06-13T10:01:30+00:00'),
        );

        self::assertSame('failed', $session->getStatus());
        self::assertSame(CallSession::CALL_STATE_FAILED, $session->getCallState());
        self::assertSame('failed', $leg->getStatus());
    }

    public function testCompletedTransitionsCallStateToCompleted(): void
    {
        [$session, $leg] = $this->existingCall();
        $this->expectExistingCall($session, $leg);

        $this->projection->project(
            $this->event('event-11', 'call.completed'),
            $this->data(['end_time' => '2026-06-13T10:02:30+00:00'], '2026-06-13T10:02:31+00:00'),
        );

        self::assertSame('completed', $session->getStatus());
        self::assertSame(CallSession::CALL_STATE_COMPLETED, $session->getCallState());
        self::assertSame('completed', $leg->getStatus());
    }

    public function testMalformedDatesFallBackWithoutBreakingProjection(): void
    {
        [$session, $leg] = $this->existingCall();
        $this->expectExistingCall($session, $leg);

        $before = new \DateTimeImmutable('-2 seconds');
        $this->projection->project(
            $this->event('event-7', 'call.initiated'),
            $this->data(['start_time' => 'not-a-date'], 'also-not-a-date'),
        );
        $after = new \DateTimeImmutable('+2 seconds');

        self::assertNotNull($session->getStartedAt());
        self::assertGreaterThanOrEqual($before, $session->getStartedAt());
        self::assertLessThanOrEqual($after, $session->getStartedAt());
    }

    public function testCostEventStoresBilledDurationAndLinksForwardedSessionToInboundSession(): void
    {
        $inboundSession = new CallSession('inbound-session');
        $outboundSession = new CallSession('session-1');
        $leg = new CallLeg($outboundSession, 'leg-1');
        $this->callEventEngine->expects(self::once())->method('record');
        $this->sessionRepository->expects(self::exactly(2))
            ->method('findOneByProviderSessionId')
            ->willReturnMap([
                ['session-1', $outboundSession],
                ['inbound-session', $inboundSession],
            ]);
        $this->legRepository->expects(self::once())
            ->method('findOneByProviderLegId')
            ->willReturn($leg);
        $this->entityManager->expects(self::once())->method('flush');

        $this->projection->project(
            $this->event('event-8', 'call.cost'),
            $this->data([
                'billed_duration_secs' => 120,
                'client_state' => base64_encode(json_encode([
                    'inbound_call_session_id' => 'inbound-session',
                ], JSON_THROW_ON_ERROR)),
            ], '2026-06-13T10:08:00+00:00'),
        );

        self::assertSame(120, $leg->getBilledDurationSeconds());
        self::assertSame($inboundSession, $outboundSession->getParentCallSession());
    }

    /** @return array{CallSession, CallLeg} */
    private function existingCall(): array
    {
        $session = new CallSession('session-1');

        return [$session, new CallLeg($session, 'leg-1')];
    }

    private function expectExistingCall(CallSession $session, CallLeg $leg): void
    {
        $this->sessionRepository->expects(self::once())
            ->method('findOneByProviderSessionId')
            ->willReturn($session);
        $this->legRepository->expects(self::once())
            ->method('findOneByProviderLegId')
            ->willReturn($leg);
        $this->entityManager->expects(self::once())->method('flush');
        $this->callEventEngine->expects(self::once())->method('record');
    }

    private function event(string $id, string $type): TelnyxEvent
    {
        return new TelnyxEvent($id, $type, [], 'control-1');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function data(array $overrides, string $occurredAt): array
    {
        return [
            'occurred_at' => $occurredAt,
            'payload' => $overrides + [
                'call_session_id' => 'session-1',
                'call_leg_id' => 'leg-1',
                'call_control_id' => 'control-1',
                'connection_id' => 'connection-1',
            ],
        ];
    }
}
