<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CallRecordingRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\RecordingStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RecordingDownloadController extends AbstractController
{
    #[Route('/recordings/{identifier}/download-url', methods: ['GET'])]
    public function __invoke(
        string $identifier,
        CallRecordingRepository $repository,
        RecordingStorageService $storage,
    ): JsonResponse {
        $recording = ctype_digit($identifier)
            ? $repository->find((int) $identifier)
            : $repository->findOneByProviderRecordingId($identifier);
        if (null === $recording) {
            throw $this->createNotFoundException('Recording not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $recording);

        if (
            'imported' !== $recording->getStatus()
            || null === $recording->getS3Bucket()
            || null === $recording->getS3Key()
        ) {
            return $this->json([
                'error' => 'Recording has not been imported into S3.',
            ], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'url' => $storage->generatePresignedDownloadUrl($recording),
            'expiresIn' => $storage->getPresignedUrlTtlSeconds(),
        ]);
    }
}
