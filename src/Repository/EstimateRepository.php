<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Estimate;
use App\Entity\Property;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Estimate> */
class EstimateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Estimate::class);
    }

    /** @return list<Estimate> */
    public function findByProperty(Property $property): array
    {
        return $this->findBy(['property' => $property], ['updatedAt' => 'DESC']);
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?Estimate
    {
        return $this->findOneBy(['tenant' => $tenant, 'id' => $id]);
    }

    public function countCreatedBetween(Tenant $tenant, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('estimate')
            ->select('COUNT(estimate.id)')
            ->andWhere('estimate.tenant = :tenant')
            ->andWhere('estimate.createdAt >= :from')
            ->andWhere('estimate.createdAt < :to')
            ->setParameter('tenant', $tenant)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countConvertedToQuoteBetween(Tenant $tenant, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('estimate')
            ->select('COUNT(estimate.id)')
            ->andWhere('estimate.tenant = :tenant')
            ->andWhere('estimate.status = :status')
            ->andWhere('estimate.updatedAt >= :from')
            ->andWhere('estimate.updatedAt < :to')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', Estimate::STATUS_CONVERTED_TO_QUOTE)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
