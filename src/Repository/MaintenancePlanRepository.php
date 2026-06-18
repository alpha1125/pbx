<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MaintenancePlan;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MaintenancePlan> */
class MaintenancePlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MaintenancePlan::class);
    }

    /** @return list<MaintenancePlan> */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('mp')
            ->where('mp.tenant = :tenant')
            ->andWhere('mp.isActive = true')
            ->setParameter('tenant', $tenant)
            ->orderBy('mp.planType', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<MaintenancePlan> */
    public function findByTenantOrdered(Tenant $tenant, int $limit): array
    {
        return $this->createQueryBuilder('mp')
            ->where('mp.tenant = :tenant')
            ->andWhere('mp.isActive = true')
            ->setParameter('tenant', $tenant)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('mp')
            ->select('COUNT(mp.id)')
            ->where('mp.tenant = :tenant')
            ->andWhere('mp.isActive = true')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<MaintenancePlan> */
    public function findActiveByPlanType(Tenant $tenant, string $planType): array
    {
        return $this->createQueryBuilder('mp')
            ->where('mp.tenant = :tenant')
            ->andWhere('mp.planType = :type')
            ->andWhere('mp.isActive = true')
            ->setParameter('tenant', $tenant)
            ->setParameter('type', $planType)
            ->getQuery()
            ->getResult();
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?MaintenancePlan
    {
        return $this->createQueryBuilder('mp')
            ->where('mp.tenant = :tenant')
            ->andWhere('mp.id = :id')
            ->setParameter('tenant', $tenant)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<MaintenancePlan> */
    public function findDraftsByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('mp')
            ->where('mp.tenant = :tenant')
            ->andWhere('mp.isActive = false')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getResult();
    }
}
