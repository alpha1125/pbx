<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\Property;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Invoice> */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /** @return list<Invoice> */
    public function findByProperty(Property $property): array
    {
        return $this->findBy(['property' => $property], ['updatedAt' => 'DESC']);
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?Invoice
    {
        return $this->findOneBy(['tenant' => $tenant, 'id' => $id]);
    }
}
