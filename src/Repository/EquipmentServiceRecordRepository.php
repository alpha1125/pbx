<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Equipment;
use App\Entity\EquipmentServiceRecord;
use App\Entity\Job;
use App\Entity\Property;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EquipmentServiceRecord> */
final class EquipmentServiceRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EquipmentServiceRecord::class);
    }

    /** @return list<EquipmentServiceRecord> */
    public function findByProperty(Property $property, int $limit = 20): array
    {
        return $this->createQueryBuilder('record')
            ->leftJoin('record.equipment', 'equipment')->addSelect('equipment')
            ->leftJoin('record.job', 'job')->addSelect('job')
            ->leftJoin('record.technician', 'technician')->addSelect('technician')
            ->andWhere('record.property = :property')
            ->setParameter('property', $property)
            ->orderBy('record.completedAt', 'DESC')
            ->addOrderBy('record.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<EquipmentServiceRecord> */
    public function findByJob(Job $job): array
    {
        return $this->createQueryBuilder('record')
            ->leftJoin('record.equipment', 'equipment')->addSelect('equipment')
            ->leftJoin('record.technician', 'technician')->addSelect('technician')
            ->andWhere('record.job = :job')
            ->setParameter('job', $job)
            ->orderBy('record.completedAt', 'DESC')
            ->addOrderBy('record.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<EquipmentServiceRecord> */
    public function findByEquipment(Equipment $equipment): array
    {
        return $this->createQueryBuilder('record')
            ->leftJoin('record.job', 'job')->addSelect('job')
            ->leftJoin('record.technician', 'technician')->addSelect('technician')
            ->andWhere('record.equipment = :equipment')
            ->setParameter('equipment', $equipment)
            ->orderBy('record.completedAt', 'DESC')
            ->addOrderBy('record.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
