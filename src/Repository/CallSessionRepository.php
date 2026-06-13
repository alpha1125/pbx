<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CallSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CallSession> */
class CallSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CallSession::class);
    }

    public function findOneByProviderSessionId(string $providerSessionId): ?CallSession
    {
        return $this->findOneBy(['providerSessionId' => $providerSessionId]);
    }

    /** @return list<CallSession> */
    public function findRecent(int $limit = 20): array
    {
        return $this->createQueryBuilder('session')
            ->andWhere('session.parentCallSession IS NULL')
            ->orderBy('session.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<CallSession> $sessions
     *
     * @return array<int, int|null>
     */
    public function findBilledDurationSeconds(array $sessions): array
    {
        $sessionIds = array_values(array_filter(array_map(
            static fn (CallSession $session): ?int => $session->getId(),
            $sessions,
        )));
        if ([] === $sessionIds) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT COALESCE(session.parent_call_session_id, session.id) AS root_session_id,
                       SUM(leg.billed_duration_seconds) AS billed_seconds
                FROM call_leg leg
                INNER JOIN call_session session ON session.id = leg.call_session_id
                WHERE COALESCE(session.parent_call_session_id, session.id) IN (?)
                  AND leg.billed_duration_seconds IS NOT NULL
                GROUP BY root_session_id
                SQL,
            [$sessionIds],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $totals = array_fill_keys($sessionIds, null);
        foreach ($rows as $row) {
            $totals[(int) $row['root_session_id']] = (int) $row['billed_seconds'];
        }

        return $totals;
    }
}
