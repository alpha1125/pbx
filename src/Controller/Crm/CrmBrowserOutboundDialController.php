<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\CallSessionRepository;
use App\Repository\ContactRepository;
use App\Repository\PropertyRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\AuditLogger;
use App\Service\CallEventEngineService;
use App\Service\BrowserSoftphoneSessionService;
use App\Service\CurrentTenantProviderInterface;
use App\Service\TelnyxCallControlService;
use App\Service\TenantMembershipAccessService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CrmBrowserOutboundDialController extends AbstractController
{
    #[Route('/crm/properties/{propertyId<\d+>}/contacts/{contactId<\d+>}/browser-call/dial', name: 'crm_property_contact_browser_call_dial', methods: ['POST'])]
    public function __invoke(
        int $propertyId,
        int $contactId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        ContactRepository $contactRepository,
        CallSessionRepository $callSessionRepo,
        BrowserSoftphoneSessionService $browserSoftphoneSessions,
        TelnyxCallControlService $callControl,
        CallEventEngineService $eventEngine,
        TenantMembershipAccessService $membershipAccess,
        AuditLogger $auditLogger,
        EntityManagerInterface $em,
    ): JsonResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $membershipAccess->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_DISPATCH,
            UserTenantMembership::ROLE_SALES,
        ]);

        $property = $propertyRepository->findOneByTenantAndId($tenant, $propertyId);
        $contact = $contactRepository->findOneByTenantAndId($tenant, $contactId);
        if (null === $property || null === $contact) {
            return $this->json(['ok' => false, 'error' => 'Property or contact not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $property);
        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $contact);

        if (!$this->isCsrfTokenValid($this->browserCallTokenId($propertyId, $contactId), (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        // Get provider session ID from request body (from prepare response)
        $providerSessionId = $request->request->get('providerSessionId', '');
        if ('' === trim((string) $providerSessionId)) {
            return $this->json(['ok' => false, 'error' => 'Provider session ID is required.'], Response::HTTP_BAD_REQUEST);
        }

        // Find the call session for this provider session
        $callSession = $callSessionRepo->findOneBy(['providerSessionId' => trim($providerSessionId)]);

        if (null === $callSession) {
            return $this->json(['ok' => false, 'error' => 'Call session not found or expired.'], Response::HTTP_NOT_FOUND);
        }

        // Validate call mode is browser_call
        if (CallSession::CALL_MODE_BROWSER !== $callSession->getCallMode()) {
            return $this->json(['ok' => false, 'error' => 'Dial is only supported for browser call mode.'], Response::HTTP_BAD_REQUEST);
        }

        // Validate tenant ownership on the call session
        if (null === $callSession->getTenant() || $callSession->getTenant()->getId() !== $tenant->getId()) {
            return $this->json(['ok' => false, 'error' => 'Call session does not belong to the current tenant.'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (null === $user) {
            return $this->json(['ok' => false, 'error' => 'You must be logged in to place a browser call.'], Response::HTTP_FORBIDDEN);
        }
        if (!$user instanceof User) {
            return $this->json(['ok' => false, 'error' => 'You must be logged in to place a browser call.'], Response::HTTP_FORBIDDEN);
        }

        // Find the browser softphone session for this call session, scoped to the current tenant and user.
        try {
            $browserSession = $browserSoftphoneSessions->findByProviderSessionId($tenant, $user, trim($providerSessionId));
        } catch (\RuntimeException $exception) {
            return $this->json(['ok' => false, 'error' => 'Browser softphone session not found.'], Response::HTTP_NOT_FOUND);
        }

        // Validate the Telnyx WebRTC connection is established
        if (\App\Entity\BrowserSoftphoneSession::CONNECTION_STATE_READY !== $browserSession->getConnectionState()) {
            return $this->json(['ok' => false, 'error' => 'Browser softphone must be connected before dialing.'], Response::HTTP_BAD_REQUEST);
        }

        $connectionId = $browserSession->getTelnyxConnectionId();
        if (null === $connectionId || '' === trim($connectionId)) {
            return $this->json(['ok' => false, 'error' => 'Telnyx WebRTC connection ID not available.'], Response::HTTP_BAD_REQUEST);
        }

        // Validate destination number matches the approved contact phone
        $approvedDestination = $callSession->getClientPhoneNumber();
        if (null === $approvedDestination || '' === trim($approvedDestination)) {
            return $this->json(['ok' => false, 'error' => 'Approved destination number is missing.'], Response::HTTP_BAD_REQUEST);
        }

        // Prevent dial on terminal call states
        if (in_array($callSession->getCallState(), [CallSession::CALL_STATE_COMPLETED, CallSession::CALL_STATE_FAILED], true)) {
            return $this->json(['ok' => false, 'error' => 'Call session has already ended.'], Response::HTTP_BAD_REQUEST);
        }

        if (CallSession::CALL_STATE_INITIATED !== $callSession->getCallState()) {
            return $this->json(['ok' => false, 'error' => 'Browser call is already in progress.'], Response::HTTP_BAD_REQUEST);
        }

        // Initiate outbound PSTN leg via Telnyx using the browser's active WebRTC connection
        try {
            $response = $callControl->dial(
                $connectionId,
                trim($this->getParameter('telnyx_from_number')),
                $approvedDestination,
                null, // clientState - not needed for browser outbound dial
                45,   // timeout_secs
                sprintf('browser-dial:%s:dial', $callSession->getProviderSessionId()),
            );

            if (null === $response || !isset($response['data']['call_leg_id'])) {
                throw new \RuntimeException('Telnyx dial response did not include a call leg ID.');
            }

            // Update call states on the CallSession entity
            $callSession
                ->setCallState(CallSession::CALL_STATE_RINGING)
                ->setStatus('active')
                ->setStartedAt($callSession->getStartedAt() ?? new \DateTimeImmutable())
                ->touch();

            $em->persist($callSession);
            $em->flush();

            // Timeline updates are best-effort here; Browser Call event reconciliation
            // is handled elsewhere in the BrowserSoftphoneSession pipeline.
            if (method_exists($eventEngine, 'pushEvent')) {
                $eventEngine->pushEvent(trim($providerSessionId), 'call.initiated', [
                    'callMode' => CallSession::CALL_MODE_BROWSER,
                    'destinationNumber' => $approvedDestination,
                    'providerSessionId' => $callSession->getProviderSessionId(),
                ]);
            }

            // Audit log the dial event
            $auditLogger->log(
                $tenant,
                'call_session',
                (string) ($callSession->getId() ?? 'new'),
                'call.browser_call_dialed',
                null,
                [
                    'status' => $callSession->getStatus(),
                    'callState' => $callSession->getCallState(),
                    'callMode' => CallSession::CALL_MODE_BROWSER,
                    'telnyxConnectionId' => $connectionId,
                    'approvedDestinationNumber' => $approvedDestination,
                ],
                [
                    'propertyId' => $property->getId(),
                    'contactId' => $contact->getId(),
                    'providerSessionId' => $callSession->getProviderSessionId(),
                    'callSessionId' => $callSession->getId(),
                ],
            );
            $em->flush();

            return $this->json([
                'ok' => true,
                'callMode' => CallSession::CALL_MODE_BROWSER,
                'callSessionId' => $callSession->getId(),
                'providerSessionId' => $callSession->getProviderSessionId(),
                'status' => $callSession->getStatus(),
                'callState' => $callSession->getCallState(),
                'callLegId' => $response['data']['call_leg_id'],
                'callSession' => [
                    'id' => $callSession->getId(),
                    'providerSessionId' => $callSession->getProviderSessionId(),
                    'callMode' => $callSession->getCallMode(),
                    'callState' => $callSession->getCallState(),
                    'recordingState' => $callSession->getRecordingState(),
                    'transcriptionState' => $callSession->getTranscriptionState(),
                    'clientPhoneNumber' => $callSession->getClientPhoneNumber(),
                ],
            ]);
        } catch (\RuntimeException $exception) {
            // Update call session to failed state on dial failure
            $callSession
                ->setCallState(CallSession::CALL_STATE_FAILED)
                ->setStatus('failed')
                ->touch();
            try {
                $em->persist($callSession);
                $em->flush();
            } catch (\Throwable) {
                // best effort
            }

            return $this->json([
                'ok' => false,
                'error' => 'Failed to initiate outbound call: '.$exception->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    private function browserCallTokenId(int $propertyId, int $contactId): string
    {
        return sprintf('crm_browser_call_%d_%d', $propertyId, $contactId);
    }
}
