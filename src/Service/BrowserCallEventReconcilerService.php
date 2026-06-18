<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BrowserSoftphoneSession;
use App\Entity\CallLeg;
use App\Entity\CallSession;
use App\Entity\TelnyxEvent;
use App\Repository\BrowserSoftphoneSessionRepository;
use App\Repository\CallSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reconciles browser SDK events with Telnyx webhook events for Browser Call sessions.
 *
 * Priority (strongest → weakest):
 *   1. Telnyx webhook (authoritative provider state) — applied by reconcileWebhook()
 *   2. Browser SDK (local client state) — used when no authoritative webhook exists yet
 *
 * Deduplication: browser events are idempotent via the event name + timestamp pair
 * stored on the browser softphone session's callMeta field.
 */
final class BrowserCallEventReconcilerService
{
    /** @var array<string, int> Rank of each normalized CRM event type */
    private const array STATUS_RANK = [
        'initiated' => 10,
        'dialing' => 12,
        'ringing' => 15,
        'answered' => 20,
        'connected' => 30,
        'failed' => 50,
        'completed' => 60,
        'csr_hangup' => 65,
        'customer_hangup' => 65,
        'abandoned' => 55,
        'timed_out' => 55,
    ];

    public function __construct(
        private readonly CallSessionRepository $sessionRepo,
        private readonly BrowserSoftphoneSessionRepository $browserSessionRepo,
        private readonly EntityManagerInterface $em,
        private readonly CallEventEngineService $callEventEngine,
        private readonly CommunicationTimelineProjector $timelineProjector,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Reconcile a browser-reported event for a given provider session ID.
     *
     * @param array<string, mixed> $meta Additional metadata (e.g. errorCode, errorMessage).
     */
    public function reconcile(
        string $providerSessionId,
        string $browserEventType,
        ?string $callId = null,
        ?string $destinationNumber = null,
        ?array $meta = [],
    ): void {
        // Resolve the CallSession first. If not found, this is not our concern.
        $session = $this->sessionRepo->findOneBy(['providerSessionId' => trim($providerSessionId)]);
        if (null === $session) {
            return;
        }

        // Only reconcile for Browser Call mode. Bridge Call uses existing Telnyx webhook path.
        if (CallSession::CALL_MODE_BROWSER !== $session->getCallMode()) {
            return;
        }

        // Resolve browser softphone session for deduplication and metadata tracking.
        $browserSession = null;
        try {
            $browserSession = $this->browserSessionRepo->findOneBy(['callSession' => $session]);
        } catch (\Throwable) {
            // Best effort: proceed without browser session metadata.
        }

        // Normalize the raw browser event to a CRM event name.
        $normalized = $this->normalizeBrowserEvent($browserEventType, $meta);
        if (null === $normalized) {
            $this->logger->info('Unrecognized browser event type ignored.', [
                'providerSessionId' => $providerSessionId,
                'browserEventType' => $browserEventType,
            ]);

            return;
        }

        // Deduplication: check last recorded event on the browser session.
        if (null !== $browserSession && $this->isDuplicate($browserSession, $normalized['normalizedEvent'], $meta)) {
            $this->logger->debug('Duplicated browser event ignored.', [
                'providerSessionId' => $providerSessionId,
                'event' => $normalized['normalizedEvent'],
            ]);

            return;
        }

        // Stale event rejection: if Telnyx webhook already set a stronger/final state on the session,
        // do not downgrade browser-reported state.
        if (!$this->isStateAcceptable($session, $normalized['callState'])) {
            $this->logger->info('Stale browser event rejected in favor of Telnyx webhook state.', [
                'providerSessionId' => $providerSessionId,
                'browserEvent' => $normalized['normalizedEvent'],
                'currentTelnyxState' => $session->getCallState(),
            ]);

            // Still record the event in timeline for visibility (but don't override state).
            if (null !== $session->getTenant()) {
                $this->timelineProjector->recordCallEvent(
                    $session,
                    'call.event.'.$normalized['normalizedEvent'],
                    'Browser-reported '.$normalized['normalizedEvent'].' (superseded by provider state).',
                    [
                        'providerSessionId' => $providerSessionId,
                        'browserEventType' => $browserEventType,
                        'currentCallState' => $session->getCallState(),
                    ],
                );
            }

            return;
        }

        // Update call session state.
        $session
            ->setCallState($normalized['callState'])
            ->setStatus(strtolower((string) $normalized['callState']))
            ->touch();

        if ('answered' === $normalized['normalizedEvent'] || 'connected' === $normalized['normalizedEvent']) {
            $session->setAnsweredAt($session->getAnsweredAt() ?? new \DateTimeImmutable());
        } elseif (in_array($normalized['normalizedEvent'], ['completed', 'csr_hangup', 'customer_hangup'], true)) {
            $session->setEndedAt($session->getEndedAt() ?? new \DateTimeImmutable());
        }

        if ('abandoned' === $normalized['normalizedEvent'] || 'timed_out' === $normalized['normalizedEvent']) {
            $session
                ->setCallState(CallSession::CALL_STATE_FAILED)
                ->setStatus('failed');
        }

        // Persist event metadata on the browser session for dedup tracking.
        if (null !== $browserSession) {
            $lastEvent = $browserSession->getCallMeta()['lastEvent'] ?? null;
            if (is_array($lastEvent)) {
                $lastEvent['name'] = $normalized['normalizedEvent'];
                $lastEvent['timestamp'] = new \DateTimeImmutable()->format(DATE_ATOM);
                $lastEvent['callId'] = $callId ?? $lastEvent['callId'] ?? null;
            } else {
                $lastEvent = [
                    'name' => $normalized['normalizedEvent'],
                    'timestamp' => new \DateTimeImmutable()->format(DATE_ATOM),
                    'callId' => $callId,
                ];
            }

            $browserSession->setCallMeta(
                array_merge($browserSession->getCallMeta() ?? [], ['lastEvent' => $lastEvent]),
            );

            // Update call state on browser softphone session.
            $browserSession
                ->setCallState(strtolower((string) $normalized['callState']))
                ->setCallErrorCode($meta['errorCode'] ?? null)
                ->setCallErrorMessage($meta['errorMessage'] ?? null)
                ->touch();

            if ('connected' === $normalized['normalizedEvent']) {
                $browserSession->setCallAnsweredAt(
                    $browserSession->getCallAnsweredAt() ?? new \DateTimeImmutable(),
                );
            } elseif (in_array($normalized['normalizedEvent'], ['completed', 'csr_hangup', 'customer_hangup'], true)) {
                $browserSession
                    ->setCallEndedAt($browserSession->getCallEndedAt() ?? new \DateTimeImmutable())
                    ->setStatus(BrowserSoftphoneSession::STATUS_ENDED);
            }

            // Audit log the reconciliation.
            if (null !== $session->getTenant()) {
                $this->auditLogger->log(
                    $session->getTenant(),
                    'call_session',
                    (string) ($session->getId() ?? 'new'),
                    'call.browser_event_reconciled',
                    null,
                    [
                        'browserEventType' => $browserEventType,
                        'normalizedEvent' => $normalized['normalizedEvent'],
                        'callState' => $session->getCallState(),
                    ],
                    [
                        'providerSessionId' => $providerSessionId,
                        'destinationNumber' => $destinationNumber ?? $session->getClientPhoneNumber(),
                    ],
                );
            }
        }

        // Push event through CallEventEngine for audit + timeline.
        $this->callEventEngine->record(
            new TelnyxEvent(
                'browser-synthetic:'.uniqid('', true),
                'browser_'.$normalized['normalizedEvent'],
                ['call_session_id' => $providerSessionId, 'raw_browser_event' => $browserEventType],
            ),
            $session,
            null,
            ['rawBrowserEvent' => $browserEventType] + ($meta ?? []),
            new \DateTimeImmutable(),
        );

        if (null !== $session->getProperty()) {
            $this->timelineProjector->syncProperty($session->getProperty());
        }

        $this->em->persist($session);
        if (null !== $browserSession) {
            $this->em->persist($browserSession);
        }
        $this->em->flush();
    }

    /**
     * Reconcile a Telnyx webhook event for a browser call session — ensures the webhook state
     * is applied as authoritative and any pending browser-reported states are superseded.
     */
    public function reconcileWebhook(
        TelnyxCallProjectionService $callProjection,
        TelnyxEvent $event,
        array $data,
    ): void {
        // Delegate to existing projection which handles leg/session updates.
        $callProjection->project($event, $data);

        // After projection, verify no stale browser state remains on the session.
        $session = $event->getCallSession();
        if (null === $session || CallSession::CALL_MODE_BROWSER !== $session->getCallMode()) {
            return;
        }

        // If Telnyx says completed/failed, ensure browser softphone session is also ended.
        try {
            $browserSession = $this->browserSessionRepo->findOneBy(['callSession' => $session]);
            if (null !== $browserSession) {
                if (in_array($session->getCallState(), [
                    CallSession::CALL_STATE_COMPLETED,
                    CallSession::CALL_STATE_FAILED,
                ], true)) {
                    $browserSession
                        ->setCallState(strtolower((string) $session->getCallState()))
                        ->setStatus(BrowserSoftphoneSession::STATUS_ENDED)
                        ->touch();
                }
            }
        } catch (\Throwable) {
            // Best effort.
        }

        $this->em->flush();
    }

    /**
     * Normalize a raw browser SDK event string to CRM event name and state.
     *
     * @param array<string, mixed> $meta
     * @return array{normalizedEvent:string,callState:?string,bodyText:string,auditAction:string}|null
     */
    private function normalizeBrowserEvent(string $browserEventType, array $meta): ?array
    {
        return match ($browserEventType) {
            'sdk_connecting' => null, // Not a call state change.
            'sdk_ready' => null,      // Connection only, not call state.
            'call.requesting' => [
                'normalizedEvent' => 'dialing',
                'callState' => CallSession::CALL_STATE_INITIATED,
                'bodyText' => 'Browser call requesting...',
                'auditAction' => 'call.event.dialing',
            ],
            'call.ringing' => [
                'normalizedEvent' => 'ringing',
                'callState' => CallSession::CALL_STATE_RINGING,
                'bodyText' => 'Call is ringing.',
                'auditAction' => 'call.event.ringing',
            ],
            'call.active' => [
                'normalizedEvent' => 'connected',
                'callState' => CallSession::CALL_STATE_CONNECTED,
                'bodyText' => 'Browser call connected.',
                'auditAction' => 'call.event.answered',
            ],
            'call.hangup' => [
                'normalizedEvent' => 'csr_hangup',
                'callState' => CallSession::CALL_STATE_COMPLETED,
                'bodyText' => 'CSR ended the call.',
                'auditAction' => 'call.event.csr_hangup',
            ],
            'call.failed' => match ($meta['errorCode'] ?? '') {
                'timeout' => [
                    'normalizedEvent' => 'timed_out',
                    'callState' => CallSession::CALL_STATE_FAILED,
                    'bodyText' => 'Call timed out.',
                    'auditAction' => 'call.event.timed_out',
                ],
                default => [
                    'normalizedEvent' => 'failed',
                    'callState' => CallSession::CALL_STATE_FAILED,
                    'bodyText' => 'Browser call failed.',
                    'auditAction' => 'call.event.failed',
                ],
            },
            'sdk_disconnected' => null, // Not a call state change.
            'mic_denied' => null,
            default => null,
        };
    }

    /**
     * Check if the target state is acceptable (not lower than current Telnyx-verified state).
     */
    private function isStateAcceptable(CallSession $session, ?string $targetState): bool
    {
        $currentRank = self::STATUS_RANK[$session->getCallState()] ?? 0;
        $targetRank = self::STATUS_RANK[$targetState] ?? 0;

        // If current state rank >= target rank, the Telnyx state is stronger or equal.
        return $targetRank > $currentRank;
    }

    /**
     * Check if this event is a duplicate of the last recorded event on the browser session.
     *
     * @param array<string, mixed> $meta
     */
    private function isDuplicate(?BrowserSoftphoneSession $browserSession, string $eventName, array $meta): bool
    {
        if (null === $browserSession) {
            return false;
        }

        $lastEvent = $browserSession->getCallMeta()['lastEvent'] ?? null;
        if (!is_array($lastEvent)) {
            return false;
        }

        if (($lastEvent['name'] ?? null) !== $eventName) {
            return false;
        }

        // Consider it a duplicate if the timestamp is within 2 seconds.
        $timestamp = $lastEvent['timestamp'] ?? null;
        if (null === $timestamp) {
            return false;
        }

        try {
            $lastTime = new \DateTimeImmutable($timestamp);
            $now = new \DateTimeImmutable();

            return abs((int) ($now->getTimestamp() - $lastTime->getTimestamp())) <= 2;
        } catch (\Exception) {
            return false;
        }
    }
}
