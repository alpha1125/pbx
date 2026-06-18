<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\CsrPlaybookAttachment;
use App\Repository\ContactRepository;
use App\Repository\CsrPlaybookAttachmentRepository;
use App\Repository\PropertyRepository;
use App\Repository\RetentionOpportunityRepository;
use App\Service\CsrPlaybookEngineService;
use App\Service\CurrentTenantProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CsrPlaybookController extends AbstractController
{
    #[Route('/crm/playbooks/attach', name: 'crm_csr_playbook_attach', methods: ['POST'])]
    public function attach(
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        ContactRepository $contactRepository,
        RetentionOpportunityRepository $retentionOpportunityRepository,
        CsrPlaybookAttachmentRepository $attachmentRepository,
        CsrPlaybookEngineService $playbookEngine,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $playbookType = (string) $request->request->get('playbookType', '');
        $playbook = $playbookEngine->get($playbookType);
        if (null === $playbook) {
            throw $this->createNotFoundException('Playbook not found.');
        }

        if (!$this->isCsrfTokenValid($this->csrfTokenId($playbookType, $request), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $propertyId = (int) $request->request->get('propertyId', 0);
        $contactId = (int) $request->request->get('contactId', 0);
        $retentionOpportunityId = (int) $request->request->get('retentionOpportunityId', 0);

        $property = $propertyId > 0 ? $propertyRepository->findOneByTenantAndId($tenant, $propertyId) : null;
        if ($propertyId > 0 && null === $property) {
            throw $this->createNotFoundException('Property not found.');
        }

        $contact = $contactId > 0 ? $contactRepository->findOneByTenantAndId($tenant, $contactId) : null;
        if ($contactId > 0 && null === $contact) {
            throw $this->createNotFoundException('Contact not found.');
        }

        $retentionOpportunity = $retentionOpportunityId > 0 ? $retentionOpportunityRepository->findOneByTenantAndId($tenant, $retentionOpportunityId) : null;
        if ($retentionOpportunityId > 0 && null === $retentionOpportunity) {
            throw $this->createNotFoundException('Retention opportunity not found.');
        }

        if (null === $property && null === $contact && null === $retentionOpportunity) {
            throw $this->createAccessDeniedException('Choose a property, contact, or retention opportunity.');
        }

        $existing = $attachmentRepository->findOneByTenantContextAndType($tenant, $playbookType, $property, $contact, $retentionOpportunity);
        if (null !== $existing) {
            $this->addFlash('success', sprintf('%s is already attached to that context.', $playbook['title']));

            return $this->redirectBack($request, $property?->getId(), $retentionOpportunity?->getProperty()?->getId());
        }

        $attachment = new CsrPlaybookAttachment($tenant, $playbookType, $property, $contact, $retentionOpportunity);
        $entityManager->persist($attachment);
        $entityManager->flush();
        $this->addFlash('success', sprintf('%s attached.', $playbook['title']));

        return $this->redirectBack($request, $property?->getId(), $retentionOpportunity?->getProperty()?->getId());
    }

    private function csrfTokenId(string $playbookType, Request $request): string
    {
        $propertyId = (int) $request->request->get('propertyId', 0);
        $contactId = (int) $request->request->get('contactId', 0);
        $retentionOpportunityId = (int) $request->request->get('retentionOpportunityId', 0);

        return sprintf(
            'crm_csr_playbook_attach_%s_%d_%d_%d',
            $playbookType,
            $propertyId,
            $contactId,
            $retentionOpportunityId,
        );
    }

    private function redirectBack(Request $request, ?int $propertyId, ?int $fallbackPropertyId): RedirectResponse
    {
        $target = (string) $request->request->get('_returnTo', '');
        if ('' !== $target) {
            return $this->redirect($target);
        }

        $propertyRouteId = $propertyId ?? $fallbackPropertyId;
        if (null !== $propertyRouteId) {
            return $this->redirectToRoute('crm_property_show', ['id' => $propertyRouteId]);
        }

        return $this->redirectToRoute('crm_property_index');
    }
}
