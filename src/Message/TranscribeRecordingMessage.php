<?php

declare(strict_types=1);

namespace App\Message;

final class TranscribeRecordingMessage
{
    public function __construct(
        public readonly int $callRecordingId,
    ) {
    }
}
