<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CallTranscriptRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class TranscriptDetailsController extends AbstractController
{
    public function __construct(
        private readonly string $environment,
    ) {
    }

    #[Route('/transcripts/{id<\d+>}', methods: ['GET'])]
    public function __invoke(int $id, CallTranscriptRepository $repository): JsonResponse
    {
        if ('dev' !== $this->environment) {
            throw $this->createNotFoundException();
        }

        // TODO: Enforce tenant ownership and RBAC before enabling this endpoint in production.
        $transcript = $repository->find($id);
        if (null === $transcript) {
            throw $this->createNotFoundException('Transcript not found.');
        }

        return $this->json([
            'id' => $transcript->getId(),
            'recordingId' => $transcript->getCallRecording()->getId(),
            'callSessionId' => $transcript->getCallSession()?->getId(),
            'provider' => $transcript->getProvider(),
            'model' => $transcript->getModel(),
            'status' => $transcript->getStatus(),
            'transcriptText' => $transcript->getTranscriptText(),
            'language' => $transcript->getLanguage(),
            'durationSeconds' => $transcript->getDurationSeconds(),
            'createdAt' => $transcript->getCreatedAt()->format(DATE_ATOM),
            'completedAt' => $transcript->getCompletedAt()?->format(DATE_ATOM),
        ]);
    }
}
