<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CallRecording;
use App\Entity\TranscriptionJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TranscriptionJob> */
final class TranscriptionJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TranscriptionJob::class);
    }

    public function hasCurrentJobForRecording(CallRecording $recording): bool
    {
        return 0 < (int) $this->createQueryBuilder('job')
            ->select('COUNT(job.id)')
            ->andWhere('job.callRecording = :recording')
            ->andWhere('job.status IN (:statuses)')
            ->setParameter('recording', $recording)
            ->setParameter('statuses', ['pending', 'claimed', 'processing', 'completed'])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findRecent(int $limit = 20): array
    {
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }
}
