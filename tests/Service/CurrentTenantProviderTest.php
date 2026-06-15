<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\TenantRepository;
use App\Repository\UserTenantMembershipRepository;
use App\Service\CurrentTenantProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CurrentTenantProviderTest extends TestCase
{
    public function testSelectedTenantOverridesDefaultMembership(): void
    {
        $user = new User();
        $user->setEmail('tester@example.com');

        $tenantA = new Tenant('Tenant A');
        $tenantB = new Tenant('Tenant B');

        $membershipA = (new UserTenantMembership($user, $tenantA))->setIsDefault(true);
        $membershipB = new UserTenantMembership($user, $tenantB);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $memberships = $this->createMock(UserTenantMembershipRepository::class);
        $memberships->method('findOneByUserAndTenantId')->with($user, 2)->willReturn($membershipB);
        $memberships->method('findActiveByUserOrdered')->with($user)->willReturn([$membershipA, $membershipB]);
        $memberships->expects(self::never())->method('findDefaultForUser');

        $tenants = $this->createMock(TenantRepository::class);

        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $session->set('crm.current_tenant_id', 2);
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $provider = new CurrentTenantProvider($security, $memberships, $tenants, $requestStack, 'prod');

        self::assertSame($tenantB, $provider->getCurrentTenant());
        self::assertSame([$tenantA, $tenantB], $provider->getAvailableTenants());
    }

    public function testProdDoesNotFallbackToDefaultTenantWhenAnonymous(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $memberships = $this->createMock(UserTenantMembershipRepository::class);
        $tenants = $this->createMock(TenantRepository::class);
        $tenants->expects(self::never())->method('findDefaultTenant');

        $provider = new CurrentTenantProvider($security, $memberships, $tenants, new RequestStack(), 'prod');

        self::assertNull($provider->getCurrentTenant());
    }

    public function testDevFallsBackToConfiguredTenant(): void
    {
        $tenant = new Tenant('Demo Tenant');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $memberships = $this->createMock(UserTenantMembershipRepository::class);
        $tenants = $this->createMock(TenantRepository::class);
        $tenants->expects(self::once())
            ->method('findDefaultTenant')
            ->with(null, 'Demo Tenant')
            ->willReturn($tenant);

        $provider = new CurrentTenantProvider($security, $memberships, $tenants, new RequestStack(), 'dev', null, 'Demo Tenant');

        self::assertSame($tenant, $provider->getCurrentTenant());
    }
}
