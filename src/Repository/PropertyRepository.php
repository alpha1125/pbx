<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Property;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Property> */
class PropertyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Property::class);
    }

    /** @return list<Property> */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('property')
            ->andWhere('property.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('property.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?Property
    {
        return $this->findOneBy(['tenant' => $tenant, 'id' => $id]);
    }

    public function findOneByTenantAndAddress(
        Tenant $tenant,
        string $addressLine1,
        ?string $addressLine2,
        string $city,
        string $province,
        string $postalCode,
        string $country = 'CA',
    ): ?Property {
        return $this->findOneBy([
            'tenant' => $tenant,
            'addressLine1' => trim($addressLine1),
            'addressLine2' => null !== $addressLine2 ? trim($addressLine2) : null,
            'city' => trim($city),
            'province' => trim($province),
            'postalCode' => strtoupper(trim($postalCode)),
            'country' => strtoupper(trim($country)),
        ]);
    }
}
