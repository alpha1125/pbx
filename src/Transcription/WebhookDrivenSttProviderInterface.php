<?php

declare(strict_types=1);

namespace App\Transcription;

use App\Entity\TelnyxEvent;

interface WebhookDrivenSttProviderInterface
{
    /** @param array<string, mixed> $payload */
    public function handleWebhook(array $payload, TelnyxEvent $event): void;
}
