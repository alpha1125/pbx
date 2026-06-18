<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CallSession;
use App\Entity\BrowserSoftphoneSession;
use App\Entity\UserTenantMembership;
use App\Repository\BrowserSoftphoneSessionRepository;
use App\Repository\CallSessionRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\BrowserCallEventReconcilerService;
use App\Service\BrowserSoftphoneSessionService;
use App\Service\CurrentTenantProviderInterface;
use App\Service\TenantMembershipAccessService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BrowserSoftphoneSessionController extends AbstractController
{
    #[Route('/api/calls/{providerSessionId}/browser-session', name: 'api_call_browser_session_allocate', methods: ['POST'])]
    public function __invoke(
        string $providerSessionId,
        CurrentTenantProviderInterface $tenantProvider,
        CallSessionRepository $sessions,
        BrowserSoftphoneSessionService $browserSoftphoneSessions,
        TenantMembershipAccessService $membershipAccess,
    ): JsonResponse {
        $tenantProvider->requireCurrentTenant();
        $membershipAccess->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_DISPATCH,
            UserTenantMembership::ROLE_SALES,
        ]);

        $callSession = $sessions->findOneByProviderSessionId($providerSessionId);
        if (null === $callSession) {
            return $this->json(['ok' => false, 'error' => sprintf('Call session "%s" was not found.', $providerSessionId)], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $callSession);

        if (CallSession::CALL_MODE_BROWSER !== $callSession->getCallMode()) {
            return $this->json(['ok' => false, 'error' => 'Browser softphone sessions are only available for browser calls.'], Response::HTTP_BAD_REQUEST);
        }

        if (null === $this->getUser()) {
            return $this->json(['ok' => false, 'error' => 'You must be logged in to allocate a browser session.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $browserSession = $browserSoftphoneSessions->allocate($callSession, $this->getUser());
        } catch (\Throwable $exception) {
            return $this->json(['ok' => false, 'error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'ok' => true,
            'browserSoftphoneSessionId' => $browserSession->getId(),
            'browserSessionToken' => $browserSession->getSessionToken(),
            'callSession' => [
                'id' => $callSession->getId(),
                'providerSessionId' => $callSession->getProviderSessionId(),
                'callMode' => $callSession->getCallMode(),
                'callState' => $callSession->getCallState(),
                'recordingState' => $callSession->getRecordingState(),
                'transcriptionState' => $callSession->getTranscriptionState(),
            ],
            'browserSession' => [
                'status' => $browserSession->getStatus(),
                'allocatedAt' => $browserSession->getAllocatedAt()->format(DATE_ATOM),
                'lastSeenAt' => $browserSession->getLastSeenAt()?->format(DATE_ATOM),
            ],
            'eventStreamUrl' => sprintf('/api/calls/%s/events/stream', $callSession->getProviderSessionId()),
        ]);
    }

    #[Route('/api/browser-softphone-sessions/{sessionToken}/events', name: 'api_browser_softphone_session_events', methods: ['POST'])]
    public function events(
        string $sessionToken,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        BrowserSoftphoneSessionRepository $sessions,
        BrowserSoftphoneSessionService $browserSoftphoneSessions,
        TenantMembershipAccessService $membershipAccess,
        EntityManagerInterface $entityManager,
        BrowserCallEventReconcilerService $reconciler,
    ): JsonResponse {
        $tenantProvider->requireCurrentTenant();
        $membershipAccess->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_DISPATCH,
            UserTenantMembership::ROLE_SALES,
        ]);

        $browserSession = $sessions->findOneBySessionToken($sessionToken);
        if (null === $browserSession) {
            return $this->json(['ok' => false, 'error' => 'Browser softphone session was not found.'], Response::HTTP_NOT_FOUND);
        }

        if (null === $this->getUser() || $browserSession->getUser()->getId() !== $this->getUser()?->getId()) {
            return $this->json(['ok' => false, 'error' => 'Browser softphone session does not belong to the current user.'], Response::HTTP_FORBIDDEN);
        }

        $payload = $this->decodeJsonBody($request);
        $event = is_string($payload['event'] ?? null) ? trim((string) $payload['event']) : '';
        if ('' === $event) {
            return $this->json(['ok' => false, 'error' => 'Event name is required.'], Response::HTTP_BAD_REQUEST);
        }

        $errorCode = is_string($payload['errorCode'] ?? null) ? trim((string) $payload['errorCode']) : null;
        $errorMessage = is_string($payload['message'] ?? null) ? trim((string) $payload['message']) : null;
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : null;
        $callId = is_string($payload['callId'] ?? null) ? trim((string) $payload['callId']) : null;
        $telnyxCallControlId = is_string($payload['telnyxCallControlId'] ?? null) ? trim((string) $payload['telnyxCallControlId']) : null;
        $destinationNumber = is_string($payload['destinationNumber'] ?? null) ? trim((string) $payload['destinationNumber']) : null;
        $telnyxConnectionId = is_string($payload['telnyxConnectionId'] ?? null) ? trim((string) $payload['telnyxConnectionId']) : null;

        if (in_array($event, ['sdk_connecting', 'sdk_ready', 'sdk_error', 'mic_denied', 'sdk_disconnected'], true)) {
            $connectionState = match ($event) {
                'sdk_connecting' => BrowserSoftphoneSession::CONNECTION_STATE_CONNECTING,
                'sdk_ready' => BrowserSoftphoneSession::CONNECTION_STATE_READY,
                'sdk_error', 'mic_denied' => BrowserSoftphoneSession::CONNECTION_STATE_FAILED,
                'sdk_disconnected' => BrowserSoftphoneSession::CONNECTION_STATE_DISCONNECTED,
            };
            $browserSoftphoneSessions->recordConnectionEvent($browserSession, $connectionState, $errorCode, $errorMessage, $meta);

            // Capture Telnyx WebRTC connection ID when SDK connects successfully
            if ('sdk_ready' === $event && null !== $telnyxConnectionId && '' !== $telnyxConnectionId) {
                $browserSession->setTelnyxConnectionId($telnyxConnectionId);
                $entityManager->flush();
            }
        } elseif (in_array($event, ['call.requesting', 'call.ringing', 'call.active', 'call.hangup', 'call.failed'], true)) {
            try {
                if (null !== $telnyxCallControlId && '' !== $telnyxCallControlId) {
                    $browserSession->setTelnyxCallControlId($telnyxCallControlId);
                    $entityManager->flush();
                }

                $browserSoftphoneSessions->recordCallEvent($browserSession, $event, $callId, $destinationNumber, $errorCode, $errorMessage, $meta);

                // Phase 9K: Reconcile browser-reported call events with Telnyx webhook state.
                try {
                    $reconciler->reconcile(
                        $browserSession->getCallSession()->getProviderSessionId(),
                        $event,
                        $callId,
                        $destinationNumber,
                        ['errorCode' => $errorCode, 'errorMessage' => $errorMessage] + ($meta ?? []),
                    );
                } catch (\Throwable $exception) {
                    // Best effort: reconciliation does not block event recording.
                }
            } catch (\RuntimeException $exception) {
                return $this->json(['ok' => false, 'error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
            }
        } else {
            return $this->json(['ok' => false, 'error' => sprintf('Unsupported browser softphone event "%s".', $event)], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'ok' => true,
            'browserSoftphoneSessionId' => $browserSession->getId(),
            'sessionToken' => $browserSession->getSessionToken(),
            'status' => $browserSession->getStatus(),
            'connectionState' => $browserSession->getConnectionState(),
            'connectionErrorCode' => $browserSession->getConnectionErrorCode(),
            'connectionErrorMessage' => $browserSession->getConnectionErrorMessage(),
            'callId' => $browserSession->getCallId(),
            'telnyxCallControlId' => $browserSession->getTelnyxCallControlId(),
            'callState' => $browserSession->getCallState(),
            'callErrorCode' => $browserSession->getCallErrorCode(),
            'callErrorMessage' => $browserSession->getCallErrorMessage(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(Request $request): array
    {
        try {
            $data = json_decode((string) $request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }
}
