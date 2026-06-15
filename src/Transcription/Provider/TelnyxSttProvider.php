<?php

declare(strict_types=1);

namespace App\Transcription\Provider;

use App\Entity\CallSummary;
use App\Entity\CallTranscript;
use App\Entity\CallTranscriptSegment;
use App\Entity\CallLeg;
use App\Entity\CallSession;
use App\Entity\TelnyxEvent;
use App\Entity\TranscriptionJob;
use App\Repository\CallSummaryRepository;
use App\Repository\CallTranscriptSegmentRepository;
use App\Repository\CallTranscriptRepository;
use App\Repository\CallRecordingRepository;
use App\Repository\TranscriptionJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Transcription\SttProviderInterface;
use App\Transcription\WebhookDrivenSttProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TelnyxSttProvider implements SttProviderInterface, WebhookDrivenSttProviderInterface
{
    public function __construct(
        private readonly TranscriptionJobRepository $jobs,
        private readonly CallRecordingRepository $recordings,
        private readonly CallTranscriptRepository $transcripts,
        private readonly CallTranscriptSegmentRepository $segments,
        private readonly CallSummaryRepository $summaries,
        private readonly EntityManagerInterface $entityManager,
        private readonly TelnyxTranscriptionConfiguration $configuration,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getName(): string
    {
        return 'telnyx';
    }

    public function submit(TranscriptionJob $job): void
    {
        if (!$this->configuration->isEnabled()) {
            throw new \RuntimeException('Telnyx transcription is disabled by configuration.');
        }

        $now = new \DateTimeImmutable();
        $job
            ->setProvider('telnyx')
            ->setProviderStatus('waiting_for_recording_transcription_webhook')
            ->setStatus('submitted')
            ->setSubmittedAt($now)
            ->setProviderModel($job->getProviderModel() ?? $this->configuration->getModel())
            ->setProviderConfig($job->getProviderConfig() ?? $this->configuration->toProviderConfig())
            ->setRawProviderResponse([
                'submissionMode' => 'record_start_transcription_webhook',
                'telnyxRecordingTranscriptionEnabled' => $this->configuration->isEnabled(),
                'note' => 'Telnyx recording transcription completes asynchronously via call.recording.transcription.saved.',
            ])
            ->touch($now);
    }

    public function handleWebhook(array $payload, TelnyxEvent $event): void
    {
        $data = $payload['data'] ?? null;
        $eventPayload = is_array($data) ? ($data['payload'] ?? null) : null;
        if (!is_array($eventPayload)) {
            return;
        }

        $recording = null;
        $recordingId = $this->firstString($eventPayload, ['recording_id', 'recordingId']);
        if (null === $recordingId) {
            $recording = null;
        } else {
            $recording = $this->recordings->findOneByProviderRecordingId($recordingId);
        }
        if (null !== $recordingId && null === $recording) {
            $this->logger->warning('Telnyx transcription webhook could not be matched to a recording.', [
                'provider_event_id' => $event->getProviderEventId(),
                'recording_id' => $recordingId,
            ]);
        }

        $providerJobId = $this->firstString($eventPayload, ['recording_transcription_id', 'transcription_id']);
        $job = null;
        if (null !== $providerJobId) {
            $job = $this->jobs->findOneByProviderJobId($this->getName(), $providerJobId);
        }
        if (null === $job && null !== $recording) {
            $job = $this->jobs->findOneForRecordingAndProvider($recording, $this->getName());
        }
        if (null === $job && null !== $event->getCallSession()) {
            $rootSession = $event->getCallSession()?->getParentCallSession() ?? $event->getCallSession();
            if (null !== $rootSession) {
                $job = $this->jobs->findOneForSessionAndProvider($rootSession, $this->getName());
            }
        }
        if (null === $job) {
            $job = (new TranscriptionJob($recording))
                ->setProvider($this->getName())
                ->setCallRecording($recording)
                ->setCallSession($recording?->getCallSession() ?? $event->getCallSession()?->getParentCallSession() ?? $event->getCallSession())
                ->setCallLeg($recording?->getCallLeg() ?? $event->getCallLeg())
                ->setChannelMapping($recording?->getChannelMapping())
                ->setProviderConfig($this->configuration->toProviderConfig());
            $this->entityManager->persist($job);
        }

        $isRealtimeTranscriptionEvent = 'call.transcription' === $event->getEventType();
        $providerStatus = $this->firstString($eventPayload, ['status']) ?? ($isRealtimeTranscriptionEvent ? 'streaming' : 'completed');
        $transcriptText = $this->extractTranscriptText($eventPayload);
        $transcriptUrl = $this->firstString($eventPayload, ['transcription_url', 'transcript_url', 'url']);
        $downloadedTranscript = null;
        if (null === $transcriptText && null !== $transcriptUrl) {
            $downloadedTranscript = $this->downloadTranscript($transcriptUrl);
            $transcriptText = $downloadedTranscript['text'];
        }
        $rawResponse = array_filter([
            'webhook' => $payload,
            'downloadedTranscript' => $downloadedTranscript['raw'] ?? null,
        ], static fn (mixed $value): bool => null !== $value);
        $providerModel = $this->firstString($eventPayload, ['model', 'transcription_model', 'engine', 'transcription_engine'])
            ?? $job->getProviderModel()
            ?? $this->configuration->getModel()
            ?? 'telnyx';
        $language = $this->firstString($eventPayload, ['language', 'transcription_language']) ?? $this->configuration->getLanguage();

        $job
            ->setProviderJobId($providerJobId)
            ->setProviderStatus($providerStatus)
            ->setProviderModel($providerModel)
            ->setCallRecording($recording ?? $job->getCallRecording())
            ->setCallSession($job->getCallSession() ?? $recording?->getCallSession() ?? $event->getCallSession()?->getParentCallSession() ?? $event->getCallSession())
            ->setCallLeg($job->getCallLeg() ?? $recording?->getCallLeg() ?? $event->getCallLeg())
            ->setRawProviderResponse($rawResponse)
            ->touch();

        if ('call.recording.saved' === $event->getEventType()) {
            $this->finalizeFromRecordingSavedWebhook($job, $providerModel, $language, $rawResponse);
            $this->entityManager->flush();

            return;
        }

        if ('call.recording.error' === $event->getEventType()) {
            $job
                ->setStatus('failed')
                ->setProviderStatus('recording_failed')
                ->setErrorMessage('Telnyx reported a recording failure before transcription completed.')
                ->setFailedAt(new \DateTimeImmutable())
                ->touch();
            $this->entityManager->flush();

            return;
        }

        if ($isRealtimeTranscriptionEvent) {
            $transcriptionData = $eventPayload['transcription_data'] ?? null;
            $isFinalSegment = is_array($transcriptionData) ? true === ($transcriptionData['is_final'] ?? false) : true;

            $job
                ->setStatus('processing')
                ->setProviderStatus($isFinalSegment ? 'streaming_final_segment' : 'streaming_partial_segment')
                ->setStartedAt($job->getStartedAt() ?? new \DateTimeImmutable())
                ->touch();

            if ($isFinalSegment && null !== $transcriptText && '' !== trim($transcriptText)) {
                $transcript = $this->upsertTranscript(
                    $job,
                    $providerModel,
                    $language,
                    $transcriptText,
                    $rawResponse,
                    'available',
                    $event->getEventType(),
                );
                $this->storeTranscriptSegment($transcript, $job, $event, $eventPayload, $transcriptText, true);
            }

            $this->entityManager->flush();

            return;
        }

        if ($this->isPendingWebhook($event->getEventType(), $providerStatus, $eventPayload, $transcriptText)) {
            $job->setStatus('processing');
            $this->entityManager->flush();
            return;
        }

        if ($this->isFailureWebhook($event->getEventType(), $providerStatus)) {
            $job
                ->setStatus('failed')
                ->setErrorMessage($this->firstString($eventPayload, ['error', 'error_message', 'failure_reason']) ?? sprintf(
                'Telnyx transcription webhook reported status "%s".',
                $providerStatus,
            ))
                ->setFailedAt(new \DateTimeImmutable())
                ->touch();
            $this->upsertTranscript($job, $providerModel, $language, null, $rawResponse, 'failed', $event->getEventType());
            $this->entityManager->flush();

            return;
        }

        if (null !== $transcriptText && '' !== trim($transcriptText)) {
            $job
                ->setStatus('completed')
                ->setTranscriptText($transcriptText)
                ->setTranscriptJson($rawResponse)
                ->setCompletedAt(new \DateTimeImmutable())
                ->setErrorMessage(null)
                ->touch();
            $this->upsertTranscript($job, $providerModel, $language, $transcriptText, $rawResponse, 'available', $event->getEventType());
        }

        $this->entityManager->flush();
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

    /**
     * @param array<string, mixed> $eventPayload
     */
    private function extractTranscriptText(array $eventPayload): ?string
    {
        $text = $this->firstString($eventPayload, ['transcription_text', 'text', 'transcript']);
        if (null !== $text) {
            return $text;
        }

        $transcriptionData = $eventPayload['transcription_data'] ?? null;
        if (is_array($transcriptionData)) {
            return $this->firstString($transcriptionData, ['transcript', 'text']);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $eventPayload
     */
    private function isPendingWebhook(string $eventType, string $providerStatus, array $eventPayload, ?string $transcriptText): bool
    {
        if ('call.transcription' === $eventType) {
            $transcriptionData = $eventPayload['transcription_data'] ?? null;
            if (is_array($transcriptionData) && true !== ($transcriptionData['is_final'] ?? false)) {
                return true;
            }
        }

        return in_array(strtolower($providerStatus), ['queued', 'pending', 'processing', 'in_progress'], true)
            && null === $transcriptText;
    }

    private function isFailureWebhook(string $eventType, string $providerStatus): bool
    {
        return 'call.transcription.error' === $eventType
            || in_array(strtolower($providerStatus), ['error', 'failed', 'failure'], true);
    }

    /**
     * @param array<string, mixed>|null $rawResponse
     */
    private function upsertTranscript(
        TranscriptionJob $job,
        string $model,
        ?string $language,
        ?string $transcriptText,
        ?array $rawResponse,
        string $status,
        ?string $eventType = null,
    ): CallTranscript {
        $transcript = $this->transcripts->findOneByTranscriptionJob($job)
            ?? new CallTranscript($job->getCallRecording(), $model, $status);

        $mergedText = $this->mergeTranscriptText(
            $transcript->getTranscriptText(),
            $transcriptText,
            $eventType,
        );

        $transcript
            ->setCallRecording($job->getCallRecording())
            ->setCallSession($job->getCallSession())
            ->setCallLeg($job->getCallLeg())
            ->setTranscriptionJob($job)
            ->setProvider($this->getName())
            ->setModel($model)
            ->setStatus($status)
            ->setTranscriptText($mergedText)
            ->setRawResponse($rawResponse)
            ->setChannelMapping($job->getChannelMapping())
            ->setLanguage($language)
            ->setStartedAt($transcript->getStartedAt() ?? $job->getSubmittedAt() ?? new \DateTimeImmutable())
            ->touch();

        if ('available' === $status) {
            $transcript->setCompletedAt(new \DateTimeImmutable())->setErrorMessage(null);
        }
        if ('failed' === $status) {
            $transcript->setFailedAt(new \DateTimeImmutable())->setErrorMessage($job->getErrorMessage());
        }

        if (null === $transcript->getId()) {
            $this->entityManager->persist($transcript);
        }

        if ('available' === $status) {
            $this->ensurePendingSummary($transcript);
        }

        return $transcript;
    }

    /**
     * @param array<string, mixed>|null $rawResponse
     */
    private function finalizeFromRecordingSavedWebhook(
        TranscriptionJob $job,
        string $model,
        ?string $language,
        ?array $rawResponse,
    ): void {
        $transcript = $this->transcripts->findOneByTranscriptionJob($job);
        $transcriptText = null !== $transcript ? trim((string) ($transcript->getTranscriptText() ?? '')) : '';

        if ('' === $transcriptText) {
            $job
                ->setStatus('processing')
                ->setProviderStatus('recording_saved_waiting_for_transcript')
                ->touch();

            return;
        }

        $now = new \DateTimeImmutable();
        $job
            ->setStatus('completed')
            ->setProviderStatus('completed_from_recording_saved')
            ->setTranscriptText($transcriptText)
            ->setTranscriptJson($rawResponse)
            ->setCompletedAt($now)
            ->setErrorMessage(null)
            ->touch();

        $transcript
            ->setProvider($this->getName())
            ->setModel($model)
            ->setStatus('available')
            ->setTranscriptText($transcriptText)
            ->setRawResponse($rawResponse)
            ->setLanguage($language)
            ->setCompletedAt($now)
            ->setFailedAt(null)
            ->setErrorMessage(null)
            ->touch();

        $this->ensurePendingSummary($transcript);
    }

    private function mergeTranscriptText(?string $existingText, ?string $incomingText, ?string $eventType): ?string
    {
        $existingText = null !== $existingText ? trim($existingText) : null;
        $incomingText = null !== $incomingText ? trim($incomingText) : null;

        if (null === $existingText || '' === $existingText) {
            return $incomingText;
        }

        if (null === $incomingText || '' === $incomingText) {
            return $existingText;
        }

        if ('call.transcription' === $eventType) {
            if (str_contains($existingText, $incomingText)) {
                return $existingText;
            }

            return trim($existingText."\n".$incomingText);
        }

        // For non-streaming follow-up webhooks, prefer the richer transcript body.
        return mb_strlen($incomingText) > mb_strlen($existingText) ? $incomingText : $existingText;
    }

    /**
     * @return array{text:?string,raw:array<string, mixed>|string|null}
     */
    private function downloadTranscript(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json, text/plain;q=0.9',
                ],
            ]);
            $body = $response->getContent();
        } catch (\Throwable $exception) {
            $this->logger->warning('Telnyx transcription URL download failed.', [
                'url' => $url,
                'exception' => $exception,
            ]);

            return ['text' => null, 'raw' => null];
        }

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['text' => trim($body) !== '' ? trim($body) : null, 'raw' => substr($body, 0, 4000)];
        }

        if (!is_array($decoded)) {
            return ['text' => null, 'raw' => null];
        }

        return [
            'text' => $this->extractTranscriptText($decoded),
            'raw' => $decoded,
        ];
    }

    /**
     * @param array<string, mixed> $eventPayload
     */
    private function storeTranscriptSegment(
        CallTranscript $transcript,
        TranscriptionJob $job,
        TelnyxEvent $event,
        array $eventPayload,
        string $text,
        bool $isFinal,
    ): void {
        $segment = (new CallTranscriptSegment(
            $transcript,
            null === $transcript->getId() ? 1 : $this->segments->nextSequenceNumber($transcript),
            $text,
        ))
            ->setCallSession($job->getCallSession())
            ->setCallLeg($job->getCallLeg())
            ->setProviderEventId($event->getProviderEventId())
            ->setSpeakerRole($this->inferSpeakerRole($job->getCallSession(), $job->getCallLeg()))
            ->setIsFinal($isFinal)
            ->setRawPayload([
                'eventType' => $event->getEventType(),
                'payload' => $eventPayload,
            ])
            ->setOccurredAt($this->occurredAtFromWebhook($event->getPayload()) ?? $event->getReceivedAt());

        $this->entityManager->persist($segment);
    }

    private function ensurePendingSummary(CallTranscript $transcript): void
    {
        $summary = $this->summaries->findOneByTranscript($transcript);
        if (null === $summary) {
            $summary = (new CallSummary($transcript))
                ->setStatus('pending')
                ->touch();
            $this->entityManager->persist($summary);

            return;
        }

        if ('available' !== $summary->getStatus()) {
            $summary->setStatus('pending')->setErrorMessage(null)->touch();
        }
    }

    private function inferSpeakerRole(?CallSession $session, ?CallLeg $leg): ?string
    {
        if (null !== $leg?->getRole()) {
            return $leg->getRole();
        }

        if (null === $session || null === $leg) {
            return null;
        }

        return match ($session->getFlowType()) {
            CallSession::FLOW_TYPE_INBOUND_FORWARD => 'incoming' === $leg->getDirection() ? CallLeg::ROLE_CALLER : CallLeg::ROLE_VENDOR,
            CallSession::FLOW_TYPE_CLICK_TO_CALL => 'incoming' === $leg->getDirection() ? CallLeg::ROLE_AGENT : CallLeg::ROLE_CUSTOMER,
            CallSession::FLOW_TYPE_TRANSCRIPTION_TEST => 'outgoing' === $leg->getDirection() ? 'target' : CallLeg::ROLE_SYSTEM,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $webhook
     */
    private function occurredAtFromWebhook(array $webhook): ?\DateTimeImmutable
    {
        $data = $webhook['data'] ?? null;
        $occurredAt = is_array($data) ? ($data['occurred_at'] ?? null) : null;
        if (!is_string($occurredAt) || '' === trim($occurredAt)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($occurredAt);
        } catch (\Exception) {
            return null;
        }
    }
}
