<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\PropertyContact;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PropertyContact> */
class PropertyContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PropertyContact::class);
    }

    /** @return list<PropertyContact> */
    public function findByProperty(Property $property): array
    {
        return $this->createQueryBuilder('propertyContact')
            ->leftJoin('propertyContact.contact', 'contact')->addSelect('contact')
            ->andWhere('propertyContact.property = :property')
            ->andWhere('propertyContact.endDate IS NULL')
            ->andWhere('contact.isArchived = false')
            ->setParameter('property', $property)
            ->orderBy('propertyContact.isPrimary', 'DESC')
            ->addOrderBy('propertyContact.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findPrimaryByProperty(Property $property): ?PropertyContact
    {
        return $this->createQueryBuilder('propertyContact')
            ->leftJoin('propertyContact.contact', 'contact')->addSelect('contact')
            ->andWhere('propertyContact.property = :property')
            ->andWhere('propertyContact.isPrimary = true')
            ->andWhere('propertyContact.endDate IS NULL')
            ->andWhere('contact.isArchived = false')
            ->setParameter('property', $property)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<Property> $properties
     *
     * @return array<int, PropertyContact>
     */
    public function findPrimaryByProperties(array $properties): array
    {
        $propertyIds = array_values(array_filter(array_map(static fn (Property $property): ?int => $property->getId(), $properties)));
        if ([] === $propertyIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('propertyContact')
            ->leftJoin('propertyContact.contact', 'contact')->addSelect('contact')
            ->leftJoin('propertyContact.property', 'property')->addSelect('property')
            ->andWhere('propertyContact.property IN (:properties)')
            ->andWhere('propertyContact.isPrimary = true')
            ->andWhere('propertyContact.endDate IS NULL')
            ->andWhere('contact.isArchived = false')
            ->setParameter('properties', $properties)
            ->getQuery()
            ->getResult();

        $mapped = [];
        foreach ($rows as $row) {
            if ($row instanceof PropertyContact && null !== $row->getProperty()->getId()) {
                $mapped[$row->getProperty()->getId()] = $row;
            }
        }

        return $mapped;
    }

    public function findOneByTenantPropertyAndContact(Tenant $tenant, Property $property, Contact $contact): ?PropertyContact
    {
        return $this->createQueryBuilder('propertyContact')
            ->andWhere('propertyContact.tenant = :tenant')
            ->andWhere('propertyContact.property = :property')
            ->andWhere('propertyContact.contact = :contact')
            ->andWhere('propertyContact.endDate IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->setParameter('contact', $contact)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAnyByTenantPropertyAndContact(Tenant $tenant, Property $property, Contact $contact): ?PropertyContact
    {
        return $this->findOneBy([
            'tenant' => $tenant,
            'property' => $property,
            'contact' => $contact,
        ]);
    }

    /** @return list<PropertyContact> */
    public function findByPropertyId(int $propertyId): array
    {
        return $this->createQueryBuilder('propertyContact')
            ->leftJoin('propertyContact.contact', 'contact')->addSelect('contact')
            ->andWhere('IDENTITY(propertyContact.property) = :propertyId')
            ->andWhere('propertyContact.endDate IS NULL')
            ->andWhere('contact.isArchived = false')
            ->setParameter('propertyId', $propertyId)
            ->orderBy('propertyContact.isPrimary', 'DESC')
            ->addOrderBy('propertyContact.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<PropertyContact> */
    public function findByPropertyPaginated(Property $property, int $page, int $pageSize): array
    {
        return $this->createQueryBuilder('propertyContact')
            ->leftJoin('propertyContact.contact', 'contact')->addSelect('contact')
            ->andWhere('propertyContact.property = :property')
            ->andWhere('propertyContact.endDate IS NULL')
            ->andWhere('contact.isArchived = false')
            ->setParameter('property', $property)
            ->orderBy('propertyContact.isPrimary', 'DESC')
            ->addOrderBy('propertyContact.id', 'ASC')
            ->setFirstResult(max(0, ($page - 1) * $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();
    }

    public function countByProperty(Property $property): int
    {
        return (int) $this->createQueryBuilder('propertyContact')
            ->select('COUNT(propertyContact.id)')
            ->leftJoin('propertyContact.contact', 'contact')
            ->andWhere('propertyContact.property = :property')
            ->andWhere('propertyContact.endDate IS NULL')
            ->andWhere('contact.isArchived = false')
            ->setParameter('property', $property)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
