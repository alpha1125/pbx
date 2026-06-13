<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClickToCallRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ClickToCallRequest> */
final class ClickToCallRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClickToCallRequest::class);
    }

    public function findOneByClientStateToken(string $clientStateToken): ?ClickToCallRequest
    {
        return $this->findOneBy(['clientStateToken' => $clientStateToken]);
    }

    public function findOneByAnyLegId(?string $providerLegId): ?ClickToCallRequest
    {
        if (null === $providerLegId || '' === trim($providerLegId)) {
            return null;
        }

        return $this->createQueryBuilder('request')
            ->andWhere('request.agentCallLegId = :providerLegId OR request.targetCallLegId = :providerLegId')
            ->setParameter('providerLegId', $providerLegId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByAnyCallControlId(?string $callControlId): ?ClickToCallRequest
    {
        if (null === $callControlId || '' === trim($callControlId)) {
            return null;
        }

        return $this->createQueryBuilder('request')
            ->andWhere('request.agentCallControlId = :callControlId OR request.targetCallControlId = :callControlId')
            ->setParameter('callControlId', $callControlId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<ClickToCallRequest> */
    public function findRecent(int $limit = 20): array
    {
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }
}
