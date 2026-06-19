<?php

declare(strict_types=1);

namespace App\Controller\Poc;

use App\Service\TelnyxWebRtcProvisioningService;
use App\Service\TelnyxWebrtcTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class BrowserSoftphoneController extends AbstractController
{
    public function __construct(
        private readonly TelnyxWebRtcProvisioningService $provisioningService,
        private readonly TelnyxWebrtcTokenService $tokenService,
        private readonly string $fromNumber,
        private readonly string $destinationNumber,
    ) {
    }

    #[Route('/poc/browser-softphone', name: 'poc_browser_softphone', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('poc/browser_softphone.html.twig', [
            'fromNumber' => $this->fromNumber,
            'destinationNumber' => $this->destinationNumber,
        ]);
    }

    #[Route('/poc/browser-softphone/token', name: 'poc_browser_softphone_token', methods: ['POST'])]
    public function token(Request $request): JsonResponse
    {
        if ('' === trim($this->fromNumber)) {
            return new JsonResponse(['ok' => false, 'error' => 'TELNYX_FROM_NUMBER is missing.'], Response::HTTP_BAD_REQUEST);
        }

        $destinationNumber = $this->destinationNumber;
        $payload = [];
        try {
            $payload = json_decode((string) $request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $payload = [];
        }

        if (is_array($payload) && array_key_exists('destinationNumber', $payload)) {
            $requestedDestinationNumber = $this->normalizePhoneNumber($payload['destinationNumber'] ?? null);
            if (null === $requestedDestinationNumber) {
                return new JsonResponse(['ok' => false, 'error' => 'destinationNumber must be an E.164-style number starting with +.'], Response::HTTP_BAD_REQUEST);
            }

            $destinationNumber = $requestedDestinationNumber;
        }

        if ('' === trim($destinationNumber)) {
            return new JsonResponse(['ok' => false, 'error' => 'TELNYX_BROWSER_CALL_DESTINATION_NUMBER is missing.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $credential = $this->provisioningService->getOrCreateTelephonyCredential('csr-browser-poc');
            $token = $this->tokenService->generateToken($credential->id);
        } catch (\Throwable $exception) {
            return new JsonResponse(['ok' => false, 'error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $callSessionId = Uuid::v7()->toRfc4122();
        $transcriptTopic = sprintf('/poc/browser-softphone/%s/transcript', $callSessionId);
        $transcriptStreamUrl = sprintf('/api/poc/browser-softphone/%s/transcript/stream', $callSessionId);

        return new JsonResponse([
            'ok' => true,
            'token' => $token,
            'destinationNumber' => $destinationNumber,
            'callerNumber' => $this->fromNumber,
            'callSessionId' => $callSessionId,
            'transcriptTopic' => $transcriptTopic,
            'transcriptStreamUrl' => $transcriptStreamUrl,
        ]);
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
