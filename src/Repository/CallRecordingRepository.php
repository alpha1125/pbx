<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CallLeg;
use App\Entity\CallRecording;
use App\Entity\CallSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CallRecording> */
class CallRecordingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CallRecording::class);
    }

    public function findOneByProviderRecordingId(string $id): ?CallRecording
    {
        return $this->findOneBy(['providerRecordingId' => $id]);
    }

    public function findRequested(CallSession $session, ?CallLeg $leg): ?CallRecording
    {
        return $this->findOneBy([
            'callSession' => $session,
            'callLeg' => $leg,
            'status' => 'requested',
        ], ['createdAt' => 'DESC']);
    }

    /** @return list<CallRecording> */
    public function findRecent(int $limit = 20): array
    {
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }

    /**
     * @param list<CallSession> $sessions
     *
     * @return list<CallRecording>
     */
    public function findBySessions(array $sessions): array
    {
        if ([] === $sessions) {
            return [];
        }

        return $this->createQueryBuilder('recording')
            ->andWhere('recording.callSession IN (:sessions)')
            ->setParameter('sessions', $sessions)
            ->orderBy('recording.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<CallRecording> */
    public function findPendingImports(int $limit = 50): array
    {
        return $this->createQueryBuilder('recording')
            ->andWhere('recording.providerDownloadUrl IS NOT NULL')
            ->andWhere('recording.s3Key IS NULL')
            ->andWhere('recording.status IN (:statuses)')
            ->setParameter('statuses', ['import_pending', 'saved'])
            ->orderBy('recording.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<CallRecording> */
    public function findImportedWithoutCurrentTranscript(
        string $provider,
        string $model,
        int $limit,
        CallTranscriptRepository $transcripts,
    ): array {
        $recordings = $this->createQueryBuilder('recording')
            ->andWhere('recording.status = :status')
            ->andWhere('recording.s3Bucket IS NOT NULL')
            ->andWhere('recording.s3Key IS NOT NULL')
            ->orderBy('recording.importedAt', 'ASC')
            ->setParameter('status', 'imported')
            ->setMaxResults($limit * 3)
            ->getQuery()
            ->getResult();

        $pending = [];
        foreach ($recordings as $recording) {
            if ($transcripts->hasCurrentForRecording($provider, $model, $recording)) {
                continue;
            }
            $pending[] = $recording;
            if (count($pending) >= $limit) {
                break;
            }
        }

        return $pending;
    }

    /** @return list<CallRecording> */
    public function findImportedWithStorage(int $limit = 50): array
    {
        return $this->createQueryBuilder('recording')
            ->andWhere('recording.status = :status')
            ->andWhere('recording.s3Bucket IS NOT NULL')
            ->andWhere('recording.s3Key IS NOT NULL')
            ->orderBy('recording.importedAt', 'ASC')
            ->setParameter('status', 'imported')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
