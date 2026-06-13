<?php

declare(strict_types=1);

namespace App\Service;

final class ClientStateService
{
    /**
     * @param array<string, mixed> $state
     */
    public function encode(array $state): string
    {
        return base64_encode((string) json_encode($state, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decode(?string $clientState): ?array
    {
        if (null === $clientState || '' === trim($clientState)) {
            return null;
        }

        $decoded = base64_decode($clientState, true);
        if (false === $decoded) {
            return null;
        }

        try {
            $state = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($state) ? $state : null;
    }
}
