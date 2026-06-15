<?php

declare(strict_types=1);

namespace App\Capture;

final readonly class CapturePolicy
{
    public function __construct(
        public bool $recordAudio,
        public bool $transcribeAudio,
        public ?string $source = null,
    ) {
    }

    public function shouldCaptureAnything(): bool
    {
        return $this->recordAudio || $this->transcribeAudio;
    }

    /**
     * @return array{recordAudio:bool,transcribeAudio:bool,source:?string}
     */
    public function toArray(): array
    {
        return [
            'recordAudio' => $this->recordAudio,
            'transcribeAudio' => $this->transcribeAudio,
            'source' => $this->source,
        ];
    }
}
