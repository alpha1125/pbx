<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<UserTenantMembership> */
class UserTenantMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserTenantMembership::class);
    }

    public function findDefaultForUser(User $user): ?UserTenantMembership
    {
        return $this->findOneBy(['user' => $user, 'isDefault' => true, 'status' => UserTenantMembership::STATUS_ACTIVE])
            ?? $this->createQueryBuilder('membership')
                ->andWhere('membership.user = :user')
                ->andWhere('membership.status = :status')
                ->setParameter('user', $user)
                ->setParameter('status', UserTenantMembership::STATUS_ACTIVE)
                ->orderBy('membership.id', 'ASC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
    }

    /** @return list<UserTenantMembership> */
    public function findByUserOrdered(User $user): array
    {
        return $this->createQueryBuilder('membership')
            ->leftJoin('membership.tenant', 'tenant')->addSelect('tenant')
            ->andWhere('membership.user = :user')
            ->setParameter('user', $user)
            ->orderBy('membership.isDefault', 'DESC')
            ->addOrderBy('tenant.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<UserTenantMembership> */
    public function findActiveByUserOrdered(User $user): array
    {
        return $this->createQueryBuilder('membership')
            ->leftJoin('membership.tenant', 'tenant')->addSelect('tenant')
            ->andWhere('membership.user = :user')
            ->andWhere('membership.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', UserTenantMembership::STATUS_ACTIVE)
            ->orderBy('membership.isDefault', 'DESC')
            ->addOrderBy('tenant.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByUserAndTenantId(User $user, int $tenantId): ?UserTenantMembership
    {
        return $this->createQueryBuilder('membership')
            ->andWhere('membership.user = :user')
            ->andWhere('membership.tenant = :tenantId')
            ->andWhere('membership.status = :status')
            ->setParameter('user', $user)
            ->setParameter('tenantId', $tenantId)
            ->setParameter('status', UserTenantMembership::STATUS_ACTIVE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAnyByUserAndTenantId(User $user, int $tenantId): ?UserTenantMembership
    {
        return $this->createQueryBuilder('membership')
            ->andWhere('membership.user = :user')
            ->andWhere('membership.tenant = :tenantId')
            ->setParameter('user', $user)
            ->setParameter('tenantId', $tenantId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<UserTenantMembership> */
    public function findByTenantOrdered(Tenant $tenant, int $page = 1, int $pageSize = 20): array
    {
        return $this->createQueryBuilder('membership')
            ->leftJoin('membership.user', 'user')->addSelect('user')
            ->andWhere('membership.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('membership.status', 'ASC')
            ->addOrderBy('user.isActive', 'DESC')
            ->addOrderBy('user.email', 'ASC')
            ->setFirstResult(max(0, ($page - 1) * $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();
    }

    public function countByTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('membership')
            ->select('COUNT(membership.id)')
            ->andWhere('membership.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findPendingByInviteToken(string $inviteToken): ?UserTenantMembership
    {
        return $this->createQueryBuilder('membership')
            ->leftJoin('membership.user', 'user')->addSelect('user')
            ->leftJoin('membership.tenant', 'tenant')->addSelect('tenant')
            ->andWhere('membership.inviteToken = :inviteToken')
            ->andWhere('membership.status = :status')
            ->setParameter('inviteToken', trim($inviteToken))
            ->setParameter('status', UserTenantMembership::STATUS_PENDING)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function userHasTenant(User $user, Tenant $tenant): bool
    {
        return null !== $this->findOneBy(['user' => $user, 'tenant' => $tenant, 'status' => UserTenantMembership::STATUS_ACTIVE]);
    }
}
