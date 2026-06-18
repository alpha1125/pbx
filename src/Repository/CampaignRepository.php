<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Campaign;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Campaign> */
class CampaignRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Campaign::class);
    }

    /**
     * @return list<Campaign>
     */
    public function findByTenantOrdered(Tenant $tenant, int $limit = 100): array
    {
        return $this->createQueryBuilder('campaign')
            ->andWhere('campaign.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('campaign.updatedAt', 'DESC')
            ->addOrderBy('campaign.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('campaign')
            ->select('COUNT(campaign.id)')
            ->andWhere('campaign.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?Campaign
    {
        return $this->createQueryBuilder('campaign')
            ->andWhere('campaign.tenant = :tenant')
            ->andWhere('campaign.id = :id')
            ->setParameter('tenant', $tenant)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
