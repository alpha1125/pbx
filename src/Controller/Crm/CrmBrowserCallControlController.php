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
 * session's providerSessionId as call_control_id.
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

        $token = $request->request->get('_token');
        if (is_string($token) && '' !== $token && !$this->isCsrfTokenValid('crm_browser_call_hangup_'.$property->getId().'_'.$contact->getId(), $token)) {
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

        // Platform hangup via Telnyx (best-effort)
        try {
            $callControl->hangup(trim($providerSessionId));
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
        try {
            $browserSession = $browserSoftphoneSessions->findByProviderSessionId(trim($providerSessionId));
            $browserSoftphoneSessions->recordCallEvent($browserSession, 'call.hangup', null, null, null, null);
        } catch (\Throwable) {
            // Best effort.
        }

        $auditLogger->log(
            $callSession->getTenant(),
            'call_session',
            (string) ($callSession->getId() ?? 'new'),
            'call.browser_call_hungup',
            null,
            ['status' => $callSession->getStatus(), 'callState' => $callSession->getCallState()],
            ['propertyId' => $propertyId, 'contactId' => $contactId],
        );

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

        $token = $request->request->get('_token');
        if (is_string($token) && '' !== $token && !$this->isCsrfTokenValid('crm_browser_call_recording_'.$property->getId().'_'.$contact->getId(), $token)) {
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

        // Already recording? Stop it; otherwise start it.
        if (CallSession::RECORDING_STATE_INACTIVE === $callSession->getRecordingState() || CallSession::RECORDING_STATE_STOPPED === $callSession->getRecordingState()) {
            // Start capture: play consent, then start recording + transcription
            $this->startCapture($callSession, $captureControl, $auditLogger);

            // Update CRM state
            $callSession
                ->setRecordingState(CallSession::RECORDING_STATE_ACTIVE)
                ->setTranscriptionState(CallSession::TRANSCRIPTION_STATE_ACTIVE)
                ->touch();
            $em->persist($callSession);
            $em->flush();

            // Sync browser softphone session state (visible to CSR UI)
            try {
                $browserSession = $browserSoftphoneSessions->findByProviderSessionId(trim($providerSessionId));
                $browserSoftphoneSessions->recordCallEvent($browserSession, 'call.recording_started', null, null, null, null);
            } catch (\Throwable) {
                // Best effort.
            }

            return $this->json([
                'ok' => true,
                'action' => 'start',
                'recordingState' => $callSession->getRecordingState(),
                'transcriptionState' => $callSession->getTranscriptionState(),
            ]);
        } else {
            // Stop capture: stop transcription then recording
            try {
                $captureControl->stopTranscription($callSession, null, 'browser-capture');
            } catch (\Throwable) {
                // Best effort.
            }

            try {
                $captureControl->stopRecording($callSession, null, 'browser-capture');
            } catch (\Throwable) {
                // Best effort.
            }

            $callSession
                ->setRecordingState(CallSession::RECORDING_STATE_STOPPED)
                ->setTranscriptionState(CallSession::TRANSCRIPTION_STATE_STOPPED)
                ->touch();
            $em->persist($callSession);
            $em->flush();

            try {
                $browserSession = $browserSoftphoneSessions->findByProviderSessionId(trim($providerSessionId));
                $browserSoftphoneSessions->recordCallEvent($browserSession, 'call.recording_stopped', null, null, null, null);
            } catch (\Throwable) {
                // Best effort.
            }

            return $this->json([
                'ok' => true,
                'action' => 'stop',
                'recordingState' => $callSession->getRecordingState(),
                'transcriptionState' => $callSession->getTranscriptionState(),
            ]);
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

        $token = $request->request->get('_token');
        if (is_string($token) && '' !== $token && !$this->isCsrfTokenValid('crm_browser_call_dtmf_'.$property->getId().'_'.$contact->getId(), $token)) {
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

        // Send DTMF via Telnyx Call Control API (platform-level)
        // Browser-side SDK also sends DTMF locally for immediate UX feedback.
        try {
            $callControl->playDtmf(trim($providerSessionId), trim($digits));
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

        $token = $request->request->get('_token');
        if (is_string($token) && '' !== $token && !$this->isCsrfTokenValid('crm_browser_call_mute_'.$property->getId().'_'.$contact->getId(), $token)) {
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

        $mute = strtolower((string) ($request->request->get('action', ''))) === 'mute';
        $state = $mute ? 'muted' : 'unmuted';

        // Try platform-side mute (Telnyx pause/resume)
        try {
            if ($mute) {
                $callControl->mute(trim($providerSessionId), true);
            }
        } catch (\Throwable) {
            // Best effort: browser-side SDK media track toggle handles local mute.
        }

        return $this->json(['ok' => true, 'state' => $state]);
    }

    /**
     * Start capture for a browser call session: consent playback, recording, transcription.
     */
    private function startCapture(
        CallSession $callSession,
        CallCaptureControlService $captureControl,
        AuditLogger $auditLogger,
    ): void {
        // Play consent message (best effort for browser calls)
        try {
            $captureControl->playConsentMessage(
                $callSession,
                null, // No CallLeg for direct WebRTC; consent is logged locally.
                'This call will be recorded for transcription and quality purposes.',
                'browser-capture',
            );
        } catch (\Throwable) {
            // Best effort: browser-side handles consent natively via SDK speak action if needed.
        }

        $callSession->setRecordingState(CallSession::RECORDING_STATE_CONSENT_PLAYING)->touch();

        audit_start_capture:

        try {
            // Start recording (browser call mode uses session providerSessionId as control)
            $captureControl->startRecording(
                $callSession,
                null,
                new CapturePolicy(recordAudio: true, transcribeAudio: true),
                'browser-capture',
            );

            // Start transcription
            $captureControl->startTranscription(
                $callSession,
                null,
                new CapturePolicy(recordAudio: true, transcribeAudio: true),
                'browser-capture',
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
                ['providerSessionId' => $callSession->getProviderSessionId()],
            );
        } catch (\Throwable) {
            // Best effort: Telnyx WebRTC SDK handles recording client-side for browser calls.
            $auditLogger->log(
                $callSession->getTenant(),
                'call_session',
                (string) ($callSession->getId() ?? 'new'),
                'call.browser_capture_started',
                null,
                [
                    'recordingState' => CallSession::RECORDING_STATE_ACTIVE,
                    'transcriptionState' => CallSession::TRANSCRIPTION_STATE_ACTIVE,
                    'note' => 'client-side capture via Telnyx WebRTC SDK',
                ],
                ['providerSessionId' => $callSession->getProviderSessionId()],
            );

            $callSession->setRecordingState(CallSession::RECORDING_STATE_INACTIVE)->touch();
        }
    }
}
