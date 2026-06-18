<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CallSummary;
use App\Entity\CallTranscript;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CallSummary> */
final class CallSummaryRepository extends ServiceEntityRepository implements CallSummaryLookupInterface
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

    public function findOneByTranscript(CallTranscript $transcript): ?CallSummary
    {
        return $this->findOneBy(['callTranscript' => $transcript], ['createdAt' => 'DESC']);
    }

    /**
     * @param list<\App\Entity\CallSession> $sessions
     *
     * @return list<CallSummary>
     */
    public function findBySessions(array $sessions): array
    {
        if ([] === $sessions) {
            return [];
        }

        return $this->createQueryBuilder('summary')
            ->andWhere('summary.callSession IN (:sessions)')
            ->setParameter('sessions', $sessions)
            ->orderBy('summary.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
