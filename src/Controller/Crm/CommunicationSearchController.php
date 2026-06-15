<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Repository\CommunicationTimelineItemRepository;
use App\Service\CommunicationTimelineProjector;
use App\Repository\PropertyRepository;
use App\Service\CurrentTenantProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CommunicationSearchController extends AbstractController
{
    #[Route('/crm/communications/search', name: 'crm_communication_search', methods: ['GET'])]
    public function __invoke(
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        CommunicationTimelineItemRepository $timelineRepository,
        CommunicationTimelineProjector $timelineProjector,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $search = trim((string) $request->query->get('q', ''));
        $filter = (string) $request->query->get('activity', 'all');

        foreach ($propertyRepository->findByTenant($tenant, 1, 1000) as $property) {
            // Keep the search index warm enough for the first phase-3 iteration.
            $timelineProjector->syncProperty($property);
        }

        $itemTypes = match ($filter) {
            'calls' => ['call', 'recording'],
            'transcripts' => ['transcript', 'summary'],
            'notes' => ['manual_note', 'status_change', 'quote_event', 'invoice_event'],
            default => null,
        };

        $results = [] === $search
            ? []
            : $timelineRepository->searchByTenant($tenant, $search, $itemTypes, 100);

        return $this->render('crm/communication/search.html.twig', [
            'tenant' => $tenant,
            'search' => $search,
            'activityFilter' => $filter,
            'results' => $results,
        ]);
    }
}
