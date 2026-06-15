<?php

declare(strict_types=1);

namespace App\Transcription;

final class SttProviderRegistry
{
    /** @var array<string, SttProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<SttProviderInterface> $providers
     */
    public function __construct(
        iterable $providers,
        private readonly string $defaultProviderName,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->getName()] = $provider;
        }
    }

    public function get(string $name): SttProviderInterface
    {
        if (!isset($this->providers[$name])) {
            throw new \RuntimeException(sprintf('Unknown STT provider "%s".', $name));
        }

        return $this->providers[$name];
    }

    public function getDefaultProviderName(): string
    {
        return $this->defaultProviderName;
    }

    /** @return list<string> */
    public function getRegisteredProviderNames(): array
    {
        $names = array_keys($this->providers);
        sort($names);

        return array_values($names);
    }
}
