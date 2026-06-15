<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CallRecording;
use App\Entity\CallTranscript;
use App\Entity\TranscriptionJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CallTranscript> */
class CallTranscriptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CallTranscript::class);
    }

    public function findCurrentForRecording(string $provider, string $model, CallRecording $recording): ?CallTranscript
    {
        return $this->createQueryBuilder('transcript')
            ->andWhere('transcript.callRecording = :recording')
            ->andWhere('transcript.provider = :provider')
            ->andWhere('transcript.model = :model')
            ->andWhere('transcript.status IN (:statuses)')
            ->setParameter('recording', $recording)
            ->setParameter('provider', $provider)
            ->setParameter('model', $model)
            ->setParameter('statuses', ['processing', 'available'])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestForRecording(string $provider, string $model, CallRecording $recording): ?CallTranscript
    {
        return $this->findOneBy([
            'callRecording' => $recording,
            'provider' => $provider,
            'model' => $model,
        ], ['createdAt' => 'DESC']);
    }

    public function findOneByTranscriptionJob(TranscriptionJob $job): ?CallTranscript
    {
        return $this->findOneBy(['transcriptionJob' => $job], ['createdAt' => 'DESC']);
    }

    /** @return list<CallTranscript> */
    public function findRecent(int $limit = 20): array
    {
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }

    public function hasTranscriptForRecording(CallRecording $recording): bool
    {
        return 0 < (int) $this->createQueryBuilder('transcript')
            ->select('COUNT(transcript.id)')
            ->andWhere('transcript.callRecording = :recording')
            ->andWhere('transcript.status = :status')
            ->setParameter('recording', $recording)
            ->setParameter('status', 'available')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function hasCurrentForRecording(string $provider, string $model, CallRecording $recording): bool
    {
        return 0 < (int) $this->createQueryBuilder('transcript')
            ->select('COUNT(transcript.id)')
            ->andWhere('transcript.callRecording = :recording')
            ->andWhere('transcript.provider = :provider')
            ->andWhere('transcript.model = :model')
            ->andWhere('transcript.status IN (:statuses)')
            ->setParameter('recording', $recording)
            ->setParameter('provider', $provider)
            ->setParameter('model', $model)
            ->setParameter('statuses', ['processing', 'available'])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
