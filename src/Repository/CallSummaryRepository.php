<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CallSummary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CallSummary> */
final class CallSummaryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CallSummary::class);
    }

    public function findRecent(int $limit = 20): array
    {
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }

    public function findPending(int $limit = 10): array
    {
        return $this->findBy(['status' => 'pending'], ['createdAt' => 'ASC'], $limit);
    }
}
