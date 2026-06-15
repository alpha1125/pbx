<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;

interface CurrentTenantProviderInterface
{
    public function getCurrentTenant(): ?Tenant;

    public function requireCurrentTenant(): Tenant;

    /** @return list<Tenant> */
    public function getAvailableTenants(): array;

    public function selectTenant(User $user, int $tenantId): bool;
}
