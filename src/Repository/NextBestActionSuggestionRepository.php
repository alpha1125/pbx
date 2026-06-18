<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NextBestActionSuggestion;
use App\Entity\Property;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<NextBestActionSuggestion> */
final class NextBestActionSuggestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NextBestActionSuggestion::class);
    }

    /**
     * @return list<NextBestActionSuggestion>
     */
    public function findByTenantAndPropertyOrdered(Tenant $tenant, Property $property): array
    {
        return $this->createQueryBuilder('suggestion')
            ->leftJoin('suggestion.opportunity', 'opportunity')->addSelect('opportunity')
            ->andWhere('suggestion.tenant = :tenant')
            ->andWhere('suggestion.property = :property')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->orderBy('suggestion.updatedAt', 'DESC')
            ->addOrderBy('suggestion.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?NextBestActionSuggestion
    {
        return $this->createQueryBuilder('suggestion')
            ->leftJoin('suggestion.opportunity', 'opportunity')->addSelect('opportunity')
            ->andWhere('suggestion.tenant = :tenant')
            ->andWhere('suggestion.id = :id')
            ->setParameter('tenant', $tenant)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByTenantPropertyTypeAndSourceKey(Tenant $tenant, Property $property, string $suggestionType, string $sourceKey): ?NextBestActionSuggestion
    {
        return $this->createQueryBuilder('suggestion')
            ->leftJoin('suggestion.opportunity', 'opportunity')->addSelect('opportunity')
            ->andWhere('suggestion.tenant = :tenant')
            ->andWhere('suggestion.property = :property')
            ->andWhere('suggestion.suggestionType = :suggestionType')
            ->andWhere('suggestion.sourceKey = :sourceKey')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->setParameter('suggestionType', $suggestionType)
            ->setParameter('sourceKey', $sourceKey)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
