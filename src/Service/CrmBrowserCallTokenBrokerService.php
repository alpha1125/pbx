<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\User;
use App\Repository\CallSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class CrmBrowserCallTokenBrokerService
{
    public function __construct(
        private readonly CallSessionRepository $sessions,
        private readonly EntityManagerInterface $entityManager,
        private readonly CrmInputNormalizer $normalizer,
        private readonly TelnyxWebrtcTokenService $tokenService,
        private readonly BrowserCallTokenIssueGuard $issueGuard,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @return array{
     *   callSession: CallSession,
     *   token: string,
     *   tokenExpiresAt: \DateTimeImmutable,
     *   approvedDestinationNumber: string,
     *   statusStreamUrl: string,
     *   callMode: string
     * }
     */
    public function prepare(Property $property, Contact $contact, User $user): array
    {
        $tenant = $property->getTenant();
        if (null === $tenant) {
            throw new \RuntimeException('Browser call token issuance requires a tenant-scoped property.');
        }

        $destination = $this->normalizer->normalizePhoneOrNull($contact->getPrimaryPhone());
        if (null === $destination || !preg_match('/^\+[1-9][0-9]{9,14}$/', $destination)) {
            throw new \RuntimeException('This contact does not have a valid primary phone number.');
        }

        $this->issueGuard->assertAllowed($tenant, $property, $contact, $user);

        $session = $this->sessions->findLatestBrowserCallIntent($tenant, $property, $contact, $user);
        if (null === $session) {
            $session = (new CallSession('browser-intent-'.Uuid::v7()->toRfc4122()))
                ->setProvider('telnyx')
                ->setTenant($tenant)
                ->setProperty($property)
                ->setContact($contact)
                ->setCsrUser($user)
                ->setCallMode(CallSession::CALL_MODE_BROWSER)
                ->setCallState(CallSession::CALL_STATE_INITIATED)
                ->setRecordingState(CallSession::RECORDING_STATE_INACTIVE)
                ->setTranscriptionState(CallSession::TRANSCRIPTION_STATE_INACTIVE)
                ->setClientPhoneNumber($destination)
                ->setFlowType(CallSession::FLOW_TYPE_CLICK_TO_CALL)
                ->setInboundTo($destination)
                ->setStatus('initiated')
                ->touch();

            $this->entityManager->persist($session);
        } else {
            $session
                ->setTenant($tenant)
                ->setProperty($property)
                ->setContact($contact)
                ->setCsrUser($user)
                ->setCallMode(CallSession::CALL_MODE_BROWSER)
                ->setCallState(CallSession::CALL_STATE_INITIATED)
                ->setRecordingState(CallSession::RECORDING_STATE_INACTIVE)
                ->setTranscriptionState(CallSession::TRANSCRIPTION_STATE_INACTIVE)
                ->setClientPhoneNumber($destination)
                ->setFlowType(CallSession::FLOW_TYPE_CLICK_TO_CALL)
                ->setInboundTo($destination)
                ->setStatus('initiated')
                ->touch();
        }

        $this->entityManager->flush();

        try {
            $token = $this->tokenService->issue($tenant, $user, $session);
        } catch (\Throwable $exception) {
            $session
                ->setStatus('failed')
                ->setCallState(CallSession::CALL_STATE_FAILED)
                ->touch();
            $this->entityManager->flush();

            throw $exception;
        }

        $this->auditLogger->log(
            $tenant,
            'call_session',
            (string) ($session->getId() ?? 'new'),
            'call.browser_call_token_issued',
            null,
            [
                'status' => $session->getStatus(),
                'callMode' => $session->getCallMode(),
                'callState' => $session->getCallState(),
                'tokenExpiresAt' => $token['expiresAt']->format(DATE_ATOM),
                'approvedDestinationNumber' => $destination,
            ],
            [
                'propertyId' => $property->getId(),
                'contactId' => $contact->getId(),
                'providerSessionId' => $session->getProviderSessionId(),
                'callSessionId' => $session->getId(),
            ],
        );

        $this->entityManager->flush();

        return [
            'callSession' => $session,
            'token' => $token['token'],
            'tokenExpiresAt' => $token['expiresAt'],
            'approvedDestinationNumber' => $destination,
            'statusStreamUrl' => sprintf('/api/calls/%s/events/stream', $session->getProviderSessionId()),
            'callMode' => CallSession::CALL_MODE_BROWSER,
        ];
    }
}
