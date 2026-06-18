<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Repository\CommunicationTimelineItemRepository;
use App\Repository\CallTranscriptSegmentRepository;
use App\Service\CommunicationTimelineProjector;
use App\Repository\PropertyRepository;
use App\Service\CurrentTenantProviderInterface;
use App\Entity\CommunicationTimelineItem;
use App\Entity\CallTranscriptSegment;
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
        CallTranscriptSegmentRepository $segmentRepository,
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

        $results = [];
        if ('' !== $search) {
            foreach ($timelineRepository->searchByTenant($tenant, $search, $itemTypes, 100) as $item) {
                $results[] = $this->timelineResult($item);
            }

            foreach ($segmentRepository->searchByTenant($tenant, $search, 50) as $segment) {
                $results[] = $this->segmentResult($segment);
            }
        }

        usort($results, static fn (array $left, array $right): int => strcmp($right['occurredAt'], $left['occurredAt']));

        return $this->render('crm/communication/search.html.twig', [
            'tenant' => $tenant,
            'search' => $search,
            'activityFilter' => $filter,
            'results' => $results,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineResult(\App\Entity\CommunicationTimelineItem $item): array
    {
        return [
            'resultType' => 'timeline',
            'itemType' => $item->getItemType(),
            'property' => $item->getProperty(),
            'contact' => $item->getContact(),
            'bodyText' => $item->getBodyText(),
            'occurredAt' => $item->getOccurredAt()->format(DATE_ATOM),
            'detailUrl' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function segmentResult(\App\Entity\CallTranscriptSegment $segment): array
    {
        $transcript = $segment->getCallTranscript();
        $session = $segment->getCallSession();
        $contact = $session?->getContact();
        $property = $session?->getProperty();
        $text = trim($segment->getText());

        return [
            'resultType' => 'transcript_segment',
            'itemType' => 'transcript segment',
            'property' => $property,
            'contact' => $contact,
            'bodyText' => $this->excerpt($text),
            'fullText' => $text,
            'occurredAt' => $segment->getOccurredAt()->format(DATE_ATOM),
            'detailUrl' => null !== $transcript->getId() ? '/transcripts/'.$transcript->getId().'/messages' : null,
        ];
    }

    private function excerpt(string $text, int $limit = 220): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ('' === $text) {
            return '';
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_strimwidth($text, 0, $limit - 1, '…');
    }
}
