<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CapturePolicyResolver;
use App\Service\DevTelnyxTranscriptionTestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DevTelnyxTranscriptionTestController extends AbstractController
{
    #[Route('/api/dev/telnyx/transcription-test', methods: ['POST'])]
    public function __invoke(
        Request $request,
        DevTelnyxTranscriptionTestService $service,
        CapturePolicyResolver $policyResolver,
    ): JsonResponse {
        try {
            $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['ok' => false, 'error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($payload)) {
            return $this->json(['ok' => false, 'error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $targetNumber = $this->normalizePhoneNumber($payload['targetNumber'] ?? null);
        $targetName = isset($payload['targetName']) && is_string($payload['targetName']) ? trim($payload['targetName']) : null;
        $recordAudio = $this->boolValue($payload, 'recordAudio');
        $transcribeAudio = $this->boolValue($payload, 'transcribeAudio');

        if (null === $targetNumber) {
            return $this->json(['ok' => false, 'error' => 'targetNumber must be an E.164-style number starting with +.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $session = $service->start($targetNumber, '' !== ($targetName ?? '') ? $targetName : null, $recordAudio, $transcribeAudio);
        } catch (\Throwable $exception) {
            return $this->json([
                'ok' => false,
                'error' => $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $policy = $policyResolver->resolve($recordAudio, $transcribeAudio, 'transcription_test');

        return $this->json([
            'ok' => true,
            'callSessionId' => $session->getId(),
            'providerSessionId' => $session->getProviderSessionId(),
            'capturePolicy' => $policy->toArray(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function boolValue(array $payload, string $key): ?bool
    {
        return array_key_exists($key, $payload) && is_bool($payload[$key]) ? $payload[$key] : null;
    }

    private function normalizePhoneNumber(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $number = preg_replace('/\s+/', '', trim($value)) ?? '';

        return 1 === preg_match('/^\+[1-9]\d{7,19}$/', $number) ? $number : null;
    }
}
