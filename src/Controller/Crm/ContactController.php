<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\Contact;
use App\Entity\PropertyContact;
use App\Repository\ContactRepository;
use App\Repository\PropertyContactRepository;
use App\Repository\PropertyRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\AuditLogger;
use App\Service\CrmInputNormalizer;
use App\Service\CurrentTenantProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ContactController extends AbstractController
{
    #[Route('/crm/properties/{propertyId<\d+>}/contacts/new', name: 'crm_contact_new', methods: ['GET', 'POST'])]
    public function create(
        int $propertyId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        PropertyContactRepository $propertyContactRepository,
        ContactRepository $contactRepository,
        CrmInputNormalizer $normalizer,
        ValidatorInterface $validator,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): Response|RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = $propertyRepository->findOneByTenantAndId($tenant, $propertyId);
        if (null === $property) {
            throw $this->createNotFoundException('Property not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $property);

        $contact = new Contact($tenant, '');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm_contact_form', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $this->applyContactForm($contact, $request, $normalizer);
            $errors = $validator->validate($contact);
            if (0 === count($errors)) {
                $existingContact = $contactRepository->findOneForRfqCustomer(
                    $tenant,
                    $contact->getPrimaryEmail(),
                    $contact->getPrimaryPhone(),
                    $contact->getDisplayName(),
                );
                if ($existingContact instanceof Contact) {
                    $existingLink = $propertyContactRepository->findOneByTenantPropertyAndContact($tenant, $property, $existingContact);
                    if ($existingLink instanceof PropertyContact) {
                        $this->addFlash('error', 'This contact is already linked to the property.');

                        return $this->redirectToRoute('crm_property_show', ['id' => $property->getId()]);
                    }

                    $contact = $existingContact;
                    $this->addFlash('success', 'Matched an existing contact and linked it to the property.');
                } else {
                    $entityManager->persist($contact);
                    $entityManager->flush();
                }

                $propertyContact = $propertyContactRepository->findAnyByTenantPropertyAndContact($tenant, $property, $contact);
                if ($propertyContact instanceof PropertyContact) {
                    $propertyContact
                        ->setEndDate(null)
                        ->setRelationshipType((string) ($normalizer->stringOrNull($request->request->get('relationshipType')) ?? PropertyContact::RELATIONSHIP_OTHER))
                        ->setIsPrimary('1' === (string) $request->request->get('isPrimary'))
                        ->touch();
                } else {
                    $propertyContact = (new PropertyContact($tenant, $property, $contact))
                        ->setRelationshipType((string) ($normalizer->stringOrNull($request->request->get('relationshipType')) ?? PropertyContact::RELATIONSHIP_OTHER))
                        ->setIsPrimary('1' === (string) $request->request->get('isPrimary'));
                    $entityManager->persist($propertyContact);
                }

                if ($propertyContact->isPrimary()) {
                    $this->resetPrimaryContacts($propertyContactRepository, $property->getId(), $propertyContact->getId());
                }

                $auditLogger->log($tenant, 'contact', 'new', 'contact.created', null, [
                    'displayName' => $contact->getDisplayName(),
                    'propertyId' => $property->getId(),
                ]);
                $entityManager->flush();
                $this->addFlash('success', 'Contact created.');

                return $this->redirectToRoute('crm_property_show', ['id' => $property->getId()]);
            }

            $this->addFlash('error', (string) $errors[0]->getMessage());
        }

        return $this->render('crm/contact/form.html.twig', [
            'contact' => $contact,
            'property' => $property,
            'formAction' => $this->generateUrl('crm_contact_new', ['propertyId' => $property->getId()]),
            'title' => 'Add Contact',
        ]);
    }

    #[Route('/crm/properties/{propertyId<\d+>}/contacts/{contactId<\d+>}/edit', name: 'crm_contact_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $propertyId,
        int $contactId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        ContactRepository $contactRepository,
        PropertyContactRepository $propertyContactRepository,
        CrmInputNormalizer $normalizer,
        ValidatorInterface $validator,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): Response|RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = $propertyRepository->findOneByTenantAndId($tenant, $propertyId);
        $contact = $contactRepository->findOneByTenantAndId($tenant, $contactId);
        if (null === $property || null === $contact) {
            throw $this->createNotFoundException('Property or contact not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $property);
        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $contact);

        $propertyContact = $propertyContactRepository->findOneByTenantPropertyAndContact($tenant, $property, $contact);
        if (null === $propertyContact) {
            throw $this->createNotFoundException('Property contact not found.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm_contact_form', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $this->applyContactForm($contact, $request, $normalizer);
            $propertyContact
                ->setRelationshipType((string) ($normalizer->stringOrNull($request->request->get('relationshipType')) ?? PropertyContact::RELATIONSHIP_OTHER))
                ->setIsPrimary('1' === (string) $request->request->get('isPrimary'))
                ->touch();

            $errors = $validator->validate($contact);
            if (0 === count($errors)) {
                $contact->touch();
                if ($propertyContact->isPrimary()) {
                    $this->resetPrimaryContacts($propertyContactRepository, $property->getId(), $propertyContact->getId());
                }
                $auditLogger->log($tenant, 'contact', (string) $contact->getId(), 'contact.updated', null, [
                    'displayName' => $contact->getDisplayName(),
                    'propertyId' => $property->getId(),
                ]);
                $entityManager->flush();
                $this->addFlash('success', 'Contact updated.');

                return $this->redirectToRoute('crm_property_show', ['id' => $property->getId()]);
            }

            $this->addFlash('error', (string) $errors[0]->getMessage());
        }

        return $this->render('crm/contact/form.html.twig', [
            'contact' => $contact,
            'property' => $property,
            'propertyContact' => $propertyContact,
            'formAction' => $this->generateUrl('crm_contact_edit', ['propertyId' => $property->getId(), 'contactId' => $contact->getId()]),
            'title' => 'Edit Contact',
        ]);
    }

    #[Route('/crm/properties/{propertyId<\d+>}/contacts/{contactId<\d+>}/archive', name: 'crm_contact_archive', methods: ['POST'])]
    public function archive(
        int $propertyId,
        int $contactId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        ContactRepository $contactRepository,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = $propertyRepository->findOneByTenantAndId($tenant, $propertyId);
        $contact = $contactRepository->findOneByTenantAndId($tenant, $contactId);
        if (null === $property || null === $contact) {
            throw $this->createNotFoundException('Property or contact not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $contact);

        if (!$this->isCsrfTokenValid('crm_contact_archive_'.$contact->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $contact->archive()->touch();
        $auditLogger->log($tenant, 'contact', (string) $contact->getId(), 'contact.archived');
        $entityManager->flush();
        $this->addFlash('success', 'Contact archived.');

        return $this->redirectToRoute('crm_property_show', ['id' => $property->getId()]);
    }

    #[Route('/crm/properties/{propertyId<\d+>}/contacts/{contactId<\d+>}/link/archive', name: 'crm_property_contact_archive', methods: ['POST'])]
    public function archivePropertyContactLink(
        int $propertyId,
        int $contactId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        ContactRepository $contactRepository,
        PropertyContactRepository $propertyContactRepository,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = $propertyRepository->findOneByTenantAndId($tenant, $propertyId);
        $contact = $contactRepository->findOneByTenantAndId($tenant, $contactId);
        if (null === $property || null === $contact) {
            throw $this->createNotFoundException('Property or contact not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $property);

        $propertyContact = $propertyContactRepository->findOneByTenantPropertyAndContact($tenant, $property, $contact);
        if (null === $propertyContact) {
            throw $this->createNotFoundException('Property contact not found.');
        }

        if (!$this->isCsrfTokenValid('crm_property_contact_archive_'.$property->getId().'_'.$contact->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $propertyContact
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setIsPrimary(false)
            ->touch();

        $replacementPrimary = $propertyContactRepository->findPrimaryByProperty($property);
        if (null === $replacementPrimary) {
            $remainingContacts = $propertyContactRepository->findByProperty($property);
            if ([] !== $remainingContacts) {
                $remainingContacts[0]->setIsPrimary(true)->touch();
            }
        }

        $auditLogger->log($tenant, 'property_contact', (string) $propertyContact->getId(), 'property_contact.archived', null, [
            'propertyId' => $property->getId(),
            'contactId' => $contact->getId(),
        ]);
        $entityManager->flush();
        $this->addFlash('success', 'Property contact link archived.');

        return $this->redirectToRoute('crm_property_show', ['id' => $property->getId()]);
    }

    private function applyContactForm(Contact $contact, Request $request, CrmInputNormalizer $normalizer): void
    {
        $displayName = $normalizer->stringOrNull($request->request->get('displayName'));
        $firstName = $normalizer->stringOrNull($request->request->get('firstName'));
        $lastName = $normalizer->stringOrNull($request->request->get('lastName'));
        if (null === $displayName) {
            $displayName = trim((string) preg_replace('/\s+/', ' ', sprintf('%s %s', $firstName ?? '', $lastName ?? '')));
        }

        $contact
            ->setDisplayName($displayName ?: '')
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setCompanyName($normalizer->stringOrNull($request->request->get('companyName')))
            ->setPrimaryPhone($normalizer->normalizePhoneOrNull($request->request->get('primaryPhone')))
            ->setPrimaryEmail($normalizer->normalizeEmailOrNull($request->request->get('primaryEmail')))
            ->setNotes($normalizer->stringOrNull($request->request->get('notes')));
    }

    private function resetPrimaryContacts(PropertyContactRepository $repository, int $propertyId, ?int $keepId): void
    {
        foreach ($repository->findByPropertyId($propertyId) as $propertyContact) {
            if ($propertyContact->getId() !== $keepId) {
                $propertyContact->setIsPrimary(false)->touch();
            }
        }
    }
}
