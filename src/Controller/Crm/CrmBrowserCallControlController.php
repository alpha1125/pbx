<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Capture\CapturePolicy;
use App\Entity\CallSession;
use App\Entity\UserTenantMembership;
use App\Repository\CallSessionRepository;
use App\Repository\ContactRepository;
use App\Repository\PropertyRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\AuditLogger;
use App\Service\BrowserSoftphoneSessionService;
use App\Service\CallCaptureControlService;
use App\Service\CallEventEngineService;
use App\Service\CurrentTenantProviderInterface;
use App\Service\TelnyxCallControlService;
use App\Service\TenantMembershipAccessService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Unified browser-call control endpoints (9J).
 *
 * Mute, hangup, DTMF keypad, and recording controls all route through the platform
 * so Browser Call and Bridge Call share identical CRM behaviour.
 *
 * For Browser Call mode: actions are sent via Telnyx Call Control API using the
 * call-control id captured from the browser SDK call object, not providerSessionId.
 */
final class CrmBrowserCallControlController extends AbstractController
{
    #[Route('/crm/properties/{propertyId<\d+>}/contacts/{contactId<\d+>}/browser-call/hangup', name: 'crm_property_contact_browser_call_hangup', methods: ['POST'])]
    public function hangup(
        int $propertyId,
        int $contactId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propRepo,
        ContactRepository $contactRepo,
        TenantMembershipAccessService $membershipAccess,
        CallSessionRepository $sessions,
        BrowserSoftphoneSessionService $browserSoftphoneSessions,
        TelnyxCallControlService $callControl,
        AuditLogger $auditLogger,
        EntityManagerInterface $em,
    ): JsonResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $membershipAccess->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_DISPATCH,
            UserTenantMembership::ROLE_SALES,
        ]);

        $property = $propRepo->findOneByTenantAndId($tenant, $propertyId);
        $contact = $contactRepo->findOneByTenantAndId($tenant, $contactId);
        if (null === $property || null === $contact) {
            return $this->json(['ok' => false, 'error' => 'Property or contact not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $property);
        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $contact);

        if (!$this->isCsrfTokenValid($this->browserCallTokenId($propertyId, $contactId), (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        if (null === $this->getUser()) {
            return $this->json(['ok' => false, 'error' => 'You must be logged in to hang up.'], Response::HTTP_FORBIDDEN);
        }

        $providerSessionId = $request->request->get('providerSessionId', '');
        if ('' === trim((string) $providerSessionId)) {
            return $this->json(['ok' => false, 'error' => 'Provider session ID is required.'], Response::HTTP_BAD_REQUEST);
        }

        $callSession = $sessions->findOneBy(['providerSessionId' => trim($providerSessionId)]);
        if (null === $callSession || CallSession::CALL_MODE_BROWSER !== $callSession->getCallMode()) {
            return $this->json(['ok' => false, 'error' => 'Active browser call session required.'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->json(['ok' => false, 'error' => 'You must be logged in to hang up.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $browserSession = $browserSoftphoneSessions->findByProviderSessionId($tenant, $user, trim($providerSessionId));
        } catch (\RuntimeException $exception) {
            return $this->json(['ok' => false, 'error' => 'Browser softphone session not found.'], Response::HTTP_NOT_FOUND);
        }

        $callControlId = $browserSession->getTelnyxCallControlId();
        if (null === $callControlId || '' === trim($callControlId)) {
            return $this->json(['ok' => false, 'error' => 'Browser call control ID is not available yet.'], Response::HTTP_BAD_REQUEST);
        }

        // Platform hangup via Telnyx (best-effort)
        try {
            $callControl->hangup($callControlId);
        } catch (\Throwable) {
            // Browser-side client will also disconnect the SDK call.
        }

        // Update CRM state
        $callSession
            ->setCallState(CallSession::CALL_STATE_COMPLETED)
            ->setStatus('completed')
            ->setEndedAt(new \DateTimeImmutable())
            ->touch();
        $em->persist($callSession);
        $em->flush();

        // Sync browser softphone session state
        $browserSoftphoneSessions->recordCallEvent($browserSession, 'call.hangup', null, null, null, null);

        $auditLogger->log(
            $callSession->getTenant(),
            'call_session',
            (string) ($callSession->getId() ?? 'new'),
            'call.browser_call_hungup',
            null,
            ['status' => $callSession->getStatus(), 'callState' => $callSession->getCallState()],
            ['propertyId' => $propertyId, 'contactId' => $contactId],
        );
        $em->flush();

        // Push hangup event to stream for CRM UI updates
        try {
            /** @var CallEventEngineService $engine */
            $engine = static::getContainer()->get(CallEventEngineService::class);
            $engine->pushEvent(trim($providerSessionId), 'call.hangup', [
                'callMode' => CallSession::CALL_MODE_BROWSER,
                'hangupSource' => 'csr',
            ]);
        } catch (\Throwable) {
            // Best effort.
        }

        return $this->json([
            'ok' => true,
            'callMode' => CallSession::CALL_MODE_BROWSER,
            'callState' => $callSession->getCallState(),
        ]);
    }

    #[Route('/crm/properties/{propertyId<\d+>}/contacts/{contactId<\d+>}/browser-call/recording', name: 'crm_property_contact_browser_call_recording', methods: ['POST'])]
    public function recording(
        int $propertyId,
        int $contactId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propRepo,
        ContactRepository $contactRepo,
        TenantMembershipAccessService $membershipAccess,
        CallSessionRepository $sessions,
        BrowserSoftphoneSessionService $browserSoftphoneSessions,
        CallCaptureControlService $captureControl,
        AuditLogger $auditLogger,
        EntityManagerInterface $em,
    ): JsonResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $membershipAccess->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_DISPATCH,
            UserTenantMembership::ROLE_SALES,
        ]);

        $property = $propRepo->findOneByTenantAndId($tenant, $propertyId);
        $contact = $contactRepo->findOneByTenantAndId($tenant, $contactId);
        if (null === $property || null === $contact) {
            return $this->json(['ok' => false, 'error' => 'Property or contact not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $property);
        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $contact);

        if (!$this->isCsrfTokenValid($this->browserCallTokenId($propertyId, $contactId), (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        if (null === $this->getUser()) {
            return $this->json(['ok' => false, 'error' => 'You must be logged in to control recording.'], Response::HTTP_FORBIDDEN);
        }

        $providerSessionId = $request->request->get('providerSessionId', '');
        if ('' === trim((string) $providerSessionId)) {
            return $this->json(['ok' => false, 'error' => 'Provider session ID is required.'], Response::HTTP_BAD_REQUEST);
        }

        $callSession = $sessions->findOneBy(['providerSessionId' => trim($providerSessionId)]);
        if (null === $callSession || CallSession::CALL_MODE_BROWSER !== $callSession->getCallMode()) {
            return $this->json(['ok' => false, 'error' => 'Active browser call session required.'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->json(['ok' => false, 'error' => 'You must be logged in to control recording.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $browserSession = $browserSoftphoneSessions->findByProviderSessionId($tenant, $user, trim($providerSessionId));
        } catch (\RuntimeException $exception) {
            return $this->json(['ok' => false, 'error' => 'Browser softphone session not found.'], Response::HTTP_NOT_FOUND);
        }

        $callControlId = $browserSession->getTelnyxCallControlId();
        if (null === $callControlId || '' === trim($callControlId)) {
            return $this->json(['ok' => false, 'error' => 'Browser call control ID is not available yet.'], Response::HTTP_BAD_REQUEST);
        }

        // Already recording? Stop it; otherwise start it.
        try {
            if (CallSession::RECORDING_STATE_INACTIVE === $callSession->getRecordingState() || CallSession::RECORDING_STATE_STOPPED === $callSession->getRecordingState()) {
                $this->startCapture($callSession, $captureControl, $auditLogger, $callControlId);

                $em->persist($callSession);
                $em->flush();

                $browserSoftphoneSessions->recordCallEvent($browserSession, 'call.recording_started', null, null, null, null);

                return $this->json([
                    'ok' => true,
                    'action' => 'start',
                    'recordingState' => $callSession->getRecordingState(),
                    'transcriptionState' => $callSession->getTranscriptionState(),
                ]);
            }

            $captureControl->stopTranscription($callSession, null, 'browser-capture', $callControlId);
            $captureControl->stopRecording($callSession, null, 'browser-capture', $callControlId);

            $callSession
                ->setRecordingState(CallSession::RECORDING_STATE_STOPPED)
                ->setTranscriptionState(CallSession::TRANSCRIPTION_STATE_STOPPED)
                ->touch();
            $em->persist($callSession);
            $em->flush();

            $browserSoftphoneSessions->recordCallEvent($browserSession, 'call.recording_stopped', null, null, null, null);

            return $this->json([
                'ok' => true,
                'action' => 'stop',
                'recordingState' => $callSession->getRecordingState(),
                'transcriptionState' => $callSession->getTranscriptionState(),
            ]);
        } catch (\RuntimeException $exception) {
            return $this->json(['ok' => false, 'error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/crm/properties/{propertyId<\d+>}/contacts/{contactId<\d+>}/browser-call/dtmf', name: 'crm_property_contact_browser_call_dtmf', methods: ['POST'])]
    public function dtmf(
        int $propertyId,
        int $contactId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propRepo,
        ContactRepository $contactRepo,
        TenantMembershipAccessService $membershipAccess,
        CallSessionRepository $sessions,
        BrowserSoftphoneSessionService $browserSoftphoneSessions,
        TelnyxCallControlService $callControl,
    ): JsonResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $membershipAccess->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_DISPATCH,
            UserTenantMembership::ROLE_SALES,
        ]);

        $property = $propRepo->findOneByTenantAndId($tenant, $propertyId);
        $contact = $contactRepo->findOneByTenantAndId($tenant, $contactId);
        if (null === $property || null === $contact) {
            return $this->json(['ok' => false, 'error' => 'Property or contact not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $property);
        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $contact);

        if (!$this->isCsrfTokenValid($this->browserCallTokenId($propertyId, $contactId), (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        if (null === $this->getUser()) {
            return $this->json(['ok' => false, 'error' => 'You must be logged in to send DTMF.'], Response::HTTP_FORBIDDEN);
        }

        $providerSessionId = $request->request->get('providerSessionId', '');
        $digits = (string) ($request->request->get('digits', ''));

        if ('' === trim((string) $providerSessionId) || '' === trim($digits)) {
            return $this->json(['ok' => false, 'error' => 'Provider session ID and digits are required.'], Response::HTTP_BAD_REQUEST);
        }

        $callSession = $sessions->findOneBy(['providerSessionId' => trim($providerSessionId)]);
        if (null === $callSession || CallSession::CALL_MODE_BROWSER !== $callSession->getCallMode()) {
            return $this->json(['ok' => false, 'error' => 'Active browser call session required.'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->json(['ok' => false, 'error' => 'You must be logged in to send DTMF.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $browserSession = $browserSoftphoneSessions->findByProviderSessionId($tenant, $user, trim($providerSessionId));
        } catch (\RuntimeException $exception) {
            return $this->json(['ok' => false, 'error' => 'Browser softphone session not found.'], Response::HTTP_NOT_FOUND);
        }

        $callControlId = $browserSession->getTelnyxCallControlId();
        if (null === $callControlId || '' === trim($callControlId)) {
            return $this->json(['ok' => false, 'error' => 'Browser call control ID is not available yet.'], Response::HTTP_BAD_REQUEST);
        }

        // Send DTMF via Telnyx Call Control API (platform-level)
        // Browser-side SDK also sends DTMF locally for immediate UX feedback.
        try {
            $callControl->playDtmf($callControlId, trim($digits));
        } catch (\Throwable $exception) {
            return $this->json(['ok' => false, 'error' => 'DTMF send failed: '.$exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['ok' => true, 'digits' => trim($digits)]);
    }

    #[Route('/crm/properties/{propertyId<\d+>}/contacts/{contactId<\d+>}/browser-call/mute', name: 'crm_property_contact_browser_call_mute', methods: ['POST'])]
    public function mute(
        int $propertyId,
        int $contactId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propRepo,
        ContactRepository $contactRepo,
        TenantMembershipAccessService $membershipAccess,
        CallSessionRepository $sessions,
        BrowserSoftphoneSessionService $browserSoftphoneSessions,
        TelnyxCallControlService $callControl,
    ): JsonResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $membershipAccess->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_DISPATCH,
            UserTenantMembership::ROLE_SALES,
        ]);

        $property = $propRepo->findOneByTenantAndId($tenant, $propertyId);
        $contact = $contactRepo->findOneByTenantAndId($tenant, $contactId);
        if (null === $property || null === $contact) {
            return $this->json(['ok' => false, 'error' => 'Property or contact not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $property);
        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $contact);

        if (!$this->isCsrfTokenValid($this->browserCallTokenId($propertyId, $contactId), (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        if (null === $this->getUser()) {
            return $this->json(['ok' => false, 'error' => 'You must be logged in to mute.'], Response::HTTP_FORBIDDEN);
        }

        $providerSessionId = $request->request->get('providerSessionId', '');
        if ('' === trim((string) $providerSessionId)) {
            return $this->json(['ok' => false, 'error' => 'Provider session ID is required.'], Response::HTTP_BAD_REQUEST);
        }

        $callSession = $sessions->findOneBy(['providerSessionId' => trim($providerSessionId)]);
        if (null === $callSession || CallSession::CALL_MODE_BROWSER !== $callSession->getCallMode()) {
            return $this->json(['ok' => false, 'error' => 'Active browser call session required.'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->json(['ok' => false, 'error' => 'You must be logged in to mute.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $browserSession = $browserSoftphoneSessions->findByProviderSessionId($tenant, $user, trim($providerSessionId));
        } catch (\RuntimeException $exception) {
            return $this->json(['ok' => false, 'error' => 'Browser softphone session not found.'], Response::HTTP_NOT_FOUND);
        }

        $callControlId = $browserSession->getTelnyxCallControlId();
        if (null === $callControlId || '' === trim($callControlId)) {
            return $this->json(['ok' => false, 'error' => 'Browser call control ID is not available yet.'], Response::HTTP_BAD_REQUEST);
        }

        $mute = strtolower((string) ($request->request->get('action', ''))) === 'mute';
        $state = $mute ? 'muted' : 'unmuted';

        // Try platform-side mute (Telnyx pause/resume)
        try {
            if ($mute) {
                $callControl->mute($callControlId, true);
            }
        } catch (\Throwable) {
            // Best effort: browser-side SDK media track toggle handles local mute.
        }

        return $this->json(['ok' => true, 'state' => $state]);
    }

    private function browserCallTokenId(int $propertyId, int $contactId): string
    {
        return sprintf('crm_browser_call_%d_%d', $propertyId, $contactId);
    }

    /**
     * Start capture for a browser call session: consent playback, recording, transcription.
     */
    private function startCapture(
        CallSession $callSession,
        CallCaptureControlService $captureControl,
        AuditLogger $auditLogger,
        string $callControlId,
    ): void {
        $captureControl->playConsentMessage(
            $callSession,
            null, // No CallLeg for direct WebRTC; consent is logged locally.
            'This call will be recorded for transcription and quality purposes.',
            'browser-capture',
            $callControlId,
        );

        $captureControl->startRecording(
            $callSession,
            null,
            new CapturePolicy(recordAudio: true, transcribeAudio: true),
            'browser-capture',
            $callControlId,
        );

        $captureControl->startTranscription(
            $callSession,
            null,
            new CapturePolicy(recordAudio: true, transcribeAudio: true),
            'browser-capture',
            $callControlId,
        );

        $auditLogger->log(
            $callSession->getTenant(),
            'call_session',
            (string) ($callSession->getId() ?? 'new'),
            'call.browser_capture_started',
            null,
            [
                'recordingState' => CallSession::RECORDING_STATE_ACTIVE,
                'transcriptionState' => CallSession::TRANSCRIPTION_STATE_ACTIVE,
            ],
            ['callControlId' => $callControlId],
        );
    }
}
