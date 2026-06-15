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
            ->setParameter('statuses', ['pending', 'submitted', 'processing', 'completed'])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneForRecordingAndProvider(CallRecording $recording, string $provider): ?TranscriptionJob
    {
        return $this->findOneBy([
            'callRecording' => $recording,
            'provider' => $provider,
        ], ['createdAt' => 'DESC']);
    }

    public function findOneForSessionAndProvider(\App\Entity\CallSession $session, string $provider): ?TranscriptionJob
    {
        return $this->findOneBy([
            'callSession' => $session,
            'provider' => $provider,
        ], ['createdAt' => 'DESC']);
    }

    public function findOneByProviderJobId(string $provider, string $providerJobId): ?TranscriptionJob
    {
        return $this->findOneBy([
            'provider' => $provider,
            'providerJobId' => $providerJobId,
        ], ['createdAt' => 'DESC']);
    }

    /** @return list<TranscriptionJob> */
    public function findPendingForProvider(string $provider, int $limit = 25): array
    {
        return $this->createQueryBuilder('job')
            ->andWhere('job.provider = :provider')
            ->andWhere('job.status = :status')
            ->setParameter('provider', $provider)
            ->setParameter('status', 'pending')
            ->orderBy('job.priority', 'DESC')
            ->addOrderBy('job.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findRecent(int $limit = 20): array
    {
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }
}
