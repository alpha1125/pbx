<?php

declare(strict_types=1);

namespace App\Service;

use App\Capture\CapturePolicy;

final class CapturePolicyResolver
{
    public function __construct(
        private readonly bool $recordingEnabled,
        private readonly bool $transcriptionEnabled,
    ) {
    }

    public function defaultForContext(?string $source = null): CapturePolicy
    {
        return new CapturePolicy(
            $this->recordingEnabled,
            $this->transcriptionEnabled,
            $source,
        );
    }

    public function resolve(?bool $recordAudio, ?bool $transcribeAudio, ?string $source = null): CapturePolicy
    {
        return new CapturePolicy(
            $recordAudio ?? $this->recordingEnabled,
            $transcribeAudio ?? $this->transcriptionEnabled,
            $source,
        );
    }

    /**
     * @param array<string, mixed>|null $state
     */
    public function fromClientState(?array $state, ?string $fallbackSource = null): CapturePolicy
    {
        $source = isset($state['flow']) && is_string($state['flow']) && '' !== trim($state['flow'])
            ? $state['flow']
            : $fallbackSource;

        return $this->resolve(
            $this->boolValue($state, 'recordAudio'),
            $this->boolValue($state, 'transcribeAudio'),
            $source,
        );
    }

    /**
     * @return array{recordAudio:bool,transcribeAudio:bool}
     */
    public function getDefaultConfig(): array
    {
        return [
            'recordAudio' => $this->recordingEnabled,
            'transcribeAudio' => $this->transcriptionEnabled,
        ];
    }

    /**
     * @param array<string, mixed>|null $state
     */
    private function boolValue(?array $state, string $key): ?bool
    {
        if (!is_array($state) || !array_key_exists($key, $state)) {
            return null;
        }

        $value = $state[$key];

        return is_bool($value) ? $value : null;
    }
}
