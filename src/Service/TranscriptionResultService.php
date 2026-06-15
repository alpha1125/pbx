<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallSummary;
use App\Entity\CallTranscript;
use App\Entity\TranscriptionJob;
use App\Repository\CallSummaryRepository;
use App\Repository\CallTranscriptRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TranscriptionResultService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CallTranscriptRepository $transcripts,
        private readonly CallSummaryRepository $summaries,
    ) {
    }

    /**
     * @param array<string, mixed>|null $rawResponse
     * @param array<string, mixed>|null $channelMapping
     * @return array{transcriptId:int|null,summaryId:int|null}
     */
    public function completeProviderJob(
        TranscriptionJob $job,
        string $provider,
        ?string $model,
        ?array $rawResponse,
        ?string $transcriptText,
        ?array $channelMapping,
        ?string $language,
        ?int $durationSeconds,
    ): array {
        $now = new \DateTimeImmutable();
        $effectiveModel = $model ?? $job->getProviderModel();

        $job
            ->setStatus('completed')
            ->setProvider($provider)
            ->setProviderStatus('completed')
            ->setProviderModel($effectiveModel)
            ->setTranscriptText($transcriptText)
            ->setTranscriptJson($rawResponse)
            ->setRawProviderResponse($rawResponse)
            ->setChannelMapping($channelMapping ?? $job->getChannelMapping())
            ->setCompletedAt($now)
            ->setFailedAt(null)
            ->setErrorMessage(null)
            ->setLockedBy(null)
            ->setLockedUntil(null)
            ->setNextAttemptAt(null)
            ->touch($now);

        $transcript = $this->transcripts->findOneByTranscriptionJob($job)
            ?? new CallTranscript($job->getCallRecording(), $effectiveModel, 'available');

        $transcript
            ->setCallRecording($job->getCallRecording())
            ->setCallSession($job->getCallSession())
            ->setCallLeg($job->getCallLeg())
            ->setTranscriptionJob($job)
            ->setProvider($provider)
            ->setModel($effectiveModel)
            ->setStatus('available')
            ->setTranscriptText($transcriptText)
            ->setRawResponse($rawResponse)
            ->setChannelMapping($channelMapping ?? $job->getChannelMapping())
            ->setLanguage($language)
            ->setDurationSeconds($durationSeconds)
            ->setStartedAt($job->getStartedAt() ?? $job->getSubmittedAt() ?? $job->getClaimedAt())
            ->setCompletedAt($now)
            ->setFailedAt(null)
            ->setErrorMessage(null)
            ->touch($now);

        if (null === $transcript->getId()) {
            $this->entityManager->persist($transcript);
        }

        $summary = $this->summaries->findOneByTranscript($transcript);
        if (null === $summary) {
            $summary = (new CallSummary($transcript))
                ->setStatus('pending')
                ->touch($now);
            $this->entityManager->persist($summary);
        } elseif ('available' !== $summary->getStatus()) {
            $summary->setStatus('pending')->setErrorMessage(null)->touch($now);
        }

        $this->entityManager->flush();

        return [
            'transcriptId' => $transcript->getId(),
            'summaryId' => $summary->getId(),
        ];
    }

    /** @param array<string, mixed>|null $rawResponse */
    public function failProviderJob(
        TranscriptionJob $job,
        string $errorMessage,
        ?string $providerStatus = null,
        ?array $rawResponse = null,
    ): void {
        $now = new \DateTimeImmutable();
        $job
            ->setStatus('failed')
            ->setProviderStatus($providerStatus ?? 'failed')
            ->setErrorMessage($errorMessage)
            ->setRawProviderResponse($rawResponse ?? $job->getRawProviderResponse())
            ->setFailedAt($now)
            ->setLockedBy(null)
            ->setLockedUntil(null)
            ->setNextAttemptAt(null)
            ->touch($now);

        $transcript = $this->transcripts->findOneByTranscriptionJob($job);
        if (null !== $transcript) {
            $transcript
                ->setProvider($job->getProvider())
                ->setModel($job->getProviderModel())
                ->setStatus('failed')
                ->setErrorMessage($errorMessage)
                ->setFailedAt($now)
                ->setRawResponse($rawResponse)
                ->touch($now);
        }

        $this->entityManager->flush();
    }
}
