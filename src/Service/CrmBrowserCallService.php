<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

final class CrmBrowserCallService
{
    public function __construct(
        private readonly TelnyxCallControlService $callControl,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly AuditLogger $auditLogger,
        private readonly ClientStateService $clientState,
        private readonly string $fromNumber,
        private readonly string $connectionId,
    ) {
    }

    public function start(Property $property, Contact $contact): CallSession
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('You must be logged in to place a CRM browser call.');
        }

        if (null === $contact->getPrimaryPhone() || '' === trim($contact->getPrimaryPhone())) {
            throw new \RuntimeException('This contact does not have a primary phone number.');
        }

        if ('' === trim($this->connectionId)) {
            throw new \RuntimeException('TELNYX_CONNECTION_ID is missing.');
        }

        if ('' === trim($this->fromNumber)) {
            throw new \RuntimeException('TELNYX_FROM_NUMBER is missing.');
        }

        $session = (new CallSession('local-browser-'.Uuid::v7()->toRfc4122()))
            ->setProvider('telnyx')
            ->setTenant($property->getTenant())
            ->setProperty($property)
            ->setContact($contact)
            ->setCsrUser($user)
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setRecordingState(CallSession::RECORDING_STATE_INACTIVE)
            ->setTranscriptionState(CallSession::TRANSCRIPTION_STATE_INACTIVE)
            ->setClientPhoneNumber($contact->getPrimaryPhone())
            ->setFlowType(CallSession::FLOW_TYPE_CLICK_TO_CALL)
            ->setInboundFrom($this->fromNumber)
            ->setInboundTo($contact->getPrimaryPhone())
            ->setStatus('initiated')
            ->touch();

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $clientState = $this->clientState->encode([
            'flow' => CallSession::FLOW_TYPE_CLICK_TO_CALL,
            'root_call_session_id' => $session->getProviderSessionId(),
            'call_mode' => CallSession::CALL_MODE_BROWSER,
            'propertyId' => $property->getId(),
            'contactId' => $contact->getId(),
        ]);

        try {
            $this->callControl->dial(
                $this->connectionId,
                $this->fromNumber,
                $contact->getPrimaryPhone(),
                $clientState,
                45,
                sprintf('browser-call:%s:dial', $session->getProviderSessionId()),
            );
        } catch (\Throwable $exception) {
            $session
                ->setStatus('failed')
                ->setCallState(CallSession::CALL_STATE_FAILED)
                ->touch();
            $this->entityManager->flush();

            throw $exception;
        }

        $this->auditLogger->log(
            $property->getTenant(),
            'call_session',
            (string) ($session->getId() ?? 'new'),
            'call.browser_call_started',
            null,
            ['status' => $session->getStatus(), 'flowType' => $session->getFlowType(), 'callMode' => $session->getCallMode()],
            ['propertyId' => $property->getId(), 'contactId' => $contact->getId()],
        );

        $this->entityManager->flush();

        return $session;
    }
}
