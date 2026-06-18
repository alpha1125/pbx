<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CallTranscript;
use App\Entity\CallTranscriptSegment;
use App\Entity\Tenant;
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

    /**
     * @return list<CallTranscriptSegment>
     */
    public function searchByTenant(Tenant $tenant, string $search, int $limit = 50): array
    {
        $term = '%'.mb_strtolower(trim($search)).'%';

        return $this->createQueryBuilder('segment')
            ->leftJoin('segment.callTranscript', 'transcript')->addSelect('transcript')
            ->leftJoin('segment.callSession', 'callSession')->addSelect('callSession')
            ->leftJoin('callSession.property', 'property')->addSelect('property')
            ->leftJoin('callSession.contact', 'contact')->addSelect('contact')
            ->andWhere('callSession.tenant = :tenant')
            ->andWhere(
                'LOWER(COALESCE(segment.text, \'\')) LIKE :term OR '.
                'LOWER(COALESCE(transcript.transcriptText, \'\')) LIKE :term OR '.
                'LOWER(COALESCE(contact.displayName, \'\')) LIKE :term OR '.
                'LOWER(COALESCE(contact.primaryEmail, \'\')) LIKE :term OR '.
                'LOWER(COALESCE(contact.primaryPhone, \'\')) LIKE :term OR '.
                'LOWER(COALESCE(property.addressLine1, \'\')) LIKE :term OR '.
                'LOWER(COALESCE(property.city, \'\')) LIKE :term OR '.
                'LOWER(COALESCE(property.postalCode, \'\')) LIKE :term OR '.
                'LOWER(COALESCE(callSession.inboundFrom, \'\')) LIKE :term OR '.
                'LOWER(COALESCE(callSession.inboundTo, \'\')) LIKE :term'
            )
            ->setParameter('tenant', $tenant)
            ->setParameter('term', $term)
            ->orderBy('segment.occurredAt', 'DESC')
            ->addOrderBy('segment.sequenceNumber', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
