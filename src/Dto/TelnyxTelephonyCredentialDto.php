<?php

declare(strict_types=1);

namespace App\Dto;

final class TelnyxTelephonyCredentialDto
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $resourceId,
        public readonly ?string $sipUsername = null,
    ) {
    }
}
