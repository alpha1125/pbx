<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ClickToCallRequest;

final class TelnyxRecordingStartService
{
    public function __construct(
        private readonly TelnyxCaptureService $capture,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function startForBridgedInboundLeg(array $payload): void
    {
        $this->capture->startForBridgedInboundLeg($payload);
    }

    /** @param array<string, mixed> $payload */
    public function startWhenBothLegsAnswered(array $payload): void
    {
        $this->capture->startWhenBothLegsAnswered($payload);
    }

    public function startForClickToCallRequest(ClickToCallRequest $request): void
    {
        // Maintained only as a compatibility shim while call flows migrate to TelnyxCaptureService.
    }
}
