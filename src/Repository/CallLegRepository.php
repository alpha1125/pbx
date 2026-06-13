<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CallLeg;
use App\Entity\CallSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CallLeg> */
class CallLegRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CallLeg::class);
    }

    public function findOneByProviderLegId(string $providerLegId): ?CallLeg
    {
        return $this->findOneBy(['providerLegId' => $providerLegId]);
    }

    public function hasOtherActiveLegs(CallSession $session, CallLeg $endingLeg): bool
    {
        $queryBuilder = $this->createQueryBuilder('leg')
            ->select('COUNT(leg.id)')
            ->andWhere('leg.callSession = :session')
            ->andWhere('leg.status NOT IN (:terminalStatuses)')
            ->setParameter('session', $session)
            ->setParameter('terminalStatuses', ['completed', 'failed']);

        if (null !== $endingLeg->getId()) {
            $queryBuilder
                ->andWhere('leg.id != :endingLegId')
                ->setParameter('endingLegId', $endingLeg->getId());
        }

        return 0 < (int) $queryBuilder->getQuery()
            ->getSingleScalarResult();
    }

    public function findInboundLegForRootSession(CallSession $session): ?CallLeg
    {
        return $this->createQueryBuilder('leg')
            ->innerJoin('leg.callSession', 'session')
            ->andWhere('session = :rootSession')
            ->andWhere('leg.direction = :direction')
            ->setParameter('rootSession', $session)
            ->setParameter('direction', 'incoming')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function hasAnsweredOutboundLeg(CallSession $session): bool
    {
        return 0 < (int) $this->createQueryBuilder('leg')
            ->select('COUNT(leg.id)')
            ->innerJoin('leg.callSession', 'legSession')
            ->andWhere('legSession.parentCallSession = :rootSession')
            ->andWhere('leg.direction = :direction')
            ->andWhere('leg.status IN (:statuses)')
            ->setParameter('rootSession', $session)
            ->setParameter('direction', 'outgoing')
            ->setParameter('statuses', ['answered', 'bridged', 'completed'])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
