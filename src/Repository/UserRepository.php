<?php

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /** @return list<User> */
    public function findByTenant(Tenant $tenant, int $page = 1, int $pageSize = 20): array
    {
        return $this->createQueryBuilder('user')
            ->innerJoin('App\Entity\UserTenantMembership', 'membership', 'WITH', 'membership.user = user')
            ->andWhere('membership.tenant = :tenant')
            ->andWhere('user.isActive = true')
            ->setParameter('tenant', $tenant)
            ->orderBy('user.email', 'ASC')
            ->setFirstResult(max(0, ($page - 1) * $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();
    }

    public function countByTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('user')
            ->select('COUNT(DISTINCT user.id)')
            ->innerJoin('App\Entity\UserTenantMembership', 'membership', 'WITH', 'membership.user = user')
            ->andWhere('membership.tenant = :tenant')
            ->andWhere('user.isActive = true')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    //    /**
    //     * @return User[] Returns an array of User objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?User
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
