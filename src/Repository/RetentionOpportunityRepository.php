<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Property;
use App\Entity\RetentionOpportunity;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RetentionOpportunity> */
class RetentionOpportunityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RetentionOpportunity::class);
    }

    /**
     * @return list<RetentionOpportunity>
     */
    public function findByTenantOrdered(Tenant $tenant): array
    {
        return $this->createQueryBuilder('opportunity')
            ->leftJoin('opportunity.property', 'property')->addSelect('property')
            ->leftJoin('opportunity.contact', 'contact')->addSelect('contact')
            ->leftJoin('opportunity.equipment', 'equipment')->addSelect('equipment')
            ->andWhere('opportunity.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('opportunity.detectedAt', 'DESC')
            ->addOrderBy('opportunity.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<RetentionOpportunity>
     */
    public function findOpenByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('opportunity')
            ->leftJoin('opportunity.property', 'property')->addSelect('property')
            ->leftJoin('opportunity.contact', 'contact')->addSelect('contact')
            ->leftJoin('opportunity.equipment', 'equipment')->addSelect('equipment')
            ->andWhere('opportunity.tenant = :tenant')
            ->andWhere('opportunity.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', RetentionOpportunity::STATUS_OPEN)
            ->orderBy('opportunity.detectedAt', 'DESC')
            ->addOrderBy('opportunity.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<RetentionOpportunity>
     */
    public function findByTenantAndProperty(Tenant $tenant, Property $property): array
    {
        return $this->createQueryBuilder('opportunity')
            ->leftJoin('opportunity.contact', 'contact')->addSelect('contact')
            ->leftJoin('opportunity.equipment', 'equipment')->addSelect('equipment')
            ->andWhere('opportunity.tenant = :tenant')
            ->andWhere('opportunity.property = :property')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->orderBy('opportunity.detectedAt', 'DESC')
            ->addOrderBy('opportunity.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<RetentionOpportunity>
     */
    public function findOpenByTenantAndProperty(Tenant $tenant, Property $property): array
    {
        return $this->createQueryBuilder('opportunity')
            ->leftJoin('opportunity.contact', 'contact')->addSelect('contact')
            ->leftJoin('opportunity.equipment', 'equipment')->addSelect('equipment')
            ->andWhere('opportunity.tenant = :tenant')
            ->andWhere('opportunity.property = :property')
            ->andWhere('opportunity.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->setParameter('status', RetentionOpportunity::STATUS_OPEN)
            ->orderBy('opportunity.detectedAt', 'DESC')
            ->addOrderBy('opportunity.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOpenByTenantPropertyTypeAndSourceKey(Tenant $tenant, Property $property, string $opportunityType, string $sourceKey): ?RetentionOpportunity
    {
        return $this->createQueryBuilder('opportunity')
            ->andWhere('opportunity.tenant = :tenant')
            ->andWhere('opportunity.property = :property')
            ->andWhere('opportunity.opportunityType = :opportunityType')
            ->andWhere('opportunity.sourceKey = :sourceKey')
            ->andWhere('opportunity.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->setParameter('opportunityType', $opportunityType)
            ->setParameter('sourceKey', $sourceKey)
            ->setParameter('status', RetentionOpportunity::STATUS_OPEN)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?RetentionOpportunity
    {
        return $this->createQueryBuilder('opportunity')
            ->andWhere('opportunity.tenant = :tenant')
            ->andWhere('opportunity.id = :id')
            ->setParameter('tenant', $tenant)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countOpenByTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('opportunity')
            ->select('COUNT(opportunity.id)')
            ->andWhere('opportunity.tenant = :tenant')
            ->andWhere('opportunity.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', RetentionOpportunity::STATUS_OPEN)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
