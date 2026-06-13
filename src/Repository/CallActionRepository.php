<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CallAction;
use App\Entity\CallLeg;
use App\Entity\CallSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CallAction> */
final class CallActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CallAction::class);
    }

    public function hasActionForSession(CallSession $session, string $actionType): bool
    {
        return null !== $this->findOneBy([
            'callSession' => $session,
            'actionType' => $actionType,
        ]);
    }
}
