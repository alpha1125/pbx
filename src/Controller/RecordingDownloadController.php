<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CallRecordingRepository;
use App\Service\RecordingStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RecordingDownloadController extends AbstractController
{
    public function __construct(
        private readonly string $environment,
    ) {
    }

    #[Route('/recordings/{identifier}/download-url', methods: ['GET'])]
    public function __invoke(
        string $identifier,
        CallRecordingRepository $repository,
        RecordingStorageService $storage,
    ): JsonResponse {
        if ('dev' !== $this->environment) {
            throw $this->createNotFoundException();
        }

        // TODO: Enforce tenant ownership and RBAC before enabling this endpoint in production.
        $recording = ctype_digit($identifier)
            ? $repository->find((int) $identifier)
            : $repository->findOneByProviderRecordingId($identifier);
        if (null === $recording) {
            throw $this->createNotFoundException('Recording not found.');
        }

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
