<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallLeg;
use App\Entity\CallSession;
use App\Entity\TelnyxEvent;
use App\Repository\CallLegRepository;
use App\Repository\CallSessionRepository;
use Doctrine\ORM\EntityManagerInterface;

class TelnyxCallProjectionService
{
    /** @var array<string, int> */
    private const array STATUS_RANK = [
        'initiated' => 10,
        'answered' => 20,
        'bridging' => 30,
        'bridged' => 40,
        'failed' => 50,
        'completed' => 60,
    ];

    public function __construct(
        private readonly CallSessionRepository $sessionRepository,
        private readonly CallLegRepository $legRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientStateService $clientState,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function project(TelnyxEvent $event, array $data): void
    {
        $payload = $data['payload'] ?? null;
        if (!is_array($payload)) {
            return;
        }

        $providerSessionId = $this->stringValue($payload, 'call_session_id');
        if (null === $providerSessionId) {
            return;
        }

        $session = $this->sessionRepository->findOneByProviderSessionId($providerSessionId);
        if (null === $session) {
            $session = new CallSession($providerSessionId);
            $this->entityManager->persist($session);
        }

        $this->linkParentSession($session, $payload);

        $occurredAt = $this->dateValue($data['occurred_at'] ?? null) ?? new \DateTimeImmutable();
        $this->updateLastEventAt($session, $occurredAt);
        $this->populateInboundNumbers($session, $payload);

        $leg = $this->upsertLeg($session, $payload);
        $event->setCallSession($session);
        $event->setCallLeg($leg);

        $this->applyEvent($event->getEventType(), $session, $leg, $payload, $occurredAt);
        $session->touch();
        $leg?->touch();

        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function upsertLeg(CallSession $session, array $payload): ?CallLeg
    {
        $providerLegId = $this->stringValue($payload, 'call_leg_id');
        if (null === $providerLegId) {
            return null;
        }

        $leg = $this->legRepository->findOneByProviderLegId($providerLegId);
        if (null === $leg) {
            $leg = new CallLeg($session, $providerLegId);
            $this->entityManager->persist($leg);
        } elseif ($leg->getCallSession() !== $session) {
            throw new \RuntimeException(sprintf(
                'Telnyx leg "%s" is already assigned to another call session.',
                $providerLegId,
            ));
        }

        $leg
            ->setCallControlId($this->stringValue($payload, 'call_control_id') ?? $leg->getCallControlId())
            ->setConnectionId($this->stringValue($payload, 'connection_id') ?? $leg->getConnectionId())
            ->setDirection($this->stringValue($payload, 'direction') ?? $leg->getDirection())
            ->setFromNumber($this->stringValue($payload, 'from') ?? $leg->getFromNumber())
            ->setToNumber($this->stringValue($payload, 'to') ?? $leg->getToNumber());

        return $leg;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyEvent(
        string $eventType,
        CallSession $session,
        ?CallLeg $leg,
        array $payload,
        \DateTimeImmutable $occurredAt,
    ): void {
        if ('call.initiated' === $eventType) {
            $startedAt = $this->dateValue($payload['start_time'] ?? null) ?? $occurredAt;
            $this->advanceStatus($session, 'initiated');
            $session->setStartedAt($session->getStartedAt() ?? $startedAt);
            if ('incoming' === $this->stringValue($payload, 'direction') && null === $session->getFlowType()) {
                $session->setFlowType(CallSession::FLOW_TYPE_INBOUND_FORWARD);
            }
            if (null !== $leg) {
                $this->advanceStatus($leg, 'initiated');
                $leg->setStartedAt($leg->getStartedAt() ?? $startedAt);
            }

            return;
        }

        if ('call.answered' === $eventType) {
            $this->advanceStatus($session, 'answered');
            $session->setAnsweredAt($session->getAnsweredAt() ?? $occurredAt);
            if (null !== $leg) {
                $this->advanceStatus($leg, 'answered');
                $leg->setAnsweredAt($leg->getAnsweredAt() ?? $occurredAt);
            }

            return;
        }

        if (in_array($eventType, ['call.bridged', 'call.bridge'], true)) {
            $this->advanceStatus($session, 'bridged');
            if (null !== $leg) {
                $this->advanceStatus($leg, 'bridged');
            }

            return;
        }

        if ('call.cost' === $eventType && null !== $leg) {
            $billedDurationSeconds = $this->integerValue($payload, 'billed_duration_secs');
            if (null !== $billedDurationSeconds) {
                $leg->setBilledDurationSeconds($billedDurationSeconds);
            }

            return;
        }

        if ('call.hangup' !== $eventType || null === $leg) {
            return;
        }

        $endedAt = $this->dateValue($payload['end_time'] ?? null) ?? $occurredAt;
        $hangupCause = $this->stringValue($payload, 'hangup_cause');
        $hangupSource = $this->stringValue($payload, 'hangup_source');

        $leg
            ->setStatus('completed')
            ->setEndedAt($endedAt)
            ->setHangupCause($hangupCause)
            ->setHangupSource($hangupSource)
            ->setSipHangupCause($this->stringValue($payload, 'sip_hangup_cause'));

        if (!$this->legRepository->hasOtherActiveLegs($session, $leg)) {
            $session
                ->setStatus('completed')
                ->setEndedAt($endedAt)
                ->setHangupCause($hangupCause)
                ->setHangupSource($hangupSource);
        }
    }

    private function advanceStatus(CallSession|CallLeg $entity, string $status): void
    {
        $currentRank = self::STATUS_RANK[$entity->getStatus()] ?? 0;
        $newRank = self::STATUS_RANK[$status] ?? 0;
        if ($newRank > $currentRank) {
            $entity->setStatus($status);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function populateInboundNumbers(CallSession $session, array $payload): void
    {
        if ('incoming' !== $this->stringValue($payload, 'direction')) {
            return;
        }

        $session->setInboundFrom(
            $session->getInboundFrom() ?? $this->stringValue($payload, 'from'),
        );
        $session->setInboundTo(
            $session->getInboundTo() ?? $this->stringValue($payload, 'to'),
        );
    }

    private function updateLastEventAt(CallSession $session, \DateTimeImmutable $occurredAt): void
    {
        if (null === $session->getLastEventAt() || $occurredAt > $session->getLastEventAt()) {
            $session->setLastEventAt($occurredAt);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function linkParentSession(CallSession $session, array $payload): void
    {
        $clientState = $this->stringValue($payload, 'client_state');
        $parentProviderSessionId = $this->inboundSessionIdFromClientState($clientState);
        $flowType = $this->flowTypeFromClientState($clientState);
        if (null !== $flowType && null === $session->getFlowType()) {
            $session->setFlowType($flowType);
        }
        if (null === $parentProviderSessionId || $parentProviderSessionId === $session->getProviderSessionId()) {
            return;
        }

        $parentSession = $this->sessionRepository->findOneByProviderSessionId($parentProviderSessionId);
        if (null !== $parentSession) {
            $session->setParentCallSession($parentSession);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringValue(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? $value : null;
    }

    private function dateValue(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function integerValue(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function inboundSessionIdFromClientState(?string $clientState): ?string
    {
        $state = $this->clientState->decode($clientState);
        $sessionId = is_array($state)
            ? ($state['inbound_call_session_id'] ?? $state['root_call_session_id'] ?? null)
            : null;

        return is_string($sessionId) && '' !== $sessionId ? $sessionId : null;
    }

    private function flowTypeFromClientState(?string $clientState): ?string
    {
        $state = $this->clientState->decode($clientState);
        $flowType = is_array($state) ? ($state['flow'] ?? null) : null;

        return is_string($flowType) && '' !== trim($flowType) ? $flowType : null;
    }
}
