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

    /**
     * @return list<Tenant>
     */
    public function findForVendorAnalytics(?string $search): array
    {
        $qb = $this->createQueryBuilder('tenant')
            ->andWhere('tenant.rfqVendorEnabled = true')
            ->orderBy('tenant.name', 'ASC');

        if (null !== $search && '' !== trim($search)) {
            $term = '%'.mb_strtolower(trim($search)).'%';
            $qb
                ->andWhere(
                    $qb->expr()->orX(
                        'LOWER(tenant.name) LIKE :term',
                        'LOWER(COALESCE(tenant.legalName, \'\')) LIKE :term',
                        'LOWER(COALESCE(tenant.email, \'\')) LIKE :term',
                    ),
                )
                ->setParameter('term', $term);
        }

        return $qb->getQuery()->getResult();
    }
}
