<?php

declare(strict_types=1);

namespace App\Message;

final class PingTranscriptionMessage
{
    public function __construct(
        public readonly string $pingId,
        public readonly \DateTimeImmutable $dispatchedAt = new \DateTimeImmutable(),
    ) {
    }
}
