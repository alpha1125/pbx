<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Exception\NoCurrentTenantException;
use App\Repository\TenantRepository;
use App\Repository\UserTenantMembershipRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Bundle\SecurityBundle\Security;

class CurrentTenantProvider implements CurrentTenantProviderInterface
{
    private const SESSION_KEY = 'crm.current_tenant_id';

    public function __construct(
        private readonly Security $security,
        private readonly UserTenantMembershipRepository $membershipRepository,
        private readonly TenantRepository $tenantRepository,
        private readonly RequestStack $requestStack,
        private readonly string $environment,
        private readonly ?string $defaultTenantId = null,
        private readonly ?string $defaultTenantName = null,
    ) {
    }

    public function getCurrentTenant(): ?Tenant
    {
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $sessionTenantId = $this->selectedTenantId();
            if (null !== $sessionTenantId) {
                $membership = $this->membershipRepository->findOneByUserAndTenantId($user, $sessionTenantId);
                if (null !== $membership) {
                    return $membership->getTenant();
                }
                $this->clearSelectedTenant();
            }

            $membership = $this->membershipRepository->findDefaultForUser($user);
            if (null !== $membership) {
                return $membership->getTenant();
            }

            $memberships = $this->membershipRepository->findActiveByUserOrdered($user);
            if ([] !== $memberships) {
                return $memberships[0]->getTenant();
            }
        }

        if ('dev' !== $this->environment) {
            return null;
        }

        $tenantId = null;
        if (null !== $this->defaultTenantId && ctype_digit($this->defaultTenantId)) {
            $tenantId = (int) $this->defaultTenantId;
        }

        return $this->tenantRepository->findDefaultTenant($tenantId, $this->defaultTenantName);
    }

    public function requireCurrentTenant(): Tenant
    {
        return $this->getCurrentTenant() ?? throw new NoCurrentTenantException('No tenant could be resolved for the current request.');
    }

    /** @return list<Tenant> */
    public function getAvailableTenants(): array
    {
        $user = $this->security->getUser();
        if ($user instanceof User) {
            return array_map(
                static fn ($membership): Tenant => $membership->getTenant(),
                $this->membershipRepository->findActiveByUserOrdered($user),
            );
        }

        $tenant = $this->getCurrentTenant();

        return null !== $tenant ? [$tenant] : [];
    }

    public function selectTenant(User $user, int $tenantId): bool
    {
        $membership = $this->membershipRepository->findOneByUserAndTenantId($user, $tenantId);
        if (null === $membership) {
            return false;
        }

        $session = $this->requestStack->getSession();
        if (null === $session) {
            return false;
        }

        $session->set(self::SESSION_KEY, $tenantId);

        return true;
    }

    private function selectedTenantId(): ?int
    {
        $session = $this->requestStack->getSession();
        if (null === $session) {
            return null;
        }

        $tenantId = $session->get(self::SESSION_KEY);

        return is_int($tenantId) || (is_string($tenantId) && ctype_digit($tenantId)) ? (int) $tenantId : null;
    }

    private function clearSelectedTenant(): void
    {
        $session = $this->requestStack->getSession();
        if (null === $session) {
            return;
        }

        $session->remove(self::SESSION_KEY);
    }
}
