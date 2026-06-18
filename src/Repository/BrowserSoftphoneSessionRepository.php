<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BrowserSoftphoneSession;
use App\Entity\CallSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BrowserSoftphoneSession> */
final class BrowserSoftphoneSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrowserSoftphoneSession::class);
    }

    public function findOneByCallSession(CallSession $session): ?BrowserSoftphoneSession
    {
        return $this->findOneBy(['callSession' => $session]);
    }

    public function findOneBySessionToken(string $sessionToken): ?BrowserSoftphoneSession
    {
        return $this->findOneBy(['sessionToken' => $sessionToken]);
    }
}
