<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Tenant> */
class TenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    public function findOneByName(string $name): ?Tenant
    {
        return $this->findOneBy(['name' => trim($name)]);
    }

    public function findDefaultTenant(?int $tenantId = null, ?string $tenantName = null): ?Tenant
    {
        if (null !== $tenantId) {
            $tenant = $this->find($tenantId);
            if (null !== $tenant) {
                return $tenant;
            }
        }

        if (null !== $tenantName && '' !== trim($tenantName)) {
            $tenant = $this->findOneByName($tenantName);
            if (null !== $tenant) {
                return $tenant;
            }
        }

        return $this->createQueryBuilder('tenant')
            ->orderBy('tenant.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
