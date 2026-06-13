<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ClickToCallService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ClickToCallController extends AbstractController
{
    #[Route('/api/click-to-call', methods: ['POST'])]
    public function __invoke(Request $request, ClickToCallService $clickToCall): JsonResponse
    {
        // TODO: Add auth/RBAC before exposing click-to-call beyond dev use.
        try {
            $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['ok' => false, 'error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($payload)) {
            return $this->json(['ok' => false, 'error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $targetNumber = $this->normalizePhoneNumber($payload['targetNumber'] ?? null);
        $agentNumber = $this->normalizePhoneNumber($payload['agentNumber'] ?? null, allowNull: true);
        $targetName = isset($payload['targetName']) && is_string($payload['targetName']) ? trim($payload['targetName']) : null;

        if (null === $targetNumber) {
            return $this->json(['ok' => false, 'error' => 'targetNumber must be an E.164-style number starting with +.'], Response::HTTP_BAD_REQUEST);
        }
        if (null === $agentNumber && array_key_exists('agentNumber', $payload)) {
            return $this->json(['ok' => false, 'error' => 'agentNumber must be an E.164-style number starting with +.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $clickRequest = $clickToCall->start($targetNumber, '' !== ($targetName ?? '') ? $targetName : null, $agentNumber);
        } catch (\Throwable $exception) {
            return $this->json([
                'ok' => false,
                'error' => $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'ok' => true,
            'clickToCallRequestId' => $clickRequest->getId(),
            'status' => $clickRequest->getStatus(),
        ]);
    }

    private function normalizePhoneNumber(mixed $value, bool $allowNull = false): ?string
    {
        if (null === $value) {
            return $allowNull ? null : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $number = preg_replace('/\s+/', '', trim($value)) ?? '';

        return 1 === preg_match('/^\+[1-9]\d{7,19}$/', $number) ? $number : null;
    }
}
