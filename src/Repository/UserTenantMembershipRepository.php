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
        return $this->findOneBy(['user' => $user, 'isDefault' => true])
            ?? $this->createQueryBuilder('membership')
                ->andWhere('membership.user = :user')
                ->setParameter('user', $user)
                ->orderBy('membership.id', 'ASC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
    }

    public function userHasTenant(User $user, Tenant $tenant): bool
    {
        return null !== $this->findOneBy(['user' => $user, 'tenant' => $tenant]);
    }
}
