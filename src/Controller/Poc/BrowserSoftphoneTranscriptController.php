<?php

declare(strict_types=1);

namespace App\Controller\Poc;

use App\Transcription\Provider\TelnyxTranscriptionConfiguration;
use App\Service\TelnyxCallControlService;
use App\Service\PocBrowserSoftphoneTranscriptService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class BrowserSoftphoneTranscriptController extends AbstractController
{
    #[Route('/api/poc/browser-softphone/{callSessionId}/call-control', name: 'api_poc_browser_softphone_call_control_register', methods: ['POST'])]
    public function registerCallControlId(
        string $callSessionId,
        Request $request,
        PocBrowserSoftphoneTranscriptService $transcripts,
        TelnyxCallControlService $callControl,
        TelnyxTranscriptionConfiguration $transcriptionConfiguration,
        LoggerInterface $logger,
    ): JsonResponse {
        try {
            $payload = json_decode((string) $request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($payload)) {
            return $this->json(['ok' => false, 'error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $callControlId = is_string($payload['callControlId'] ?? null) ? trim((string) $payload['callControlId']) : '';
        if ('' === $callControlId) {
            return $this->json(['ok' => false, 'error' => 'callControlId is required.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $transcripts->registerCallControlId($callSessionId, $callControlId);
        } catch (\Throwable $exception) {
            return $this->json(['ok' => false, 'error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $transcriptionResponse = null;
        try {
            $logger->info('Poc browser softphone call-control registered; requesting Telnyx live transcription.', [
                'callSessionId' => $callSessionId,
                'callControlId' => $callControlId,
            ]);

            $transcriptionResponse = $callControl->startTranscription($callControlId, [
                ...$transcriptionConfiguration->toTranscriptionStartPayload(),
                'command_id' => sprintf('poc-browser-softphone-%s-transcription-start', $callSessionId),
            ]);

            $logger->info('Poc browser softphone Telnyx transcription start returned.', [
                'callSessionId' => $callSessionId,
                'callControlId' => $callControlId,
                'response' => $transcriptionResponse,
            ]);
        } catch (\Throwable $exception) {
            $logger->warning('Poc browser softphone Telnyx transcription start failed.', [
                'callSessionId' => $callSessionId,
                'callControlId' => $callControlId,
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->json([
            'ok' => true,
            'callSessionId' => $callSessionId,
            'callControlId' => $callControlId,
            'topic' => $transcripts->topicForCallSession($callSessionId),
            'streamUrl' => $transcripts->streamUrlForCallSession($callSessionId),
            'transcriptionStart' => $transcriptionResponse,
        ]);
    }

    #[Route('/api/poc/browser-softphone/{callSessionId}/transcript', name: 'api_poc_browser_softphone_transcript_ingest', methods: ['POST'])]
    public function ingest(
        string $callSessionId,
        Request $request,
        PocBrowserSoftphoneTranscriptService $transcripts,
    ): JsonResponse {
        try {
            $payload = json_decode((string) $request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($payload)) {
            return $this->json(['ok' => false, 'error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $speaker = is_string($payload['speaker'] ?? null) ? $payload['speaker'] : 'customer';
        $text = is_string($payload['text'] ?? null) ? $payload['text'] : '';
        $isFinal = array_key_exists('isFinal', $payload) ? (bool) $payload['isFinal'] : true;
        $sourceEventId = is_string($payload['sourceEventId'] ?? null) ? $payload['sourceEventId'] : null;
        $occurredAt = $this->parseDateTime($payload['occurredAt'] ?? null);

        try {
            $result = $transcripts->recordSegment($callSessionId, $speaker, $text, $occurredAt, $isFinal, $sourceEventId);
        } catch (\Throwable $exception) {
            return $this->json(['ok' => false, 'error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'ok' => true,
            'topic' => $result['topic'],
            'callSessionId' => $result['callSessionId'],
            'deduplicated' => $result['deduplicated'],
            'segment' => $result['segment'],
        ]);
    }

    #[Route('/api/poc/browser-softphone/{callSessionId}/transcript/stream', name: 'api_poc_browser_softphone_transcript_stream', methods: ['GET'])]
    public function streamAction(
        string $callSessionId,
        Request $request,
        PocBrowserSoftphoneTranscriptService $transcripts,
    ): Response {
        $timeoutSeconds = min(55, max(5, $request->query->getInt('timeout', 25)));
        $pollIntervalMicros = min(2_000_000, max(250_000, $request->query->getInt('poll_ms', 1000) * 1000));
        $cursor = max(0, $request->query->getInt('cursor', 0));
        $topic = $transcripts->topicForCallSession($callSessionId);

        $response = new StreamedResponse(function () use ($callSessionId, $transcripts, $timeoutSeconds, $pollIntervalMicros, $cursor, $topic): void {
            if (function_exists('session_write_close')) {
                @session_write_close();
            }

            @set_time_limit($timeoutSeconds + 5);
            ignore_user_abort(true);

            $startedAt = microtime(true);
            $currentCursor = $cursor;
            $lastHeartbeatAt = 0.0;

            $this->sendSse('ready', [
                'ok' => true,
                'topic' => $topic,
                'callSessionId' => $callSessionId,
                'cursor' => $currentCursor,
            ]);

            while (!connection_aborted() && (microtime(true) - $startedAt) < $timeoutSeconds) {
                $batch = $transcripts->fetchSince($callSessionId, $currentCursor);
                $currentCursor = $batch['cursor'];

                foreach ($batch['segments'] as $segment) {
                    $this->sendSse('transcript.segment', [
                        'ok' => true,
                        'topic' => $topic,
                        'callSessionId' => $callSessionId,
                        'segment' => $segment,
                    ], (string) $segment['id']);
                    $lastHeartbeatAt = microtime(true);
                }

                if ([] === $batch['segments'] && (microtime(true) - $lastHeartbeatAt) >= 5) {
                    $this->sendSse('heartbeat', [
                        'ok' => true,
                        'topic' => $topic,
                        'callSessionId' => $callSessionId,
                        'cursor' => $currentCursor,
                        'at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    ]);
                    $lastHeartbeatAt = microtime(true);
                }

                usleep($pollIntervalMicros);
            }

            $this->sendSse('close', [
                'ok' => true,
                'reason' => 'timeout',
                'topic' => $topic,
                'callSessionId' => $callSessionId,
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

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
