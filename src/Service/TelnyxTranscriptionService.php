<?php

declare(strict_types=1);

namespace App\Service;

use App\Capture\CapturePolicy;

final class TelnyxTranscriptionService
{
    public function disclosureMessage(CapturePolicy $policy): string
    {
        return match (true) {
            $policy->recordAudio && $policy->transcribeAudio => 'Thank you for calling FirstFire, this call will be recorded for transcription and quality purposes.',
            !$policy->recordAudio && $policy->transcribeAudio => 'Thank you for calling FirstFire, this call will be transcribed for quality purposes.',
            $policy->recordAudio && !$policy->transcribeAudio => 'Thank you for calling FirstFire, this call will be recorded for quality purposes.',
            default => 'Thank you for calling FirstFire.',
        };
    }

    public function forwardingDisclosureMessage(CapturePolicy $policy): string
    {
        return $this->disclosureMessage($policy).' Please hold while I connect you.';
    }
}
