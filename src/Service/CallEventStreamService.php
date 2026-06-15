<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallSession;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class CallEventStreamService
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param array{telnyx:int,recording:int,transcript:int} $cursor
     * @return array{
     *     events:list<array<string, mixed>>,
     *     cursor:array{telnyx:int,recording:int,transcript:int}
     * }
     */
    public function fetchSince(CallSession $session, array $cursor): array
    {
        $rootSession = $session->getParentCallSession() ?? $session;
        $rootSessionId = $rootSession->getId();
        if (null === $rootSessionId) {
            return ['events' => [], 'cursor' => $cursor];
        }

        $sessionIds = $this->sessionIdsForRoot($rootSessionId);
        $events = [
            ...$this->fetchTelnyxEvents($sessionIds, $cursor['telnyx']),
            ...$this->fetchRecordingEvents($rootSessionId, $cursor['recording']),
            ...$this->fetchTranscriptEvents($rootSessionId, $cursor['transcript']),
        ];

        usort($events, static function (array $left, array $right): int {
            $timeCompare = strcmp((string) $left['occurredAt'], (string) $right['occurredAt']);
            if (0 !== $timeCompare) {
                return $timeCompare;
            }

            return strcmp((string) $left['streamId'], (string) $right['streamId']);
        });

        foreach ($events as $event) {
            if ('telnyx_event' === $event['source']) {
                $cursor['telnyx'] = max($cursor['telnyx'], (int) $event['sequence']);
            } elseif ('call_recording' === $event['source']) {
                $cursor['recording'] = max($cursor['recording'], (int) $event['sequence']);
            } elseif ('call_transcript' === $event['source']) {
                $cursor['transcript'] = max($cursor['transcript'], (int) $event['sequence']);
            }
        }

        return [
            'events' => $events,
            'cursor' => $cursor,
        ];
    }

    /**
     * @return list<int>
     */
    private function sessionIdsForRoot(int $rootSessionId): array
    {
        $rows = $this->connection->executeQuery(
            <<<'SQL'
                SELECT id
                FROM call_session
                WHERE id = :rootSessionId
                   OR parent_call_session_id = :rootSessionId
                ORDER BY id ASC
                SQL,
            ['rootSessionId' => $rootSessionId],
        )->fetchFirstColumn();

        return array_map(static fn (mixed $id): int => (int) $id, $rows);
    }

    /**
     * @param list<int> $sessionIds
     * @return list<array<string, mixed>>
     */
    private function fetchTelnyxEvents(array $sessionIds, int $afterId): array
    {
        if ([] === $sessionIds) {
            return [];
        }

        $rows = $this->connection->executeQuery(
            <<<'SQL'
                SELECT id, provider_event_id, event_type, payload, received_at, call_session_id, call_leg_id
                FROM telnyx_event
                WHERE call_session_id IN (:sessionIds)
                  AND id > :afterId
                ORDER BY id ASC
                SQL,
            [
                'sessionIds' => $sessionIds,
                'afterId' => $afterId,
            ],
            [
                'sessionIds' => ArrayParameterType::INTEGER,
            ],
        )->fetchAllAssociative();

        return array_map(function (array $row): array {
            return [
                'streamId' => sprintf('telnyx:%d', (int) $row['id']),
                'sequence' => (int) $row['id'],
                'source' => 'telnyx_event',
                'event' => (string) $row['event_type'],
                'occurredAt' => $this->stringDate($row['received_at']),
                'providerEventId' => (string) $row['provider_event_id'],
                'callSessionId' => isset($row['call_session_id']) ? (int) $row['call_session_id'] : null,
                'callLegId' => isset($row['call_leg_id']) ? (int) $row['call_leg_id'] : null,
                'payload' => $this->decodeJsonColumn($row['payload'] ?? null),
            ];
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRecordingEvents(int $rootSessionId, int $afterId): array
    {
        $rows = $this->connection->executeQuery(
            <<<'SQL'
                SELECT id, provider_recording_id, status, format, duration_seconds, recording_started_at,
                       recording_ended_at, created_at, call_leg_id
                FROM call_recording
                WHERE call_session_id = :rootSessionId
                  AND id > :afterId
                ORDER BY id ASC
                SQL,
            [
                'rootSessionId' => $rootSessionId,
                'afterId' => $afterId,
            ],
        )->fetchAllAssociative();

        return array_map(function (array $row): array {
            $status = (string) $row['status'];

            return [
                'streamId' => sprintf('recording:%d', (int) $row['id']),
                'sequence' => (int) $row['id'],
                'source' => 'call_recording',
                'event' => sprintf('call.recording.%s', $status),
                'occurredAt' => $this->stringDate($row['created_at']),
                'recordingId' => (int) $row['id'],
                'providerRecordingId' => $row['provider_recording_id'],
                'callLegId' => isset($row['call_leg_id']) ? (int) $row['call_leg_id'] : null,
                'payload' => [
                    'status' => $status,
                    'format' => $row['format'],
                    'durationSeconds' => isset($row['duration_seconds']) ? (int) $row['duration_seconds'] : null,
                    'recordingStartedAt' => $this->stringDate($row['recording_started_at'] ?? null),
                    'recordingEndedAt' => $this->stringDate($row['recording_ended_at'] ?? null),
                ],
            ];
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTranscriptEvents(int $rootSessionId, int $afterId): array
    {
        $rows = $this->connection->executeQuery(
            <<<'SQL'
                SELECT id, provider, model, status, completed_at, failed_at, created_at, call_recording_id
                FROM call_transcript
                WHERE call_session_id = :rootSessionId
                  AND id > :afterId
                ORDER BY id ASC
                SQL,
            [
                'rootSessionId' => $rootSessionId,
                'afterId' => $afterId,
            ],
        )->fetchAllAssociative();

        return array_map(function (array $row): array {
            $status = (string) $row['status'];

            return [
                'streamId' => sprintf('transcript:%d', (int) $row['id']),
                'sequence' => (int) $row['id'],
                'source' => 'call_transcript',
                'event' => sprintf('call.transcript.%s', $status),
                'occurredAt' => $this->stringDate($row['completed_at'] ?? $row['failed_at'] ?? $row['created_at']),
                'transcriptId' => (int) $row['id'],
                'recordingId' => (int) $row['call_recording_id'],
                'payload' => [
                    'status' => $status,
                    'provider' => $row['provider'],
                    'model' => $row['model'],
                    'completedAt' => $this->stringDate($row['completed_at'] ?? null),
                    'failedAt' => $this->stringDate($row['failed_at'] ?? null),
                ],
            ];
        }, $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonColumn(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function stringDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return is_string($value) && '' !== trim($value) ? $value : null;
    }
}
