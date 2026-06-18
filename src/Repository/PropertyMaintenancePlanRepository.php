<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Property;
use App\Entity\PropertyMaintenancePlan;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PropertyMaintenancePlan> */
class PropertyMaintenancePlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PropertyMaintenancePlan::class);
    }

    /** @return list<PropertyMaintenancePlan> */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('pmp')
            ->join('pmp.maintenancePlan', 'mp')
            ->where('pmp.tenant = :tenant')
            ->andWhere('pmp.isCancelled = false')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getResult();
    }

    /** @return list<PropertyMaintenancePlan> */
    public function findByTenantPaginated(Tenant $tenant, int $page, int $limit): array
    {
        return $this->createQueryBuilder('pmp')
            ->join('pmp.maintenancePlan', 'mp')
            ->where('pmp.tenant = :tenant')
            ->andWhere('pmp.isCancelled = false')
            ->setParameter('tenant', $tenant)
            ->setMaxResults($limit)
            ->setFirstResult(($page - 1) * $limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<PropertyMaintenancePlan> */
    public function findByProperty(Property $property): array
    {
        return $this->createQueryBuilder('pmp')
            ->join('pmp.maintenancePlan', 'mp')
            ->where('pmp.property = :property')
            ->andWhere('pmp.isCancelled = false')
            ->setParameter('property', $property)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<PropertyMaintenancePlan>
     */
    public function findHistoryByProperty(Property $property): array
    {
        return $this->createQueryBuilder('pmp')
            ->join('pmp.maintenancePlan', 'mp')
            ->where('pmp.property = :property')
            ->setParameter('property', $property)
            ->orderBy('pmp.cancellationDate', 'DESC')
            ->addOrderBy('pmp.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countByTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('pmp')
            ->select('COUNT(pmp.id)')
            ->where('pmp.tenant = :tenant')
            ->andWhere('pmp.isCancelled = false')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findByTenantAndProperty(Tenant $tenant, Property $property): ?PropertyMaintenancePlan
    {
        return $this->createQueryBuilder('pmp')
            ->where('pmp.tenant = :tenant')
            ->andWhere('pmp.property = :property')
            ->andWhere('pmp.isCancelled = false')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?PropertyMaintenancePlan
    {
        return $this->createQueryBuilder('pmp')
            ->where('pmp.tenant = :tenant')
            ->andWhere('pmp.id = :id')
            ->setParameter('tenant', $tenant)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByTenantPropertyAndMaintenancePlan(Tenant $tenant, Property $property, \App\Entity\MaintenancePlan $maintenancePlan): ?PropertyMaintenancePlan
    {
        return $this->createQueryBuilder('pmp')
            ->where('pmp.tenant = :tenant')
            ->andWhere('pmp.property = :property')
            ->andWhere('pmp.maintenancePlan = :maintenancePlan')
            ->andWhere('pmp.isCancelled = false')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->setParameter('maintenancePlan', $maintenancePlan)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<PropertyMaintenancePlan> */
    public function findCancelledByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('pmp')
            ->where('pmp.tenant = :tenant')
            ->andWhere('pmp.isCancelled = true')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getResult();
    }
}
