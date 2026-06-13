<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TelnyxEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TelnyxEvent>
 */
class TelnyxEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelnyxEvent::class);
    }

    public function findOneByProviderEventId(string $providerEventId): ?TelnyxEvent
    {
        return $this->findOneBy(['providerEventId' => $providerEventId]);
    }
}
