<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CustomerSentimentHistory;
use App\Entity\Property;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CustomerSentimentHistory> */
class CustomerSentimentHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerSentimentHistory::class);
    }

    /**
     * @return list<CustomerSentimentHistory>
     */
    public function findByTenantAndProperty(Tenant $tenant, Property $property, int $limit = 20): array
    {
        return $this->createQueryBuilder('sentiment')
            ->leftJoin('sentiment.contact', 'contact')->addSelect('contact')
            ->leftJoin('sentiment.callSession', 'callSession')->addSelect('callSession')
            ->leftJoin('sentiment.recordedBy', 'recordedBy')->addSelect('recordedBy')
            ->andWhere('sentiment.tenant = :tenant')
            ->andWhere('sentiment.property = :property')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->orderBy('sentiment.recordedAt', 'DESC')
            ->addOrderBy('sentiment.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?CustomerSentimentHistory
    {
        return $this->createQueryBuilder('sentiment')
            ->andWhere('sentiment.tenant = :tenant')
            ->andWhere('sentiment.id = :id')
            ->setParameter('tenant', $tenant)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
