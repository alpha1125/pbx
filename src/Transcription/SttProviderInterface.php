<?php

declare(strict_types=1);

namespace App\Transcription;

use App\Entity\TranscriptionJob;

interface SttProviderInterface
{
    public function getName(): string;

    public function submit(TranscriptionJob $job): void;
}
