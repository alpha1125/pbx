<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Equipment;
use App\Entity\Property;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Equipment> */
class EquipmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Equipment::class);
    }

    /** @return list<Equipment> */
    public function findByProperty(Property $property): array
    {
        return $this->findBy(['property' => $property], ['createdAt' => 'DESC']);
    }

    /**
     * @param list<Property> $properties
     *
     * @return array<int, int>
     */
    public function countByProperties(array $properties): array
    {
        if ([] === $properties) {
            return [];
        }

        $rows = $this->createQueryBuilder('equipment')
            ->select('IDENTITY(equipment.property) AS propertyId, COUNT(equipment.id) AS equipmentCount')
            ->andWhere('equipment.property IN (:properties)')
            ->setParameter('properties', $properties)
            ->groupBy('equipment.property')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['propertyId']] = (int) $row['equipmentCount'];
        }

        return $counts;
    }
}
