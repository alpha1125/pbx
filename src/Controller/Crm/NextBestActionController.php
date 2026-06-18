<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\NextBestActionSuggestion;
use App\Repository\NextBestActionSuggestionRepository;
use App\Repository\PropertyRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\AuditLogger;
use App\Service\CurrentTenantProviderInterface;
use App\Service\NextBestActionEngineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class NextBestActionController extends AbstractController
{
    #[Route('/crm/properties/{id<\d+>}/next-best-actions/generate', name: 'crm_property_next_best_action_generate', methods: ['POST'])]
    public function generate(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        NextBestActionEngineService $engineService,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = $propertyRepository->findOneByTenantAndId($tenant, $id);
        if (null === $property) {
            throw $this->createNotFoundException('Property not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $property);

        if (!$this->isCsrfTokenValid('crm_property_next_best_action_generate_'.$property->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $result = $engineService->generateForProperty($property, $entityManager);
        $entityManager->flush();
        $auditLogger->log($tenant, 'next_best_action_suggestion', 'batch', 'next_best_action.generated', null, null, [
            'propertyId' => $property->getId(),
            'created' => \count($result['created']),
        ]);

        $this->addFlash('success', sprintf('Generated %d next best action suggestion(s).', \count($result['created'])));

        return $this->redirectToRoute('crm_property_show', ['id' => $property->getId()]);
    }

    #[Route('/crm/properties/{id<\d+>}/next-best-actions/{suggestionId<\d+>}/status/{status}', name: 'crm_property_next_best_action_update_status', methods: ['POST'], requirements: ['status' => 'approved|dismissed|completed'])]
    public function updateStatus(
        int $id,
        int $suggestionId,
        string $status,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        NextBestActionSuggestionRepository $suggestionRepository,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = $propertyRepository->findOneByTenantAndId($tenant, $id);
        if (null === $property) {
            throw $this->createNotFoundException('Property not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $property);

        $suggestion = $suggestionRepository->findOneByTenantAndId($tenant, $suggestionId);
        if (null === $suggestion || null === $suggestion->getProperty()->getId() || $suggestion->getProperty()->getId() !== $property->getId()) {
            throw $this->createNotFoundException('Next best action suggestion not found.');
        }

        if (!$this->isCsrfTokenValid('crm_property_next_best_action_status_'.$suggestion->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $beforeStatus = $suggestion->getStatus();
        match ($status) {
            NextBestActionSuggestion::STATUS_APPROVED => $suggestion->approve(),
            NextBestActionSuggestion::STATUS_DISMISSED => $suggestion->dismiss(),
            NextBestActionSuggestion::STATUS_COMPLETED => $suggestion->complete(),
            default => null,
        };
        $entityManager->flush();
        $auditLogger->log($tenant, 'next_best_action_suggestion', (string) $suggestion->getId(), 'next_best_action.status_updated', ['status' => $beforeStatus], ['status' => $suggestion->getStatus()]);

        $this->addFlash('success', sprintf('Next best action marked %s.', $suggestion->getStatusLabel()));

        return $this->redirectToRoute('crm_property_show', ['id' => $property->getId()]);
    }
}
