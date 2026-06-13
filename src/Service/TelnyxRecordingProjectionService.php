<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallLeg;
use App\Entity\CallRecording;
use App\Entity\CallSession;
use App\Repository\CallLegRepository;
use App\Repository\CallRecordingRepository;
use App\Repository\CallSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class TelnyxRecordingProjectionService
{
    public function __construct(
        private readonly CallSessionRepository $sessionRepository,
        private readonly CallLegRepository $legRepository,
        private readonly CallRecordingRepository $recordingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly RecordingImportService $recordingImport,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rawPayload
     */
    public function project(string $eventType, array $data, array $rawPayload): void
    {
        if ('call.recording.transcription.saved' === $eventType) {
            $this->logger->info('Telnyx recording transcription webhook stored without transcription processing.', [
                'provider_event_id' => $data['id'] ?? null,
            ]);

            return;
        }

        if (!in_array($eventType, ['call.recording.saved', 'call.recording.error'], true)) {
            return;
        }

        $payload = $data['payload'] ?? null;
        if (!is_array($payload)) {
            return;
        }

        [$session, $leg] = $this->resolveCall($payload);
        if (null === $session) {
            throw new \RuntimeException('Recording webhook could not be linked to a call session.');
        }
        $rootSession = $session->getParentCallSession() ?? $session;

        $providerRecordingId = $this->firstString($payload, ['recording_id', 'recordingId', 'id']);
        $recording = null !== $providerRecordingId
            ? $this->recordingRepository->findOneByProviderRecordingId($providerRecordingId)
            : null;
        $recording ??= $this->recordingRepository->findRequested($rootSession, $leg);
        if (null === $recording) {
            $recording = new CallRecording($rootSession);
            $recording->setCallLeg($leg);
            $this->entityManager->persist($recording);
        }

        $isSaved = 'call.recording.saved' === $eventType;
        $recording
            ->setProviderRecordingId($providerRecordingId ?? $recording->getProviderRecordingId())
            ->setStatus($isSaved ? 'saved' : 'failed')
            ->setRawPayload($rawPayload)
            ->setChannelMapping($recording->getChannelMapping() ?? $this->defaultChannelMapping($rootSession))
            ->touch();

        if ($isSaved) {
            $this->applySavedMetadata($recording, $payload);
            if (null !== $recording->getProviderDownloadUrl()) {
                $recording->setStatus('import_pending');
            }
        } else {
            $recording->setImportError(
                $this->firstString($payload, ['error', 'message', 'error_message']) ?? 'Telnyx recording failed.',
            );
        }

        $this->entityManager->flush();
        if ('import_pending' === $recording->getStatus()) {
            $this->recordingImport->import($recording);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{CallSession|null, CallLeg|null}
     */
    private function resolveCall(array $payload): array
    {
        $leg = null;
        $providerLegId = $this->firstString($payload, ['call_leg_id']);
        if (null !== $providerLegId) {
            $leg = $this->legRepository->findOneByProviderLegId($providerLegId);
        }

        $session = $leg?->getCallSession();
        $providerSessionId = $this->firstString($payload, ['call_session_id']);
        if (null === $session && null !== $providerSessionId) {
            $session = $this->sessionRepository->findOneByProviderSessionId($providerSessionId);
        }

        return [$session, $leg];
    }

    /** @param array<string, mixed> $payload */
    private function applySavedMetadata(CallRecording $recording, array $payload): void
    {
        $format = $this->firstString($payload, ['format', 'recording_format']);
        $recordingUrls = $payload['recording_urls'] ?? null;
        if (null === $format && is_array($recordingUrls)) {
            $format = array_key_first($recordingUrls);
        }

        $startedAt = $this->dateValue($payload['recording_started_at'] ?? $payload['start_time'] ?? null);
        $endedAt = $this->dateValue($payload['recording_ended_at'] ?? $payload['end_time'] ?? null);
        $duration = $this->firstInteger($payload, ['duration_seconds', 'duration_secs']);
        if (null === $duration && null !== $startedAt && null !== $endedAt) {
            $duration = max(0, $endedAt->getTimestamp() - $startedAt->getTimestamp());
        }

        $recordingUrl = $this->recordingUrl($payload, $format);
        $recording
            ->setFormat($format ?? $recording->getFormat())
            ->setContentType($this->firstString($payload, ['content_type', 'mime_type']))
            ->setDurationSeconds($duration)
            ->setSizeBytes($this->firstInteger($payload, ['size_bytes', 'file_size']))
            ->setRecordingStartedAt($startedAt)
            ->setRecordingEndedAt($endedAt)
            ->setProviderDownloadUrl($recordingUrl);
    }

    /** @param array<string, mixed> $payload */
    private function recordingUrl(array $payload, ?string $format): ?string
    {
        foreach (['recording_urls', 'public_recording_urls'] as $key) {
            $urls = $payload[$key] ?? null;
            if (!is_array($urls)) {
                continue;
            }
            if (null !== $format && is_string($urls[$format] ?? null)) {
                return $urls[$format];
            }
            foreach ($urls as $url) {
                if (is_string($url) && '' !== $url) {
                    return $url;
                }
            }
        }

        return $this->firstString($payload, ['download_url', 'recording_url', 'url']);
    }

    /** @param array<string, mixed> $values */
    private function firstString(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $values[$key] ?? null;
            if (is_string($value) && '' !== trim($value)) {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $values */
    private function firstInteger(array $values, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $values[$key] ?? null;
            if (is_int($value) && $value >= 0) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function dateValue(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array<string, string>|null
     */
    private function defaultChannelMapping(CallSession $session): ?array
    {
        return match ($session->getFlowType()) {
            CallSession::FLOW_TYPE_CLICK_TO_CALL => [
                'flowType' => CallSession::FLOW_TYPE_CLICK_TO_CALL,
                'ch_0' => 'agent',
                'ch_1' => 'customer',
                'confidence' => 'assumed_agent_dialed_first_verify_with_test_recording',
                'notes' => 'For click-to-call, agent is dialed first and customer second. Verify left/right channel assignment empirically.',
            ],
            CallSession::FLOW_TYPE_INBOUND_FORWARD => [
                'flowType' => CallSession::FLOW_TYPE_INBOUND_FORWARD,
                'ch_0' => 'caller',
                'ch_1' => 'forwarded_party',
                'confidence' => 'observed',
            ],
            default => null,
        };
    }
}
