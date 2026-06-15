<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CallSessionRepository;
use App\Service\CallEventStreamService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class CallEventStreamController extends AbstractController
{
    #[Route('/api/calls/{providerSessionId}/events/stream', methods: ['GET'])]
    public function __invoke(
        string $providerSessionId,
        Request $request,
        CallSessionRepository $sessions,
        CallEventStreamService $stream,
    ): Response {
        $session = $sessions->findOneByProviderSessionId($providerSessionId);
        if (null === $session) {
            return $this->json([
                'ok' => false,
                'error' => sprintf('Call session "%s" was not found.', $providerSessionId),
            ], Response::HTTP_NOT_FOUND);
        }

        $timeoutSeconds = min(55, max(5, $request->query->getInt('timeout', 25)));
        $pollIntervalMicros = min(2_000_000, max(250_000, $request->query->getInt('poll_ms', 1000) * 1000));
        $cursor = [
            'telnyx' => max(0, $request->query->getInt('cursor_telnyx', 0)),
            'recording' => max(0, $request->query->getInt('cursor_recording', 0)),
            'transcript' => max(0, $request->query->getInt('cursor_transcript', 0)),
        ];

        $response = new StreamedResponse(function () use ($session, $stream, $timeoutSeconds, $pollIntervalMicros, $cursor): void {
            if (function_exists('session_write_close')) {
                @session_write_close();
            }

            @set_time_limit($timeoutSeconds + 5);
            ignore_user_abort(true);

            $startedAt = microtime(true);
            $currentCursor = $cursor;
            $heartbeatAt = 0.0;

            $this->sendSse('ready', [
                'providerSessionId' => $session->getProviderSessionId(),
                'cursor' => $currentCursor,
            ]);

            while (!connection_aborted() && (microtime(true) - $startedAt) < $timeoutSeconds) {
                $batch = $stream->fetchSince($session, $currentCursor);
                $currentCursor = $batch['cursor'];

                foreach ($batch['events'] as $event) {
                    $this->sendSse((string) $event['event'], $event, (string) $event['streamId']);
                    $heartbeatAt = microtime(true);
                }

                if ([] === $batch['events'] && (microtime(true) - $heartbeatAt) >= 5) {
                    $this->sendSse('heartbeat', [
                        'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                        'cursor' => $currentCursor,
                    ]);
                    $heartbeatAt = microtime(true);
                }

                usleep($pollIntervalMicros);
            }

            $this->sendSse('close', [
                'reason' => 'timeout',
                'cursor' => $currentCursor,
            ]);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendSse(string $event, array $payload, ?string $id = null): void
    {
        if (null !== $id && '' !== $id) {
            echo sprintf("id: %s\n", $id);
        }

        echo sprintf("event: %s\n", $event);
        echo sprintf("data: %s\n\n", json_encode($payload, JSON_THROW_ON_ERROR));

        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}
