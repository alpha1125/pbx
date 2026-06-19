<?php

declare(strict_types=1);

namespace App\Service;

final class PocBrowserSoftphoneTranscriptService
{
    private const STORAGE_PREFIX = 'pbx-browser-softphone-transcripts';

    private PocBrowserSoftphoneTranscriptMergeService $mergeService;

    public function __construct(?PocBrowserSoftphoneTranscriptMergeService $mergeService = null)
    {
        $this->mergeService = $mergeService ?? new PocBrowserSoftphoneTranscriptMergeService();
    }

    public function topicForCallSession(string $callSessionId): string
    {
        return sprintf('/poc/browser-softphone/%s/transcript', $this->normalizeCallSessionId($callSessionId));
    }

    public function streamUrlForCallSession(string $callSessionId): string
    {
        return sprintf('/api/poc/browser-softphone/%s/transcript/stream', $this->normalizeCallSessionId($callSessionId));
    }

    /**
     * Build the payload shape the browser receives for a transcript segment.
     *
     * @param array{
     *     id:int,
     *     sequence:int,
     *     speaker:string,
     *     text:string,
     *     occurredAt:string,
     *     displayTime:string,
     *     isFinal:bool,
     *     sourceEventId:?string,
     *     fingerprint:string
     * } $segment
     *
     * @return array{topic:string, callSessionId:string, segment:array<string, mixed>}
     */
    public function buildPublishPayload(string $callSessionId, array $segment): array
    {
        $normalizedCallSessionId = $this->normalizeCallSessionId($callSessionId);

        return [
            'topic' => $this->topicForCallSession($normalizedCallSessionId),
            'callSessionId' => $normalizedCallSessionId,
            'segment' => $segment,
        ];
    }

    /**
     * Persist a transcript segment and deduplicate exact repeats.
     *
     * @return array{topic:string, callSessionId:string, segment:array<string, mixed>, deduplicated:bool}
     */
    public function recordSegment(
        string $callSessionId,
        string $speaker,
        string $text,
        ?\DateTimeImmutable $occurredAt = null,
        bool $isFinal = true,
        ?string $sourceEventId = null,
    ): array {
        $normalizedCallSessionId = $this->normalizeCallSessionId($callSessionId);
        $normalizedSpeaker = $this->normalizeSpeaker($speaker);
        $normalizedText = trim($text);
        if ('' === $normalizedText) {
            throw new \InvalidArgumentException('Transcript text cannot be empty.');
        }

        $occurredAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $occurredAt = $occurredAt->setTimezone(new \DateTimeZone('UTC'));
        $fingerprint = $this->buildFingerprint($normalizedSpeaker, $normalizedText, $occurredAt, $isFinal, $sourceEventId);

        $state = $this->readState($normalizedCallSessionId);
        $segment = [
            'id' => $state['nextId'],
            'sequence' => $state['nextId'],
            'speaker' => $normalizedSpeaker,
            'text' => $normalizedText,
            'occurredAt' => $occurredAt->format(DATE_ATOM),
            'displayTime' => $occurredAt->format('g:i A'),
            'isFinal' => $isFinal,
            'sourceEventId' => $this->normalizeNullableString($sourceEventId),
            'fingerprint' => $fingerprint,
        ];

        $mergeResult = $this->mergeService->mergeSegments($state['segments'], $segment);
        if (false === $mergeResult['changed']) {
            return [
                'topic' => $this->topicForCallSession($normalizedCallSessionId),
                'callSessionId' => $normalizedCallSessionId,
                'segment' => $mergeResult['segment'],
                'deduplicated' => true,
            ];
        }

        $state['segments'] = $mergeResult['segments'];
        if (true === $mergeResult['added']) {
            $state['nextId'] = $state['nextId'] + 1;
        }
        $this->writeState($normalizedCallSessionId, $state);

        return [
            'topic' => $this->topicForCallSession($normalizedCallSessionId),
            'callSessionId' => $normalizedCallSessionId,
            'segment' => $mergeResult['segment'],
            'deduplicated' => false,
        ];
    }

    /**
     * @return array{segments:list<array<string, mixed>>, cursor:int}
     */
    public function fetchSince(string $callSessionId, int $afterSequence = 0): array
    {
        $state = $this->readState($this->normalizeCallSessionId($callSessionId));
        $segments = array_values(array_filter(
            $state['segments'],
            static fn (array $segment): bool => (int) $segment['sequence'] > $afterSequence,
        ));

        return [
            'segments' => $segments,
            'cursor' => max($afterSequence, $state['nextId'] - 1),
        ];
    }

    /**
     * @return array{nextId:int, segments:list<array<string, mixed>>}
     */
    private function readState(string $callSessionId): array
    {
        $path = $this->statePath($callSessionId);
        if (!is_file($path)) {
            return [
                'nextId' => 1,
                'segments' => [],
            ];
        }

        try {
            $raw = file_get_contents($path);
            $decoded = json_decode(false !== $raw ? $raw : '', true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [
                'nextId' => 1,
                'segments' => [],
            ];
        }

        if (!is_array($decoded)) {
            return [
                'nextId' => 1,
                'segments' => [],
            ];
        }

        $segments = [];
        foreach ($decoded['segments'] ?? [] as $segment) {
            if (is_array($segment) && isset($segment['sequence'], $segment['speaker'], $segment['text'])) {
                $segments[] = $segment;
            }
        }

        return [
            'nextId' => max(1, (int) ($decoded['nextId'] ?? (count($segments) + 1))),
            'segments' => $segments,
        ];
    }

    /**
     * @param array{nextId:int, segments:list<array<string, mixed>>} $state
     */
    private function writeState(string $callSessionId, array $state): void
    {
        $path = $this->statePath($callSessionId);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create transcript storage directory "%s".', $directory));
        }

        $handle = @fopen($path, 'c+');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Unable to open transcript storage file "%s".', $path));
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Unable to lock transcript storage file "%s".', $path));
            }

            rewind($handle);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            fflush($handle);
            ftruncate($handle, ftell($handle));
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function statePath(string $callSessionId): string
    {
        return sprintf('%s/%s/%s.json', sys_get_temp_dir(), self::STORAGE_PREFIX, $this->sanitizeCallSessionId($callSessionId));
    }

    private function sanitizeCallSessionId(string $callSessionId): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $callSessionId) ?? 'unknown';
    }

    private function normalizeCallSessionId(string $callSessionId): string
    {
        $normalized = trim($callSessionId);
        if ('' === $normalized) {
            throw new \InvalidArgumentException('callSessionId cannot be empty.');
        }

        return $normalized;
    }

    private function normalizeSpeaker(string $speaker): string
    {
        $normalized = strtolower(trim($speaker));
        return in_array($normalized, ['customer', 'csr'], true) ? $normalized : 'customer';
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $normalized = trim($value);

        return '' === $normalized ? null : $normalized;
    }

    private function buildFingerprint(
        string $speaker,
        string $text,
        \DateTimeImmutable $occurredAt,
        bool $isFinal,
        ?string $sourceEventId,
    ): string {
        return sha1(implode('|', [
            $speaker,
            $text,
            $occurredAt->format(DATE_ATOM),
            $isFinal ? '1' : '0',
            $this->normalizeNullableString($sourceEventId) ?? '',
        ]));
    }
}
