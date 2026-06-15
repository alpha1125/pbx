<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use App\Repository\UserTenantMembershipRepository;
use Symfony\Bundle\SecurityBundle\Security;

class CurrentTenantProvider implements CurrentTenantProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UserTenantMembershipRepository $membershipRepository,
        private readonly TenantRepository $tenantRepository,
        private readonly ?string $defaultTenantId = null,
        private readonly ?string $defaultTenantName = null,
    ) {
    }

    public function getCurrentTenant(): ?Tenant
    {
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $membership = $this->membershipRepository->findDefaultForUser($user);
            if (null !== $membership) {
                return $membership->getTenant();
            }
        }

        $tenantId = null;
        if (null !== $this->defaultTenantId && ctype_digit($this->defaultTenantId)) {
            $tenantId = (int) $this->defaultTenantId;
        }

        return $this->tenantRepository->findDefaultTenant($tenantId, $this->defaultTenantName);
    }

    public function requireCurrentTenant(): Tenant
    {
        return $this->getCurrentTenant() ?? throw new \RuntimeException('No tenant could be resolved for the current request.');
    }
}
