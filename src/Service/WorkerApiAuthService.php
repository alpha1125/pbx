<?php

declare(strict_types=1);

namespace App\Service;

final class WorkerApiAuthService
{
    public function __construct(
        private readonly string $sharedSecret,
    ) {
    }

    public function isAuthorized(?string $providedSecret): bool
    {
        if ('' === $this->sharedSecret || null === $providedSecret) {
            return false;
        }

        return hash_equals($this->sharedSecret, $providedSecret);
    }
}
