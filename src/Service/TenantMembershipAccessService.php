<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\UserTenantMembershipRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class TenantMembershipAccessService
{
    public function __construct(
        private readonly Security $security,
        private readonly CurrentTenantProviderInterface $tenantProvider,
        private readonly UserTenantMembershipRepository $membershipRepository,
    ) {
    }

    public function getCurrentMembership(): ?UserTenantMembership
    {
        $user = $this->security->getUser();
        $tenant = $this->tenantProvider->getCurrentTenant();

        if (!$user instanceof User || !$tenant instanceof Tenant || null === $tenant->getId()) {
            return null;
        }

        return $this->membershipRepository->findOneByUserAndTenantId($user, $tenant->getId());
    }

    public function requireRole(string $role): UserTenantMembership
    {
        return $this->requireAnyRole([$role]);
    }

    /**
     * @param list<string> $roles
     */
    public function requireAnyRole(array $roles): UserTenantMembership
    {
        $membership = $this->getCurrentMembership();
        if (null === $membership) {
            throw new AccessDeniedException('Current tenant membership could not be resolved.');
        }

        foreach ($roles as $role) {
            if ($membership->hasRole($role)) {
                return $membership;
            }
        }

        throw new AccessDeniedException(sprintf('Current tenant membership is missing one of the required roles: %s.', implode(', ', $roles)));
    }

    public function hasAnyRole(string ...$roles): bool
    {
        $membership = $this->getCurrentMembership();
        if (null === $membership) {
            return false;
        }

        foreach ($roles as $role) {
            if ($membership->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
