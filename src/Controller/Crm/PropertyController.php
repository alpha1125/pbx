<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Repository\AuditLogRepository;
use App\Repository\CallSessionRepository;
use App\Repository\CallTranscriptRepository;
use App\Repository\EquipmentRepository;
use App\Repository\EstimateRepository;
use App\Repository\InvoiceRepository;
use App\Repository\PropertyContactRepository;
use App\Repository\PropertyRepository;
use App\Repository\QuoteRepository;
use App\Service\CurrentTenantProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PropertyController extends AbstractController
{
    #[Route('/crm/properties', name: 'crm_property_index', methods: ['GET'])]
    public function index(
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        PropertyContactRepository $propertyContactRepository,
        EquipmentRepository $equipmentRepository,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $properties = $propertyRepository->findByTenant($tenant);
        $primaryContacts = $propertyContactRepository->findPrimaryByProperties($properties);
        $equipmentCounts = $equipmentRepository->countByProperties($properties);

        return $this->render('crm/property/index.html.twig', [
            'tenant' => $tenant,
            'properties' => $properties,
            'primaryContacts' => $primaryContacts,
            'equipmentCounts' => $equipmentCounts,
        ]);
    }

    #[Route('/crm/properties/{id<\d+>}', name: 'crm_property_show', methods: ['GET'])]
    public function show(
        int $id,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        PropertyContactRepository $propertyContactRepository,
        EquipmentRepository $equipmentRepository,
        EstimateRepository $estimateRepository,
        QuoteRepository $quoteRepository,
        InvoiceRepository $invoiceRepository,
        CallSessionRepository $callSessionRepository,
        CallTranscriptRepository $callTranscriptRepository,
        AuditLogRepository $auditLogRepository,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = $propertyRepository->findOneByTenantAndId($tenant, $id);
        if (null === $property) {
            throw $this->createNotFoundException('Property not found.');
        }

        $calls = $callSessionRepository->findByTenantAndProperty($tenant, $property);

        return $this->render('crm/property/show.html.twig', [
            'tenant' => $tenant,
            'property' => $property,
            'propertyContacts' => $propertyContactRepository->findByProperty($property),
            'equipment' => $equipmentRepository->findByProperty($property),
            'estimates' => $estimateRepository->findByProperty($property),
            'quotes' => $quoteRepository->findByProperty($property),
            'invoices' => $invoiceRepository->findByProperty($property),
            'calls' => $calls,
            'transcripts' => $callTranscriptRepository->findBySessions($calls),
            'auditLogs' => $auditLogRepository->findRecentByProperty($tenant, $property),
        ]);
    }
}
