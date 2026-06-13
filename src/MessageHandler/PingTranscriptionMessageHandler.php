<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PingTranscriptionMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PingTranscriptionMessageHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PingTranscriptionMessage $message): void
    {
        $this->logger->info('Transcription messenger ping consumed.', [
            'ping_id' => $message->pingId,
            'dispatched_at' => $message->dispatchedAt->format(DATE_ATOM),
            'consumed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }
}
