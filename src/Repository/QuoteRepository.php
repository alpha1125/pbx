<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Property;
use App\Entity\Quote;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Quote> */
class QuoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Quote::class);
    }

    /** @return list<Quote> */
    public function findByProperty(Property $property): array
    {
        return $this->findBy(['property' => $property], ['updatedAt' => 'DESC']);
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?Quote
    {
        return $this->findOneBy(['tenant' => $tenant, 'id' => $id]);
    }
}
