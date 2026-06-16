<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contact;
use App\Entity\Estimate;
use App\Entity\Property;
use App\Entity\PropertyContact;
use App\Entity\RfqInvitation;
use App\Repository\ContactRepository;
use App\Repository\PropertyContactRepository;
use App\Repository\PropertyRepository;
use Doctrine\ORM\EntityManagerInterface;

class RfqAcceptanceService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PropertyRepository $propertyRepository,
        private readonly ContactRepository $contactRepository,
        private readonly PropertyContactRepository $propertyContactRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function acceptInvitation(RfqInvitation $invitation): Estimate
    {
        return $this->entityManager->wrapInTransaction(function () use ($invitation): Estimate {
            if (!in_array($invitation->getStatus(), [RfqInvitation::STATUS_SENT, RfqInvitation::STATUS_VIEWED], true)) {
                throw new \RuntimeException(sprintf('RFQ invitation %d cannot be accepted from status "%s".', $invitation->getId(), $invitation->getStatus()));
            }

            $tenant = $invitation->getTenant();
            $rfq = $invitation->getRfq();

            $property = $this->propertyRepository->findOneByTenantAndAddress(
                $tenant,
                $rfq->getAddressLine1(),
                $rfq->getAddressLine2(),
                $rfq->getCity(),
                $rfq->getProvince(),
                $rfq->getPostalCode(),
                $rfq->getCountry(),
            );
            $propertyWasCreated = false;

            if (null === $property) {
                $property = (new Property($tenant, $rfq->getAddressLine1(), $rfq->getCity(), $rfq->getProvince(), $rfq->getPostalCode()))
                    ->setAddressLine2($rfq->getAddressLine2())
                    ->setCountry($rfq->getCountry())
                    ->setNotes($rfq->getDescription());
                $this->entityManager->persist($property);
                $propertyWasCreated = true;
            }

            $contact = $this->contactRepository->findOneForRfqCustomer(
                $tenant,
                $rfq->getCustomerEmail(),
                $rfq->getCustomerPhone(),
                $rfq->getCustomerName(),
            );
            $contactWasCreated = false;

            if (null === $contact) {
                $displayName = $rfq->getCustomerName() ?: 'RFQ Contact';
                $contact = (new Contact($tenant, $displayName))
                    ->setPrimaryPhone($rfq->getCustomerPhone())
                    ->setPrimaryEmail($rfq->getCustomerEmail());
                $this->entityManager->persist($contact);
                $contactWasCreated = true;
            }

            if ($propertyWasCreated || $contactWasCreated) {
                $this->entityManager->flush();
            }

            $propertyContact = $this->propertyContactRepository->findOneByTenantPropertyAndContact($tenant, $property, $contact);
            if (null === $propertyContact) {
                $propertyContact = (new PropertyContact($tenant, $property, $contact))
                    ->setRelationshipType(PropertyContact::RELATIONSHIP_OWNER)
                    ->setIsPrimary(true)
                    ->setStartDate(new \DateTimeImmutable('today'));
                $this->entityManager->persist($propertyContact);
            }

            $estimate = (new Estimate($tenant, $property))
                ->setContact($contact)
                ->setRfqInvitation($invitation)
                ->setStatus(Estimate::STATUS_DRAFT)
                ->setTitle(sprintf('Estimate for %s', $property->getDisplayAddress()))
                ->setNotes($rfq->getDescription());
            $this->entityManager->persist($estimate);

            $beforeInvitation = ['status' => $invitation->getStatus()];
            $invitation
                ->setStatus(RfqInvitation::STATUS_ACCEPTED_FOR_QUOTE)
                ->setAcceptedAt(new \DateTimeImmutable())
                ->setCreatedProperty($property)
                ->setCreatedEstimate($estimate)
                ->touch();

            $this->entityManager->flush();

            $this->auditLogger->log(
                $tenant,
                'rfq_invitation',
                (string) $invitation->getId(),
                'rfq.accepted',
                $beforeInvitation,
                ['status' => $invitation->getStatus()],
                ['rfqId' => $rfq->getId(), 'propertyId' => $property->getId()],
            );

            if ($propertyWasCreated) {
                $this->auditLogger->log(
                    $tenant,
                    'property',
                    (string) $property->getId(),
                    'property.created_from_rfq',
                    null,
                    ['address' => $property->getDisplayAddress()],
                    ['rfqInvitationId' => $invitation->getId()],
                );
            }

            if ($contactWasCreated) {
                $this->auditLogger->log(
                    $tenant,
                    'contact',
                    (string) $contact->getId(),
                    'contact.created_from_rfq',
                    null,
                    ['displayName' => $contact->getDisplayName()],
                    ['rfqInvitationId' => $invitation->getId()],
                );
            }

            $this->auditLogger->log(
                $tenant,
                'estimate',
                (string) $estimate->getId(),
                'estimate.created_from_rfq',
                null,
                ['status' => $estimate->getStatus(), 'title' => $estimate->getTitle()],
                ['rfqInvitationId' => $invitation->getId(), 'propertyId' => $property->getId()],
            );

            $this->entityManager->flush();

            return $estimate;
        });
    }

    public function declineInvitation(RfqInvitation $invitation): void
    {
        $this->entityManager->wrapInTransaction(function () use ($invitation): void {
            if (!in_array($invitation->getStatus(), [RfqInvitation::STATUS_SENT, RfqInvitation::STATUS_VIEWED], true)) {
                throw new \RuntimeException(sprintf('RFQ invitation %d cannot be declined from status "%s".', $invitation->getId(), $invitation->getStatus()));
            }

            $before = ['status' => $invitation->getStatus()];
            $invitation
                ->setStatus(RfqInvitation::STATUS_DECLINED)
                ->setDeclinedAt(new \DateTimeImmutable())
                ->touch();

            $this->auditLogger->log(
                $invitation->getTenant(),
                'rfq_invitation',
                (string) $invitation->getId(),
                'rfq.declined',
                $before,
                ['status' => $invitation->getStatus()],
                ['rfqId' => $invitation->getRfq()->getId()],
            );

            $this->entityManager->flush();
        });
    }
}
