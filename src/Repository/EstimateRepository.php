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
}
