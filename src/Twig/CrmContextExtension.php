<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\CurrentTenantProviderInterface;
use App\Service\TenantMembershipAccessService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CrmContextExtension extends AbstractExtension
{
    public function __construct(
        private readonly CurrentTenantProviderInterface $tenantProvider,
        private readonly TenantMembershipAccessService $membershipAccess,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('crm_current_tenant', fn () => $this->tenantProvider->getCurrentTenant()),
            new TwigFunction('crm_available_tenants', fn (): array => $this->tenantProvider->getAvailableTenants()),
            new TwigFunction('crm_current_membership', fn () => $this->membershipAccess->getCurrentMembership()),
            new TwigFunction('crm_has_any_role', fn (string ...$roles): bool => $this->membershipAccess->hasAnyRole(...$roles)),
        ];
    }
}
