<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallRecording;
use App\Entity\TranscriptionJob;
use App\Repository\CallRecordingRepository;
use App\Repository\CallTranscriptRepository;
use App\Repository\TranscriptionJobRepository;
use App\Transcription\Provider\TelnyxTranscriptionConfiguration;
use App\Transcription\SttProviderRegistry;
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
        private readonly SttProviderRegistry $providers,
        private readonly TelnyxTranscriptionConfiguration $telnyxConfiguration,
        private readonly TranscriptionResultService $results,
        private readonly bool $localWorkerEnabled,
        private readonly int $lockSeconds,
    ) {
    }

    public function getConfiguredProviderName(): string
    {
        return $this->providers->getDefaultProviderName();
    }

    public function shouldUseLocalWorker(): bool
    {
        return $this->localWorkerEnabled && 'local_worker' === $this->getConfiguredProviderName();
    }

    public function createPendingJobForRecording(CallRecording $recording): ?TranscriptionJob
    {
        if (
            'imported' !== $recording->getStatus()
            || null === $recording->getS3Bucket()
            || null === $recording->getS3Key()
            || $this->transcripts->hasTranscriptForRecording($recording)
            || $this->jobs->hasCurrentJobForRecording($recording)
        ) {
            return null;
        }

        $provider = $this->getConfiguredProviderName();
        if ('telnyx' === $provider) {
            return null;
        }

        $job = (new TranscriptionJob($recording))
            ->setCallSession($recording->getCallSession())
            ->setProvider($provider)
            ->setStatus('pending')
            ->setInputS3Bucket($recording->getS3Bucket())
            ->setInputS3Key($recording->getS3Key())
            ->setChannelMapping($recording->getChannelMapping())
            ->touch();

        if ('local_worker' === $provider) {
            $job->setNextAttemptAt(new \DateTimeImmutable());
        }
        $this->entityManager->persist($job);

        return $job;
    }

    public function createPendingJobsForImportedRecordings(int $limit): int
    {
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

    public function submitPendingJobs(int $limit): array
    {
        $providerName = $this->getConfiguredProviderName();
        $provider = $this->providers->get($providerName);
        $submitted = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($this->jobs->findPendingForProvider($providerName, $limit) as $job) {
            try {
                $provider->submit($job);
                if ('local_worker' === $providerName) {
                    ++$skipped;
                } else {
                    ++$submitted;
                }
            } catch (\RuntimeException $exception) {
                $this->markSubmissionFailed($job, $exception->getMessage());
                ++$failed;
            }
        }

        $this->entityManager->flush();

        return [
            'provider' => $providerName,
            'submitted' => $submitted,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    /** @return array<string, mixed>|null */
    public function claimNextJob(string $workerId): ?array
    {
        return $this->connection->transactional(function () use ($workerId): ?array {
            $jobId = $this->connection->fetchOne(
                <<<'SQL'
                    SELECT id
                    FROM transcription_job
                    WHERE provider = 'local_worker'
                      AND status IN ('pending', 'failed')
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
            $job
                ->setStatus('submitted')
                ->setLockedBy($workerId)
                ->setLockedUntil($now->modify(sprintf('+%d seconds', $this->lockSeconds)))
                ->setClaimedAt($now)
                ->setSubmittedAt($job->getSubmittedAt() ?? $now)
                ->setProviderStatus('claimed_by_worker')
                ->setFailedAt(null)
                ->setErrorMessage(null)
                ->setNextAttemptAt(null)
                ->incrementAttempts()
                ->touch($now);

            $this->entityManager->flush();

            return [
                'id' => $job->getId(),
                'recordingId' => $job->getCallRecording()?->getId(),
                'callSessionId' => $job->getCallSession()?->getId(),
                'input' => [
                    's3Bucket' => $job->getInputS3Bucket(),
                    's3Key' => $job->getInputS3Key(),
                    'downloadUrl' => null !== $job->getCallRecording()
                        ? $this->recordingStorage->generatePresignedDownloadUrl($job->getCallRecording())
                        : null,
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
        $this->assertLocalWorkerOwnsLock($job, $workerId);

        $now = new \DateTimeImmutable();
        $job
            ->setStatus('processing')
            ->setProviderStatus('processing')
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
        $this->assertLocalWorkerOwnsLock($job, $workerId);

        return $this->results->completeProviderJob(
            $job,
            $provider,
            $model,
            $transcriptJson,
            $transcriptText,
            $channelMapping,
            $language,
            $durationSeconds,
        );
    }

    public function failJob(TranscriptionJob $job, string $workerId, string $errorMessage): void
    {
        $this->assertLocalWorkerOwnsLock($job, $workerId);

        $now = new \DateTimeImmutable();
        $job
            ->setStatus('failed')
            ->setProviderStatus('failed')
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

    private function markSubmissionFailed(TranscriptionJob $job, string $errorMessage): void
    {
        $job
            ->setStatus('failed')
            ->setProviderStatus('submission_failed')
            ->setErrorMessage($errorMessage)
            ->setFailedAt(new \DateTimeImmutable())
            ->touch();
    }

    private function assertLocalWorkerOwnsLock(TranscriptionJob $job, string $workerId): void
    {
        if ('local_worker' !== $job->getProvider()) {
            throw new \RuntimeException('This transcription job is not assigned to the local worker provider.');
        }

        if ($job->getLockedBy() !== $workerId) {
            throw new \RuntimeException('Worker does not own this job lock.');
        }
    }
}
