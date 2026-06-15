<?php

declare(strict_types=1);

namespace App\Transcription\Provider;

use App\Entity\TranscriptionJob;
use App\Transcription\SttProviderInterface;

final class AwsTranscribeSttProvider implements SttProviderInterface
{
    public function getName(): string
    {
        return 'aws_transcribe';
    }

    public function submit(TranscriptionJob $job): void
    {
        throw new \RuntimeException(sprintf(
            'STT provider not implemented yet: %s.',
            $job->getProvider(),
        ));
    }
}
