<?php

declare(strict_types=1);

namespace App\Service;

final class PocBrowserSoftphoneTranscriptMergeService
{
    /**
     * @param list<array<string, mixed>> $segments
     * @param array<string, mixed> $incomingSegment
     *
     * @return array{
     *     segments:list<array<string, mixed>>,
     *     segment:array<string, mixed>,
     *     added:bool,
     *     changed:bool
     * }
     */
    public function mergeSegments(array $segments, array $incomingSegment): array
    {
        $normalizedIncoming = $this->normalizeSegment($incomingSegment);
        if (null === $normalizedIncoming) {
            return [
                'segments' => $this->normalizeSegments($segments),
                'segment' => [],
                'added' => false,
                'changed' => false,
            ];
        }

        $normalizedSegments = [];
        $matchedIndex = null;
        $incomingKey = $this->segmentKey($normalizedIncoming);

        foreach ($segments as $segment) {
            $normalizedSegment = $this->normalizeSegment($segment);
            if (null === $normalizedSegment) {
                continue;
            }

            $currentIndex = count($normalizedSegments);
            $normalizedSegments[] = $normalizedSegment;
            if (null === $matchedIndex && $this->segmentKey($normalizedSegment) === $incomingKey) {
                $matchedIndex = $currentIndex;
            }
        }

        if (null === $matchedIndex) {
            $normalizedSegments[] = $normalizedIncoming;

            return [
                'segments' => $this->sortSegments($normalizedSegments),
                'segment' => $normalizedIncoming,
                'added' => true,
                'changed' => true,
            ];
        }

        $existingSegment = $normalizedSegments[$matchedIndex];
        if ($existingSegment['fingerprint'] === $normalizedIncoming['fingerprint']) {
            return [
                'segments' => $this->sortSegments($normalizedSegments),
                'segment' => $existingSegment,
                'added' => false,
                'changed' => false,
            ];
        }

        if (true === $existingSegment['isFinal'] && false === $normalizedIncoming['isFinal']) {
            return [
                'segments' => $this->sortSegments($normalizedSegments),
                'segment' => $existingSegment,
                'added' => false,
                'changed' => false,
            ];
        }

        $normalizedIncoming['id'] = $existingSegment['id'];
        $normalizedIncoming['sequence'] = $existingSegment['sequence'];
        $normalizedSegments[$matchedIndex] = $normalizedIncoming;

        return [
            'segments' => $this->sortSegments($normalizedSegments),
            'segment' => $normalizedIncoming,
            'added' => false,
            'changed' => true,
        ];
    }

    /**
     * @param list<array<string, mixed>> $segments
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeSegments(array $segments): array
    {
        $normalized = [];
        foreach ($segments as $segment) {
            $normalizedSegment = $this->normalizeSegment($segment);
            if (null !== $normalizedSegment) {
                $normalized[] = $normalizedSegment;
            }
        }

        return $this->sortSegments($normalized);
    }

    /**
     * @param array<string, mixed> $segment
     *
     * @return array<string, mixed>|null
     */
    private function normalizeSegment(array $segment): ?array
    {
        $id = $this->normalizeInteger($segment['id'] ?? null);
        $sequence = $this->normalizeInteger($segment['sequence'] ?? null) ?? $id;
        $text = is_string($segment['text'] ?? null) ? trim($segment['text']) : '';

        if (null === $id || $id <= 0 || '' === $text) {
            return null;
        }

        return [
            'id' => $id,
            'sequence' => $sequence > 0 ? $sequence : $id,
            'speaker' => $this->normalizeSpeaker($segment['speaker'] ?? null),
            'text' => $text,
            'occurredAt' => is_string($segment['occurredAt'] ?? null) ? $segment['occurredAt'] : null,
            'displayTime' => is_string($segment['displayTime'] ?? null) && '' !== trim((string) $segment['displayTime']) ? trim((string) $segment['displayTime']) : null,
            'isFinal' => true === ($segment['isFinal'] ?? false),
            'sourceEventId' => $this->normalizeNullableString($segment['sourceEventId'] ?? null),
            'fingerprint' => $this->normalizeNullableString($segment['fingerprint'] ?? null) ?? sprintf('segment:%d', $id),
        ];
    }

    /**
     * @param list<array<string, mixed>> $segments
     *
     * @return list<array<string, mixed>>
     */
    private function sortSegments(array $segments): array
    {
        usort($segments, static function (array $left, array $right): int {
            $sequenceDelta = (int) ($left['sequence'] ?? $left['id'] ?? 0) - (int) ($right['sequence'] ?? $right['id'] ?? 0);

            return 0 !== $sequenceDelta ? $sequenceDelta : (int) ($left['id'] ?? 0) - (int) ($right['id'] ?? 0);
        });

        return array_values($segments);
    }

    private function segmentKey(array $segment): string
    {
        return $segment['sourceEventId'] ?? $segment['fingerprint'] ?? sprintf('segment:%d', (int) $segment['id']);
    }

    private function normalizeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && '' !== trim($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeSpeaker(mixed $speaker): string
    {
        $normalized = is_string($speaker) ? strtolower(trim($speaker)) : '';

        return in_array($normalized, ['csr', 'agent', 'operator', 'representative'], true) ? 'csr' : 'customer';
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return '' === $normalized ? null : $normalized;
    }
}
