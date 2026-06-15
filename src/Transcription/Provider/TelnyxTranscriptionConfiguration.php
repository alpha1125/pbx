<?php

declare(strict_types=1);

namespace App\Transcription\Provider;

final class TelnyxTranscriptionConfiguration
{
    public function __construct(
        private readonly bool $enabled,
        private readonly ?string $model,
        private readonly string $language,
        private readonly string $track,
        private readonly string $engine,
        private readonly bool $profanityFilter,
        private readonly bool $speakerDiarization,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getTrack(): string
    {
        return $this->track;
    }

    public function getEngine(): string
    {
        return $this->engine;
    }

    public function isProfanityFilterEnabled(): bool
    {
        return $this->profanityFilter;
    }

    public function isSpeakerDiarizationEnabled(): bool
    {
        return $this->speakerDiarization;
    }

    /** @return array<string, mixed> */
    public function toProviderConfig(): array
    {
        return [
            'model' => $this->model,
            'language' => $this->language,
            'track' => $this->track,
            'engine' => $this->engine,
            'profanityFilter' => $this->profanityFilter,
            'speakerDiarization' => $this->speakerDiarization,
        ];
    }

    /** @return array<string, mixed> */
    public function toTranscriptionStartPayload(): array
    {
        $payload = [
            'transcription_engine' => $this->normalizedEngine(),
            'transcription_tracks' => $this->track,
        ];

        $engineConfig = array_filter([
            'language' => $this->language,
            'model' => $this->model,
            'profanity_filter' => $this->profanityFilter,
            'enable_speaker_diarization' => $this->speakerDiarization,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);

        if ([] !== $engineConfig) {
            $payload['transcription_engine_config'] = $engineConfig;
        }

        return $payload;
    }

    private function normalizedEngine(): string
    {
        return match (strtolower($this->engine)) {
            'google' => 'Google',
            'deepgram' => 'Deepgram',
            'azure' => 'Azure',
            'xai' => 'xAI',
            'assemblyai' => 'AssemblyAI',
            'speechmatics' => 'Speechmatics',
            'soniox' => 'Soniox',
            'a' => 'A',
            'b', 'telnyx' => 'Telnyx',
            default => $this->engine,
        };
    }
}
