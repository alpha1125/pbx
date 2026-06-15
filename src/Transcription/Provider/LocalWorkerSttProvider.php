<?php

declare(strict_types=1);

namespace App\Transcription\Provider;

use App\Entity\TranscriptionJob;
use App\Transcription\SttProviderInterface;
use Psr\Log\LoggerInterface;

final class LocalWorkerSttProvider implements SttProviderInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'local_worker';
    }

    public function submit(TranscriptionJob $job): void
    {
        $this->logger->info('Local worker transcription job remains claimable through the worker API.', [
            'job_id' => $job->getId(),
            'recording_id' => $job->getCallRecording()?->getId(),
        ]);
    }
}
