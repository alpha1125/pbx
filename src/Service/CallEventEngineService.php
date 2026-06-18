<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallLeg;
use App\Entity\CallSession;
use App\Entity\TelnyxEvent;

class CallEventEngineService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CommunicationTimelineProjector $timelineProjector,
    ) {
    }

    /**
     * Normalize a raw Telnyx call event to a CRM call event name and state.
     *
     * @param array<string, mixed> $payload
     * @return array{normalizedEvent:string,callState:?string,bodyText:string,auditAction:string,metadata:array<string, mixed>}|null
     */
    public function normalize(string $eventType, array $payload): ?array
    {
        return match ($eventType) {
            'call.initiated' => $this->normalized('initiated', CallSession::CALL_STATE_INITIATED, 'Call initiated.', $eventType, $payload),
            'call.ringing' => $this->normalized('ringing', CallSession::CALL_STATE_RINGING, 'Call is ringing.', $eventType, $payload),
            'call.answered', 'call.bridged', 'call.bridge' => $this->normalized('answered', CallSession::CALL_STATE_CONNECTED, 'Call answered.', $eventType, $payload),
            'call.failed' => $this->normalized('failed', CallSession::CALL_STATE_FAILED, 'Call failed.', $eventType, $payload),
            'call.completed' => $this->normalized('completed', CallSession::CALL_STATE_COMPLETED, 'Call completed.', $eventType, $payload),
            'call.hangup' => $this->normalizeHangup($eventType, $payload),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function record(TelnyxEvent $event, CallSession $session, ?CallLeg $leg, array $payload, \DateTimeImmutable $occurredAt): void
    {
        $normalized = $this->normalize($event->getEventType(), $payload);
        if (null === $normalized || null === $session->getTenant()) {
            return;
        }

        $metadata = array_merge([
            'providerEventId' => $event->getProviderEventId(),
            'eventType' => $event->getEventType(),
            'normalizedEvent' => $normalized['normalizedEvent'],
            'callSessionId' => $session->getId(),
            'callLegId' => $leg?->getId(),
            'callState' => $normalized['callState'],
            'status' => $session->getStatus(),
            'hangupCause' => $session->getHangupCause(),
            'hangupSource' => $session->getHangupSource(),
            'occurredAt' => $occurredAt->format(DATE_ATOM),
        ], $normalized['metadata']);

        $this->auditLogger->log(
            $session->getTenant(),
            'call_session',
            (string) ($session->getId() ?? 'new'),
            $normalized['auditAction'],
            null,
            [
                'status' => $session->getStatus(),
                'callState' => $session->getCallState(),
                'hangupCause' => $session->getHangupCause(),
                'hangupSource' => $session->getHangupSource(),
            ],
            $metadata,
        );

        $this->timelineProjector->recordCallEvent(
            $session,
            $normalized['auditAction'],
            $normalized['bodyText'],
            $metadata,
        );

        if (null !== $session->getProperty()) {
            $this->timelineProjector->syncProperty($session->getProperty());
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{normalizedEvent:string,callState:?string,bodyText:string,auditAction:string,metadata:array<string, mixed>}
     */
    private function normalized(string $normalizedEvent, ?string $callState, string $bodyText, string $eventType, array $payload): array
    {
        return [
            'normalizedEvent' => $normalizedEvent,
            'callState' => $callState,
            'bodyText' => $bodyText,
            'auditAction' => 'call.event.'.$normalizedEvent,
            'metadata' => [
                'rawEventType' => $eventType,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{normalizedEvent:string,callState:?string,bodyText:string,auditAction:string,metadata:array<string, mixed>}|null
     */
    private function normalizeHangup(string $eventType, array $payload): ?array
    {
        $hangupSource = $this->stringValue($payload, 'hangup_source');
        $normalizedEvent = match ($hangupSource) {
            'caller', 'agent', 'csr' => 'csr_hangup',
            'callee', 'customer' => 'customer_hangup',
            default => 'completed',
        };

        $bodyText = match ($normalizedEvent) {
            'csr_hangup' => 'CSR ended the call.',
            'customer_hangup' => 'Customer ended the call.',
            default => 'Call completed.',
        };

        return [
            'normalizedEvent' => $normalizedEvent,
            'callState' => CallSession::CALL_STATE_COMPLETED,
            'bodyText' => $bodyText,
            'auditAction' => 'call.event.'.$normalizedEvent,
            'metadata' => array_filter([
                'rawEventType' => $eventType,
                'hangupSource' => $hangupSource,
                'hangupCause' => $this->stringValue($payload, 'hangup_cause'),
            ], static fn (mixed $value): bool => null !== $value),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringValue(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? $value : null;
    }
}
