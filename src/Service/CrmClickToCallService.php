<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class CrmClickToCallService
{
    public function __construct(
        private readonly ClickToCallService $clickToCallService,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function start(Property $property, Contact $contact): CallSession
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('You must be logged in to place a CRM bridge call.');
        }

        if (null === $user->getCellPhone() || '' === trim($user->getCellPhone())) {
            throw new \RuntimeException('Your user profile does not have a cell phone number configured.');
        }

        if (null === $contact->getPrimaryPhone() || '' === trim($contact->getPrimaryPhone())) {
            throw new \RuntimeException('This contact does not have a primary phone number.');
        }

        $request = $this->clickToCallService->start(
            $contact->getPrimaryPhone(),
            $contact->getDisplayName(),
            $user->getCellPhone(),
        );

        $session = $request->getCallSession() ?? throw new \RuntimeException('Bridge call did not create a call session.');
        $session
            ->setCallMode(CallSession::CALL_MODE_BRIDGE)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setRecordingState(CallSession::RECORDING_STATE_INACTIVE)
            ->setTranscriptionState(CallSession::TRANSCRIPTION_STATE_INACTIVE)
            ->setClientPhoneNumber($contact->getPrimaryPhone())
            ->setCsrUser($user)
            ->setTenant($property->getTenant())
            ->setProperty($property)
            ->setContact($contact)
            ->setFlowType(CallSession::FLOW_TYPE_CLICK_TO_CALL)
            ->touch();

        $this->auditLogger->log(
            $property->getTenant(),
            'call_session',
            (string) ($session->getId() ?? 'new'),
            'call.bridge_call_started',
            null,
            ['status' => $session->getStatus(), 'flowType' => $session->getFlowType()],
            ['propertyId' => $property->getId(), 'contactId' => $contact->getId()],
        );

        $this->entityManager->flush();

        return $session;
    }
}
