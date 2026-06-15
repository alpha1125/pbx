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
        return $this->findBy(['property' => $property], ['isPrimary' => 'DESC', 'id' => 'ASC']);
    }

    public function findPrimaryByProperty(Property $property): ?PropertyContact
    {
        return $this->findOneBy(['property' => $property, 'isPrimary' => true]);
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
        return $this->findOneBy([
            'tenant' => $tenant,
            'property' => $property,
            'contact' => $contact,
        ]);
    }
}
