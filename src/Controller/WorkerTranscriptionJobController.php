<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TranscriptionJobRepository;
use App\Service\TranscriptionJobService;
use App\Service\WorkerApiAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/worker/transcription-jobs')]
final class WorkerTranscriptionJobController extends AbstractController
{
    public function __construct(
        private readonly WorkerApiAuthService $auth,
    ) {
    }

    #[Route('/claim', methods: ['POST'])]
    public function claim(Request $request, TranscriptionJobService $jobs): JsonResponse
    {
        if (null !== ($failure = $this->authorize($request))) {
            return $failure;
        }

        $payload = $this->requestPayload($request);
        $workerId = is_string($payload['workerId'] ?? null) ? trim($payload['workerId']) : '';
        if ('' === $workerId) {
            return $this->json(['error' => 'workerId is required.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'job' => $jobs->claimNextJob($workerId),
        ]);
    }

    #[Route('/{id<\d+>}/status', methods: ['POST'])]
    public function status(
        int $id,
        Request $request,
        TranscriptionJobRepository $repository,
        TranscriptionJobService $jobs,
    ): JsonResponse {
        if (null !== ($failure = $this->authorize($request))) {
            return $failure;
        }

        $payload = $this->requestPayload($request);
        $workerId = is_string($payload['workerId'] ?? null) ? trim($payload['workerId']) : '';
        $status = $payload['status'] ?? null;
        if ('' === $workerId || 'processing' !== $status) {
            return $this->json(['error' => 'workerId and status=processing are required.'], Response::HTTP_BAD_REQUEST);
        }

        $job = $repository->find($id);
        if (null === $job) {
            throw $this->createNotFoundException('Transcription job not found.');
        }

        try {
            $jobs->markProcessing($job, $workerId);
        } catch (\RuntimeException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(['ok' => true]);
    }

    #[Route('/{id<\d+>}/complete', methods: ['POST'])]
    public function complete(
        int $id,
        Request $request,
        TranscriptionJobRepository $repository,
        TranscriptionJobService $jobs,
    ): JsonResponse {
        if (null !== ($failure = $this->authorize($request))) {
            return $failure;
        }

        $payload = $this->requestPayload($request);
        $workerId = is_string($payload['workerId'] ?? null) ? trim($payload['workerId']) : '';
        if ('' === $workerId) {
            return $this->json(['error' => 'workerId is required.'], Response::HTTP_BAD_REQUEST);
        }

        $job = $repository->find($id);
        if (null === $job) {
            throw $this->createNotFoundException('Transcription job not found.');
        }

        try {
            $result = $jobs->completeJob(
                $job,
                $workerId,
                is_string($payload['provider'] ?? null) ? $payload['provider'] : 'faster-whisper',
                is_string($payload['model'] ?? null) ? $payload['model'] : null,
                is_string($payload['language'] ?? null) ? $payload['language'] : null,
                is_int($payload['durationSeconds'] ?? null) ? $payload['durationSeconds'] : null,
                is_string($payload['transcriptText'] ?? null) ? $payload['transcriptText'] : null,
                is_array($payload['transcriptJson'] ?? null) ? $payload['transcriptJson'] : null,
                is_array($payload['channelMapping'] ?? null) ? $payload['channelMapping'] : null,
            );
        } catch (\RuntimeException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'ok' => true,
            'transcriptId' => $result['transcriptId'],
            'summaryId' => $result['summaryId'],
        ]);
    }

    #[Route('/{id<\d+>}/fail', methods: ['POST'])]
    public function fail(
        int $id,
        Request $request,
        TranscriptionJobRepository $repository,
        TranscriptionJobService $jobs,
    ): JsonResponse {
        if (null !== ($failure = $this->authorize($request))) {
            return $failure;
        }

        $payload = $this->requestPayload($request);
        $workerId = is_string($payload['workerId'] ?? null) ? trim($payload['workerId']) : '';
        $errorMessage = is_string($payload['errorMessage'] ?? null) ? trim($payload['errorMessage']) : '';
        if ('' === $workerId || '' === $errorMessage) {
            return $this->json(['error' => 'workerId and errorMessage are required.'], Response::HTTP_BAD_REQUEST);
        }

        $job = $repository->find($id);
        if (null === $job) {
            throw $this->createNotFoundException('Transcription job not found.');
        }

        try {
            $jobs->failJob($job, $workerId, $errorMessage);
        } catch (\RuntimeException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(['ok' => true]);
    }

    private function authorize(Request $request): ?JsonResponse
    {
        // TODO: Replace this shared-secret check with mTLS, OAuth, or another service identity mechanism.
        if ($this->auth->isAuthorized($request->headers->get('X-Worker-Secret'))) {
            return null;
        }

        return $this->json(['error' => 'Unauthorized worker request.'], Response::HTTP_UNAUTHORIZED);
    }

    /** @return array<string, mixed> */
    private function requestPayload(Request $request): array
    {
        try {
            $payload = $request->toArray();
        } catch (\JsonException) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }
}
