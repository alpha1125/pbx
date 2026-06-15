<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CallTranscript;
use App\Entity\CallTranscriptSegment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CallTranscriptSegment> */
final class CallTranscriptSegmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CallTranscriptSegment::class);
    }

    public function nextSequenceNumber(CallTranscript $transcript): int
    {
        return 1 + (int) ($this->createQueryBuilder('segment')
            ->select('COALESCE(MAX(segment.sequenceNumber), 0)')
            ->andWhere('segment.callTranscript = :transcript')
            ->setParameter('transcript', $transcript)
            ->getQuery()
            ->getSingleScalarResult());
    }

    /** @return list<CallTranscriptSegment> */
    public function findByTranscript(CallTranscript $transcript): array
    {
        return $this->findBy(['callTranscript' => $transcript], ['sequenceNumber' => 'ASC']);
    }
}
