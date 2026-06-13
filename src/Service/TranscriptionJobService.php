<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallRecording;
use App\Entity\CallSummary;
use App\Entity\CallTranscript;
use App\Entity\TranscriptionJob;
use App\Repository\CallRecordingRepository;
use App\Repository\CallTranscriptRepository;
use App\Repository\CallSummaryRepository;
use App\Repository\TranscriptionJobRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final class TranscriptionJobService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly TranscriptionJobRepository $jobs,
        private readonly CallRecordingRepository $recordings,
        private readonly CallTranscriptRepository $transcripts,
        private readonly RecordingStorageService $recordingStorage,
        private readonly bool $localWorkerEnabled,
        private readonly string $sttProvider,
        private readonly int $lockSeconds,
    ) {
    }

    public function shouldUseLocalWorker(): bool
    {
        return $this->localWorkerEnabled && 'local_worker' === $this->sttProvider;
    }

    public function createPendingJobForRecording(CallRecording $recording): ?TranscriptionJob
    {
        if (!$this->shouldUseLocalWorker()) {
            return null;
        }

        if (
            'imported' !== $recording->getStatus()
            || null === $recording->getS3Bucket()
            || null === $recording->getS3Key()
            || $this->transcripts->hasTranscriptForRecording($recording)
            || $this->jobs->hasCurrentJobForRecording($recording)
        ) {
            return null;
        }

        $job = (new TranscriptionJob($recording))
            ->setCallSession($recording->getCallSession())
            ->setProvider('local_worker')
            ->setStatus('pending')
            ->setInputS3Bucket($recording->getS3Bucket())
            ->setInputS3Key($recording->getS3Key())
            ->setChannelMapping($recording->getChannelMapping())
            ->setNextAttemptAt(new \DateTimeImmutable())
            ->touch();

        $this->entityManager->persist($job);

        return $job;
    }

    public function createPendingJobsForImportedRecordings(int $limit): int
    {
        if (!$this->shouldUseLocalWorker()) {
            return 0;
        }

        $created = 0;
        foreach ($this->recordings->findImportedWithStorage($limit * 3) as $recording) {
            if (null === $this->createPendingJobForRecording($recording)) {
                continue;
            }

            ++$created;
            if ($created >= $limit) {
                break;
            }
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        return $created;
    }

    /** @return array<string, mixed>|null */
    public function claimNextJob(string $workerId): ?array
    {
        if (!$this->shouldUseLocalWorker()) {
            return null;
        }

        return $this->connection->transactional(function () use ($workerId): ?array {
            $jobId = $this->connection->fetchOne(
                <<<'SQL'
                    SELECT id
                    FROM transcription_job
                    WHERE status IN ('pending', 'failed')
                      AND attempts < max_attempts
                      AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
                      AND (locked_until IS NULL OR locked_until < NOW())
                    ORDER BY priority DESC, created_at ASC
                    FOR UPDATE SKIP LOCKED
                    LIMIT 1
                SQL,
            );

            if (false === $jobId) {
                return null;
            }

            /** @var TranscriptionJob|null $job */
            $job = $this->jobs->find((int) $jobId);
            if (null === $job) {
                return null;
            }

            $now = new \DateTimeImmutable();
            $lockUntil = $now->modify(sprintf('+%d seconds', $this->lockSeconds));

            $job
                ->setStatus('claimed')
                ->setLockedBy($workerId)
                ->setLockedUntil($lockUntil)
                ->setClaimedAt($now)
                ->setFailedAt(null)
                ->setErrorMessage(null)
                ->setNextAttemptAt(null)
                ->incrementAttempts()
                ->touch($now);

            $this->entityManager->flush();

            return [
                'id' => $job->getId(),
                'recordingId' => $job->getCallRecording()->getId(),
                'callSessionId' => $job->getCallSession()?->getId(),
                'input' => [
                    's3Bucket' => $job->getInputS3Bucket(),
                    's3Key' => $job->getInputS3Key(),
                    'downloadUrl' => $this->recordingStorage->generatePresignedDownloadUrl($job->getCallRecording()),
                    'expiresIn' => $this->recordingStorage->getPresignedUrlTtlSeconds(),
                ],
                'channelMapping' => $job->getChannelMapping(),
                'recommendedMode' => 'single_stereo_transcription',
                'notes' => 'Do not split channels unless explicitly configured; keep cost and processing low.',
            ];
        });
    }

    public function markProcessing(TranscriptionJob $job, string $workerId): void
    {
        $this->assertWorkerOwnsLock($job, $workerId);

        $now = new \DateTimeImmutable();
        $job
            ->setStatus('processing')
            ->setStartedAt($job->getStartedAt() ?? $now)
            ->setLockedUntil($now->modify(sprintf('+%d seconds', $this->lockSeconds)))
            ->touch($now);

        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed>|null $transcriptJson
     * @param array<string, mixed>|null $channelMapping
     * @return array{transcriptId:int|null,summaryId:int|null}
     */
    public function completeJob(
        TranscriptionJob $job,
        string $workerId,
        string $provider,
        ?string $model,
        ?string $language,
        ?int $durationSeconds,
        ?string $transcriptText,
        ?array $transcriptJson,
        ?array $channelMapping,
    ): array {
        $this->assertWorkerOwnsLock($job, $workerId);

        $now = new \DateTimeImmutable();
        $job
            ->setStatus('completed')
            ->setTranscriptText($transcriptText)
            ->setTranscriptJson($transcriptJson)
            ->setChannelMapping($channelMapping ?? $job->getChannelMapping())
            ->setCompletedAt($now)
            ->setFailedAt(null)
            ->setErrorMessage(null)
            ->setLockedBy(null)
            ->setLockedUntil(null)
            ->setNextAttemptAt(null)
            ->touch($now);

        $transcript = (new CallTranscript($job->getCallRecording(), $model, 'available'))
            ->setCallSession($job->getCallSession())
            ->setTranscriptionJob($job)
            ->setProvider($provider)
            ->setModel($model)
            ->setTranscriptText($transcriptText)
            ->setRawResponse($transcriptJson)
            ->setChannelMapping($channelMapping ?? $job->getChannelMapping())
            ->setLanguage($language)
            ->setDurationSeconds($durationSeconds)
            ->setStartedAt($job->getStartedAt() ?? $job->getClaimedAt())
            ->setCompletedAt($now)
            ->touch($now);

        $summary = (new CallSummary($transcript))
            ->setStatus('pending')
            ->touch($now);

        $this->entityManager->persist($transcript);
        $this->entityManager->persist($summary);
        $this->entityManager->flush();

        return [
            'transcriptId' => $transcript->getId(),
            'summaryId' => $summary->getId(),
        ];
    }

    public function failJob(TranscriptionJob $job, string $workerId, string $errorMessage): void
    {
        $this->assertWorkerOwnsLock($job, $workerId);

        $now = new \DateTimeImmutable();
        $job
            ->setStatus('failed')
            ->setErrorMessage($errorMessage)
            ->setFailedAt($now)
            ->setLockedBy(null)
            ->setLockedUntil(null)
            ->touch($now);

        if ($job->getAttempts() < $job->getMaxAttempts()) {
            $delaySeconds = min(3600, 300 * (2 ** max(0, $job->getAttempts() - 1)));
            $job->setNextAttemptAt($now->modify(sprintf('+%d seconds', $delaySeconds)));
        } else {
            $job->setNextAttemptAt(null);
        }

        $this->entityManager->flush();
    }

    private function assertWorkerOwnsLock(TranscriptionJob $job, string $workerId): void
    {
        if ($job->getLockedBy() !== $workerId) {
            throw new \RuntimeException('Worker does not own this job lock.');
        }
    }
}
