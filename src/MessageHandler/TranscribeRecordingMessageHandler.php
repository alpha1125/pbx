<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\CallTranscript;
use App\Message\TranscribeRecordingMessage;
use App\Repository\CallRecordingRepository;
use App\Repository\CallTranscriptRepository;
use App\Service\OpenAiTranscriptionService;
use App\Service\RecordingStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TranscribeRecordingMessageHandler
{
    public function __construct(
        private readonly CallRecordingRepository $recordingRepository,
        private readonly CallTranscriptRepository $transcriptRepository,
        private readonly RecordingStorageService $recordingStorage,
        private readonly OpenAiTranscriptionService $transcriptionService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly bool $transcriptionEnabled,
        private readonly string $transcriptionModel,
    ) {
    }

    public function __invoke(TranscribeRecordingMessage $message): void
    {
        $recording = $this->recordingRepository->find($message->callRecordingId);
        if (null === $recording) {
            $this->logger->warning('Transcription message skipped because recording was not found.', [
                'recording_id' => $message->callRecordingId,
            ]);

            return;
        }

        if (!$this->transcriptionEnabled) {
            $this->logger->info('Transcription skipped because OpenAI transcription is disabled.', [
                'recording_id' => $recording->getId(),
            ]);

            return;
        }

        if ('' === trim($this->transcriptionModel)) {
            $this->logger->warning('Transcription skipped because OPENAI_TRANSCRIPTION_MODEL is missing.', [
                'recording_id' => $recording->getId(),
            ]);

            return;
        }

        if ('imported' !== $recording->getStatus() || null === $recording->getS3Bucket() || null === $recording->getS3Key()) {
            $this->logger->info('Transcription skipped because recording is not imported in S3.', [
                'recording_id' => $recording->getId(),
                'status' => $recording->getStatus(),
            ]);

            return;
        }

        if ($this->transcriptRepository->hasCurrentForRecording('openai', $this->transcriptionModel, $recording)) {
            return;
        }

        $transcript = $this->transcriptRepository->findLatestForRecording('openai', $this->transcriptionModel, $recording)
            ?? new CallTranscript($recording, $this->transcriptionModel);
        if (null === $transcript->getId()) {
            $this->entityManager->persist($transcript);
        }

        $transcript
            ->setStatus('processing')
            ->setErrorMessage(null)
            ->setStartedAt(new \DateTimeImmutable())
            ->setCompletedAt(null)
            ->setFailedAt(null)
            ->touch();
        $this->entityManager->flush();

        $tempFile = null;
        try {
            $tempFile = $this->recordingStorage->downloadToTemporaryFile($recording);
            $result = $this->transcriptionService->transcribeAudioFile(
                $tempFile,
                $this->recordingStorage->suggestFilename($recording),
            );

            $transcript
                ->setStatus('available')
                ->setTranscriptText($result['text'])
                ->setRawResponse($result['raw'])
                ->setLanguage($result['language'])
                ->setDurationSeconds($result['durationSeconds'])
                ->setCompletedAt(new \DateTimeImmutable())
                ->setErrorMessage(null)
                ->touch();
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $transcript
                ->setStatus('failed')
                ->setErrorMessage($exception->getMessage())
                ->setFailedAt(new \DateTimeImmutable())
                ->touch();
            $this->entityManager->flush();

            $this->logger->error('OpenAI transcription failed.', [
                'recording_id' => $recording->getId(),
                'provider_recording_id' => $recording->getProviderRecordingId(),
                'exception' => $exception,
            ]);
        } finally {
            if (null !== $tempFile && is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }
}
