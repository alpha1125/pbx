<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;

interface CurrentTenantProviderInterface
{
    public function getCurrentTenant(): ?Tenant;

    public function requireCurrentTenant(): Tenant;
}
