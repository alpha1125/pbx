<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\Property;
use App\Entity\RetentionOpportunity;
use App\Repository\PropertyRepository;
use App\Repository\RetentionOpportunityRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\AuditLogger;
use App\Service\CurrentTenantProviderInterface;
use App\Service\RetentionOpportunityEngineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RetentionOpportunityController extends AbstractController
{
    #[Route('/crm/retention-opportunities', name: 'crm_retention_opportunity_index', methods: ['GET'])]
    public function index(
        CurrentTenantProviderInterface $tenantProvider,
        RetentionOpportunityRepository $retentionOpportunityRepository,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();

        return $this->render('crm/retention_opportunity/index.html.twig', [
            'tenant' => $tenant,
            'opportunities' => $retentionOpportunityRepository->findByTenantOrdered($tenant),
            'openCount' => $retentionOpportunityRepository->countOpenByTenant($tenant),
        ]);
    }

    #[Route('/crm/retention-opportunities/generate', name: 'crm_retention_opportunity_generate', methods: ['POST'])]
    public function generate(
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        RetentionOpportunityEngineService $engineService,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $propertyId = (int) $request->request->get('propertyId', 0);
        $properties = [];
        $tokenId = $propertyId > 0
            ? 'crm_retention_opportunity_generate_'.$propertyId
            : 'crm_retention_opportunity_generate_'.$tenant->getId();

        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($propertyId > 0) {
            $property = $propertyRepository->findOneByTenantAndId($tenant, $propertyId);
            if (null === $property) {
                throw $this->createNotFoundException('Property not found.');
            }

            $properties[] = $property;
        } else {
            $pageSize = max(1, $propertyRepository->countByTenant($tenant));
            $properties = $propertyRepository->findByTenant($tenant, 1, $pageSize);
        }

        $createdCount = 0;
        $updatedCount = 0;
        foreach ($properties as $property) {
            if (!$property instanceof Property) {
                continue;
            }

            $result = $engineService->generateForProperty($property);
            foreach ($result['created'] as $opportunity) {
                $entityManager->persist($opportunity);
            }

            $createdCount += \count($result['created']);
            $updatedCount += \count($result['updated']);
            $auditLogger->log($tenant, 'retention_opportunity', 'batch', 'retention_opportunity.generated', null, null, [
                'propertyId' => $property->getId(),
                'created' => \count($result['created']),
                'updated' => \count($result['updated']),
            ]);
        }

        $entityManager->flush();

        if ($propertyId > 0) {
            $this->addFlash('success', sprintf('Generated %d retention opportunity(s) for this property.', $createdCount + $updatedCount));

            return $this->redirectToRoute('crm_property_show', ['id' => $propertyId]);
        }

        $this->addFlash('success', sprintf('Generated %d retention opportunity(s) across %d property(s).', $createdCount + $updatedCount, \count($properties)));

        return $this->redirectToRoute('crm_retention_opportunity_index');
    }

    #[Route('/crm/retention-opportunities/{id<\d+>}/status/{status}', name: 'crm_retention_opportunity_update_status', methods: ['POST'], requirements: ['status' => 'reviewed|dismissed|converted'])]
    public function updateStatus(
        int $id,
        string $status,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        RetentionOpportunityRepository $retentionOpportunityRepository,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $opportunity = $retentionOpportunityRepository->findOneByTenantAndId($tenant, $id);
        if (null === $opportunity) {
            throw $this->createNotFoundException('Retention opportunity not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $opportunity);

        if (!$this->isCsrfTokenValid('crm_retention_opportunity_status_'.$opportunity->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $beforeStatus = $opportunity->getStatus();
        match ($status) {
            RetentionOpportunity::STATUS_REVIEWED => $opportunity->markReviewed(),
            RetentionOpportunity::STATUS_DISMISSED => $opportunity->dismiss(),
            RetentionOpportunity::STATUS_CONVERTED => $opportunity->convert(),
            default => null,
        };

        $entityManager->flush();
        $auditLogger->log($tenant, 'retention_opportunity', (string) $opportunity->getId(), 'retention_opportunity.status_updated', ['status' => $beforeStatus], ['status' => $opportunity->getStatus()]);
        $this->addFlash('success', sprintf('Retention opportunity marked %s.', $opportunity->getStatusLabel()));

        return $this->redirectToRoute('crm_retention_opportunity_index');
    }
}
