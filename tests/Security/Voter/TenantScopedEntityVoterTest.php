<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\CallRecording;
use App\Entity\CallSession;
use App\Entity\Property;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\UserTenantMembershipRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\CurrentTenantProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class TenantScopedEntityVoterTest extends TestCase
{
    public function testAllowsAccessWhenEntityBelongsToCurrentTenant(): void
    {
        $tenant = $this->tenant('Tenant A', 1);
        $property = new Property($tenant, '1 Main St', 'Toronto', 'ON', 'M1M1M1');

        $provider = $this->createMock(CurrentTenantProviderInterface::class);
        $provider->method('getCurrentTenant')->willReturn($tenant);

        $membershipRepository = $this->createMock(UserTenantMembershipRepository::class);
        $membershipRepository->method('findOneByUserAndTenantId')->willReturn(
            (new UserTenantMembership($this->user(), $tenant))->setRoles([UserTenantMembership::ROLE_SALES]),
        );

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with('ROLE_USER')->willReturn(true);

        $token = $this->token();

        $voter = new TenantScopedEntityVoter($provider, $membershipRepository, $security);

        self::assertSame(1, $voter->vote($token, $property, [TenantScopedEntityVoter::VIEW]));
    }

    public function testDeniesAccessWhenEntityTenantDoesNotMatchCurrentTenant(): void
    {
        $tenantA = $this->tenant('Tenant A', 1);
        $tenantB = $this->tenant('Tenant B', 2);
        $property = new Property($tenantB, '1 Main St', 'Toronto', 'ON', 'M1M1M1');

        $provider = $this->createMock(CurrentTenantProviderInterface::class);
        $provider->method('getCurrentTenant')->willReturn($tenantA);

        $membershipRepository = $this->createMock(UserTenantMembershipRepository::class);
        $membershipRepository->method('findOneByUserAndTenantId')->willReturn(
            (new UserTenantMembership($this->user(), $tenantA))->setRoles([UserTenantMembership::ROLE_SALES]),
        );

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with('ROLE_USER')->willReturn(true);

        $voter = new TenantScopedEntityVoter($provider, $membershipRepository, $security);

        self::assertSame(-1, $voter->vote($this->token(), $property, [TenantScopedEntityVoter::VIEW]));
    }

    public function testRecordingAccessResolvesTenantThroughCallSession(): void
    {
        $tenant = $this->tenant('Tenant A', 1);
        $session = (new CallSession('provider-session'))->setTenant($tenant);
        $recording = new CallRecording($session, 'imported');

        $provider = $this->createMock(CurrentTenantProviderInterface::class);
        $provider->method('getCurrentTenant')->willReturn($tenant);

        $membershipRepository = $this->createMock(UserTenantMembershipRepository::class);
        $membershipRepository->method('findOneByUserAndTenantId')->willReturn(
            (new UserTenantMembership($this->user(), $tenant))->setRoles([UserTenantMembership::ROLE_SALES]),
        );

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with('ROLE_USER')->willReturn(true);

        $voter = new TenantScopedEntityVoter($provider, $membershipRepository, $security);

        self::assertSame(1, $voter->vote($this->token(), $recording, [TenantScopedEntityVoter::VIEW]));
    }

    public function testEditAccessRequiresAllowedMembershipRole(): void
    {
        $tenant = $this->tenant('Tenant A', 1);
        $property = new Property($tenant, '1 Main St', 'Toronto', 'ON', 'M1M1M1');

        $provider = $this->createMock(CurrentTenantProviderInterface::class);
        $provider->method('getCurrentTenant')->willReturn($tenant);

        $membershipRepository = $this->createMock(UserTenantMembershipRepository::class);
        $membershipRepository->method('findOneByUserAndTenantId')->willReturn(
            (new UserTenantMembership($this->user(), $tenant))->setRoles([UserTenantMembership::ROLE_ACCOUNTING]),
        );

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with('ROLE_USER')->willReturn(true);

        $voter = new TenantScopedEntityVoter($provider, $membershipRepository, $security);

        self::assertSame(-1, $voter->vote($this->token(), $property, [TenantScopedEntityVoter::EDIT]));
    }

    public function testEditAccessAllowsConfiguredRole(): void
    {
        $tenant = $this->tenant('Tenant A', 1);
        $property = new Property($tenant, '1 Main St', 'Toronto', 'ON', 'M1M1M1');

        $provider = $this->createMock(CurrentTenantProviderInterface::class);
        $provider->method('getCurrentTenant')->willReturn($tenant);

        $membershipRepository = $this->createMock(UserTenantMembershipRepository::class);
        $membershipRepository->method('findOneByUserAndTenantId')->willReturn(
            (new UserTenantMembership($this->user(), $tenant))->setRoles([UserTenantMembership::ROLE_DISPATCH]),
        );

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with('ROLE_USER')->willReturn(true);

        $voter = new TenantScopedEntityVoter($provider, $membershipRepository, $security);

        self::assertSame(1, $voter->vote($this->token(), $property, [TenantScopedEntityVoter::EDIT]));
    }

    private function token(): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->user());

        return $token;
    }

    private function user(): User
    {
        return (new User())->setEmail('tester@example.com');
    }

    private function tenant(string $name, int $id): Tenant
    {
        $tenant = new Tenant($name);
        $this->setEntityId($tenant, $id);

        return $tenant;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionObject($entity);
        $property = $reflection->getProperty('id');
        $property->setValue($entity, $id);
    }
}
