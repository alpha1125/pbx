<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Estimate;
use App\Entity\EstimateLineItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EstimateLineItem> */
class EstimateLineItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EstimateLineItem::class);
    }

    /** @return list<EstimateLineItem> */
    public function findByEstimate(Estimate $estimate): array
    {
        return $this->findBy(['estimate' => $estimate], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }
}
