<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CallTranscript;
use App\Repository\CallTranscriptRepository;
use App\Repository\CallTranscriptSegmentRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TranscriptDetailsController extends AbstractController
{
    #[Route('/transcripts/{id<\d+>}', name: 'transcript_details', methods: ['GET'])]
    public function __invoke(
        int $id,
        CallTranscriptRepository $repository,
        CallTranscriptSegmentRepository $segments,
    ): JsonResponse
    {
        $transcript = $repository->find($id);
        if (null === $transcript) {
            throw $this->createNotFoundException('Transcript not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $transcript);

        return $this->json([
            'id' => $transcript->getId(),
            'recordingId' => $transcript->getCallRecording()?->getId(),
            'callSessionId' => $transcript->getCallSession()?->getId(),
            'callLegId' => $transcript->getCallLeg()?->getId(),
            'provider' => $transcript->getProvider(),
            'model' => $transcript->getModel(),
            'status' => $transcript->getStatus(),
            'transcriptText' => $transcript->getTranscriptText(),
            'language' => $transcript->getLanguage(),
            'durationSeconds' => $transcript->getDurationSeconds(),
            'rawResponse' => $transcript->getRawResponse(),
            'createdAt' => $transcript->getCreatedAt()->format(DATE_ATOM),
            'completedAt' => $transcript->getCompletedAt()?->format(DATE_ATOM),
            'segments' => array_map(static fn ($segment): array => [
                'id' => $segment->getId(),
                'sequenceNumber' => $segment->getSequenceNumber(),
                'callLegId' => $segment->getCallLeg()?->getId(),
                'speakerRole' => $segment->getSpeakerRole(),
                'text' => $segment->getText(),
                'isFinal' => $segment->isFinal(),
                'providerEventId' => $segment->getProviderEventId(),
                'occurredAt' => $segment->getOccurredAt()->format(DATE_ATOM),
                'rawPayload' => $segment->getRawPayload(),
            ], $segments->findByTranscript($transcript)),
        ]);
    }

    #[Route('/transcripts/{id<\d+>}/messages', name: 'transcript_messages', methods: ['GET'])]
    public function messages(
        int $id,
        CallTranscriptRepository $repository,
        CallTranscriptSegmentRepository $segments,
    ): Response {
        $transcript = $repository->find($id);
        if (null === $transcript) {
            throw $this->createNotFoundException('Transcript not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $transcript);

        $messages = [];

        foreach ($segments->findByTranscript($transcript) as $segment) {
            $text = trim($segment->getText());
            if ('' === $text) {
                continue;
            }

            $track = $this->segmentTrack($segment->getRawPayload());
            $side = $this->segmentSide($track, $segment->getSpeakerRole());
            $label = $this->segmentLabel($side, $track, $segment->getSpeakerRole());

            $lastIndex = array_key_last($messages);
            if (
                null !== $lastIndex
                && $messages[$lastIndex]['side'] === $side
                && $messages[$lastIndex]['label'] === $label
            ) {
                $messages[$lastIndex]['text'] .= "\n".$text;
                $messages[$lastIndex]['endOccurredAt'] = $segment->getOccurredAt()->format(DATE_ATOM);
                $messages[$lastIndex]['segmentCount']++;
                continue;
            }

            $messages[] = [
                'side' => $side,
                'label' => $label,
                'text' => $text,
                'occurredAt' => $segment->getOccurredAt()->format(DATE_ATOM),
                'endOccurredAt' => $segment->getOccurredAt()->format(DATE_ATOM),
                'segmentCount' => 1,
            ];
        }

        return $this->render('transcript/messages.html.twig', [
            'transcript' => $transcript,
            'messages' => $messages,
        ]);
    }

    /** @param array<string, mixed>|null $rawPayload */
    private function segmentTrack(?array $rawPayload): ?string
    {
        $payload = $rawPayload['payload'] ?? null;
        $transcriptionData = is_array($payload) ? ($payload['transcription_data'] ?? null) : null;
        $track = is_array($transcriptionData) ? ($transcriptionData['transcription_track'] ?? null) : null;

        return is_string($track) && '' !== trim($track) ? strtolower(trim($track)) : null;
    }

    private function segmentSide(?string $track, ?string $speakerRole): string
    {
        return match (true) {
            'outbound' === $track => 'left',
            'inbound' === $track => 'right',
            in_array($speakerRole, ['caller', 'customer', 'target'], true) => 'right',
            in_array($speakerRole, ['vendor', 'agent', 'system'], true) => 'left',
            default => 'left',
        };
    }

    private function segmentLabel(string $side, ?string $track, ?string $speakerRole): string
    {
        if ('inbound' === $track) {
            return 'Inbound';
        }

        if ('outbound' === $track) {
            return 'Outbound';
        }

        if (null !== $speakerRole && '' !== trim($speakerRole)) {
            return ucfirst(str_replace('_', ' ', $speakerRole));
        }

        return 'right' === $side ? 'Inbound' : 'Outbound';
    }
}
