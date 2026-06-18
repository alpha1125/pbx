<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\BrowserSoftphoneSession;
use App\Entity\CallSession;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\BrowserSoftphoneSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class BrowserSoftphoneSessionService
{
    public function __construct(
        private readonly BrowserSoftphoneSessionRepository $sessions,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly CommunicationTimelineProjector $timelineProjector,
    ) {
    }

    public function allocate(CallSession $callSession, User $user): BrowserSoftphoneSession
    {
        if (CallSession::CALL_MODE_BROWSER !== $callSession->getCallMode()) {
            throw new \RuntimeException('Browser softphone sessions can only be allocated for browser calls.');
        }

        if (null === $callSession->getTenant()) {
            throw new \RuntimeException('Browser softphone sessions require a tenant-scoped call session.');
        }

        if (null === $callSession->getCsrUser() || null === $callSession->getCsrUser()->getId() || null === $user->getId() || $callSession->getCsrUser()->getId() !== $user->getId()) {
            throw new \RuntimeException('Browser softphone sessions must be allocated for the CSR assigned to the call session.');
        }

        $existing = $this->sessions->findOneByCallSession($callSession);
        if (null !== $existing) {
            $existing->setLastSeenAt(new \DateTimeImmutable())->touch();
            $this->entityManager->flush();

            return $existing;
        }

        $session = new BrowserSoftphoneSession(
            $callSession,
            $callSession->getTenant(),
            $user,
            Uuid::v7()->toRfc4122(),
        );

        $this->entityManager->persist($session);
        $this->auditLogger->log(
            $callSession->getTenant(),
            'browser_softphone_session',
            'new',
            'call.browser_session_allocated',
            null,
            [
                'status' => $session->getStatus(),
                'callMode' => $callSession->getCallMode(),
                'callSessionId' => $callSession->getId(),
            ],
            [
                'browserSoftphoneSessionId' => 'new',
                'callSessionId' => $callSession->getId(),
                'userId' => $user->getId(),
            ],
        );
        $this->entityManager->flush();

        return $session;
    }

    public function findByProviderSessionId(Tenant $tenant, User $user, string $providerSessionId): BrowserSoftphoneSession
    {
        $session = $this->sessions->createQueryBuilder('browserSession')
            ->innerJoin('browserSession.callSession', 'callSession')
            ->andWhere('browserSession.tenant = :tenant')
            ->andWhere('browserSession.user = :user')
            ->andWhere('callSession.providerSessionId = :providerSessionId')
            ->setParameter('tenant', $tenant)
            ->setParameter('user', $user)
            ->setParameter('providerSessionId', trim($providerSessionId))
            ->getQuery()
            ->getOneOrNullResult();

        if (!$session instanceof BrowserSoftphoneSession) {
            throw new \RuntimeException('Browser softphone session not found for the current tenant and user.');
        }

        return $session;
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    public function recordConnectionEvent(
        BrowserSoftphoneSession $session,
        string $connectionState,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?array $meta = null,
    ): BrowserSoftphoneSession {
        $now = new \DateTimeImmutable();
        $session
            ->setConnectionState($connectionState)
            ->setConnectionErrorCode($errorCode)
            ->setConnectionErrorMessage($errorMessage)
            ->setConnectionMeta($meta)
            ->touch($now);

        if (BrowserSoftphoneSession::CONNECTION_STATE_CONNECTING === $connectionState) {
            $session->setConnectionAttemptedAt($session->getConnectionAttemptedAt() ?? $now);
        } elseif (BrowserSoftphoneSession::CONNECTION_STATE_READY === $connectionState) {
            $session
                ->setStatus(BrowserSoftphoneSession::STATUS_ACTIVE)
                ->setConnectionReadyAt($now)
                ->setConnectionFailedAt(null);
        } elseif (BrowserSoftphoneSession::CONNECTION_STATE_FAILED === $connectionState) {
            $session
                ->setStatus(BrowserSoftphoneSession::STATUS_ENDED)
                ->setConnectionFailedAt($now);
        } elseif (BrowserSoftphoneSession::CONNECTION_STATE_DISCONNECTED === $connectionState) {
            $session->setStatus(BrowserSoftphoneSession::STATUS_ENDED);
        }

        $this->auditLogger->log(
            $session->getTenant(),
            'browser_softphone_session',
            (string) ($session->getId() ?? 'new'),
            'call.browser_connection_event',
            null,
            [
                'status' => $session->getStatus(),
                'connectionState' => $session->getConnectionState(),
                'connectionErrorCode' => $session->getConnectionErrorCode(),
                'connectionErrorMessage' => $session->getConnectionErrorMessage(),
            ],
            [
                'callSessionId' => $session->getCallSession()->getId(),
                'sessionToken' => $session->getSessionToken(),
                'meta' => $meta,
            ],
        );

        $this->entityManager->flush();

        return $session;
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    public function recordCallEvent(
        BrowserSoftphoneSession $session,
        string $eventType,
        ?string $callId = null,
        ?string $destinationNumber = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?array $meta = null,
    ): BrowserSoftphoneSession {
        $now = new \DateTimeImmutable();
        $approvedDestination = $this->normalizePhone($session->getCallSession()->getClientPhoneNumber());
        $normalizedDestination = $this->normalizePhone($destinationNumber);

        if (null !== $approvedDestination && null !== $normalizedDestination && $approvedDestination !== $normalizedDestination) {
            throw new \RuntimeException('Browser call destination does not match the approved destination number.');
        }

        if (null !== $callId) {
            $existingCallId = $session->getCallId();
            if (null !== $existingCallId && $existingCallId !== $callId) {
                throw new \RuntimeException('Browser call identifier does not match the active call session.');
            }

            $session->setCallId($callId);
            $session->getCallSession()->setBrowserCallId($callId);
        }

        $session
            ->setCallErrorCode($errorCode)
            ->setCallErrorMessage($errorMessage)
            ->setCallMeta($meta)
            ->touch($now);

        $callSession = $session->getCallSession();
        $callSession->setBrowserCallId($session->getCallId() ?? $callSession->getBrowserCallId());
        $callSession->setLastEventAt($now);

        [$auditAction, $timelineAction, $bodyText] = match ($eventType) {
            'call.requesting' => ['call.browser_call.requesting', 'browser_call.requesting', 'Browser call is dialing.'],
            'call.ringing' => ['call.browser_call.ringing', 'browser_call.ringing', 'Browser call is ringing.'],
            'call.active' => ['call.browser_call.active', 'browser_call.active', 'Browser call is connected.'],
            'call.hangup' => ['call.browser_call.hangup', 'browser_call.hangup', 'Browser call ended.'],
            'call.failed' => ['call.browser_call.failed', 'browser_call.failed', 'Browser call failed.'],
            'call.recording_started' => ['call.browser_capture.started', 'browser_capture_started', 'Recording started.'],
            'call.recording_stopped' => ['call.browser_capture.stopped', 'browser_capture_stopped', 'Recording stopped.'],
            default => throw new \RuntimeException(sprintf('Unsupported browser call event "%s".', $eventType)),
        };

        if ('call.requesting' === $eventType) {
            $session
                ->setCallState('dialing')
                ->setCallStartedAt($session->getCallStartedAt() ?? $now)
                ->setCallAnsweredAt(null)
                ->setCallEndedAt(null)
                ->setCallFailedAt(null)
                ->setStatus(BrowserSoftphoneSession::STATUS_ACTIVE);
            $callSession
                ->setCallState(CallSession::CALL_STATE_INITIATED)
                ->setStatus('active')
                ->setStartedAt($callSession->getStartedAt() ?? $now)
                ->touch($now);
        } elseif ('call.ringing' === $eventType) {
            $session
                ->setCallState('ringing')
                ->setCallStartedAt($session->getCallStartedAt() ?? $now)
                ->setStatus(BrowserSoftphoneSession::STATUS_ACTIVE);
            $callSession
                ->setCallState(CallSession::CALL_STATE_RINGING)
                ->setStatus('active')
                ->setStartedAt($callSession->getStartedAt() ?? $now)
                ->touch($now);
        } elseif ('call.active' === $eventType) {
            $session
                ->setCallState('connected')
                ->setCallStartedAt($session->getCallStartedAt() ?? $now)
                ->setCallAnsweredAt($session->getCallAnsweredAt() ?? $now)
                ->setStatus(BrowserSoftphoneSession::STATUS_ACTIVE)
                ->setCallErrorCode(null)
                ->setCallErrorMessage(null);
            $callSession
                ->setCallState(CallSession::CALL_STATE_CONNECTED)
                ->setStatus('active')
                ->setStartedAt($callSession->getStartedAt() ?? $now)
                ->setAnsweredAt($callSession->getAnsweredAt() ?? $now)
                ->touch($now);
        } elseif ('call.hangup' === $eventType) {
            $session
                ->setCallState('ended')
                ->setCallEndedAt($session->getCallEndedAt() ?? $now)
                ->setStatus(BrowserSoftphoneSession::STATUS_ENDED);
            $callSession
                ->setCallState(CallSession::CALL_STATE_COMPLETED)
                ->setEndedAt($callSession->getEndedAt() ?? $now)
                ->setStatus('completed')
                ->touch($now);
        } elseif ('call.recording_started' === $eventType) {
            // Recording events don't change call state, only session recording state.
            $session->setCallState($session->getCallState());
            $callSession
                ->setRecordingState(CallSession::RECORDING_STATE_ACTIVE)
                ->touch($now);
        } elseif ('call.recording_stopped' === $eventType) {
            $session->setCallState($session->getCallState());
            $callSession
                ->setRecordingState(CallSession::RECORDING_STATE_STOPPED)
                ->touch($now);
        } elseif ('call.failed' === $eventType) {
            $session
                ->setCallState('failed')
                ->setCallFailedAt($session->getCallFailedAt() ?? $now)
                ->setStatus(BrowserSoftphoneSession::STATUS_ENDED);
            $callSession
                ->setCallState(CallSession::CALL_STATE_FAILED)
                ->setEndedAt($callSession->getEndedAt() ?? $now)
                ->setStatus('failed')
                ->touch($now);
        }

        $this->auditLogger->log(
            $session->getTenant(),
            'browser_softphone_session',
            (string) ($session->getId() ?? 'new'),
            $auditAction,
            null,
            [
                'status' => $session->getStatus(),
                'callState' => $session->getCallState(),
                'callId' => $session->getCallId(),
                'callErrorCode' => $session->getCallErrorCode(),
                'callErrorMessage' => $session->getCallErrorMessage(),
            ],
            [
                'callSessionId' => $callSession->getId(),
                'sessionToken' => $session->getSessionToken(),
                'destinationNumber' => $normalizedDestination ?? $approvedDestination,
                'meta' => $meta,
                'eventType' => $eventType,
            ],
        );

        $this->timelineProjector->recordCallEvent(
            $callSession,
            $timelineAction,
            $bodyText,
            [
                'browserSoftphoneSessionId' => $session->getId(),
                'browserCallId' => $session->getCallId(),
                'destinationNumber' => $normalizedDestination ?? $approvedDestination,
                'eventType' => $eventType,
                'callErrorCode' => $errorCode,
                'callErrorMessage' => $errorMessage,
                'meta' => $meta,
            ],
        );

        $this->entityManager->flush();

        return $session;
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (null === $phone) {
            return null;
        }

        $phone = trim($phone);

        return '' !== $phone ? $phone : null;
    }
}
