<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CommunicationTimelineItem;
use App\Entity\Property;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CommunicationTimelineItem> */
final class CommunicationTimelineItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommunicationTimelineItem::class);
    }

    public function findOneBySourceKey(string $sourceKey): ?CommunicationTimelineItem
    {
        return $this->findOneBy(['sourceKey' => trim($sourceKey)]);
    }

    /**
     * @param list<string>|null $itemTypes
     *
     * @return list<CommunicationTimelineItem>
     */
    public function findByTenantAndPropertyOrdered(Tenant $tenant, Property $property, ?array $itemTypes = null, ?string $search = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('item')
            ->leftJoin('item.contact', 'contact')->addSelect('contact')
            ->leftJoin('item.callSession', 'callSession')->addSelect('callSession')
            ->leftJoin('item.callRecording', 'callRecording')->addSelect('callRecording')
            ->leftJoin('item.callTranscript', 'callTranscript')->addSelect('callTranscript')
            ->leftJoin('item.callSummary', 'callSummary')->addSelect('callSummary')
            ->leftJoin('item.createdBy', 'createdBy')->addSelect('createdBy')
            ->andWhere('item.tenant = :tenant')
            ->andWhere('item.property = :property')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->orderBy('item.occurredAt', 'DESC')
            ->addOrderBy('item.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $itemTypes && [] !== $itemTypes) {
            $qb->andWhere('item.itemType IN (:itemTypes)')
                ->setParameter('itemTypes', $itemTypes);
        }

        if (null !== $search && '' !== trim($search)) {
            $term = '%'.mb_strtolower(trim($search)).'%';
            $qb->andWhere(
                $qb->expr()->orX(
                    'LOWER(COALESCE(item.bodyText, \'\')) LIKE :term',
                    'LOWER(COALESCE(item.sourceKey, \'\')) LIKE :term',
                    'LOWER(COALESCE(item.disposition, \'\')) LIKE :term',
                    'LOWER(COALESCE(contact.displayName, \'\')) LIKE :term',
                    'LOWER(COALESCE(contact.primaryEmail, \'\')) LIKE :term',
                    'LOWER(COALESCE(contact.primaryPhone, \'\')) LIKE :term',
                    'LOWER(COALESCE(callSession.inboundFrom, \'\')) LIKE :term',
                    'LOWER(COALESCE(callSession.inboundTo, \'\')) LIKE :term',
                    'LOWER(COALESCE(callTranscript.transcriptText, \'\')) LIKE :term',
                    'LOWER(COALESCE(callSummary.summaryText, \'\')) LIKE :term',
                    'LOWER(COALESCE(callRecording.providerRecordingId, \'\')) LIKE :term',
                ),
            )
                ->setParameter('term', $term);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @param list<string>|null $itemTypes
     *
     * @return list<CommunicationTimelineItem>
     */
    public function findByTenantAndProperty(Tenant $tenant, Property $property, ?array $itemTypes = null, int $limit = 100): array
    {
        return $this->findByTenantAndPropertyOrdered($tenant, $property, $itemTypes, null, $limit);
    }

    /**
     * @param list<string>|null $itemTypes
     *
     * @return list<CommunicationTimelineItem>
     */
    public function searchByTenant(Tenant $tenant, string $search, ?array $itemTypes = null, int $limit = 100): array
    {
        $term = '%'.mb_strtolower(trim($search)).'%';
        $qb = $this->createQueryBuilder('item');
        $expr = $qb->expr();

        $qb
            ->leftJoin('item.property', 'property')->addSelect('property')
            ->leftJoin('item.contact', 'contact')->addSelect('contact')
            ->leftJoin('item.callSession', 'callSession')->addSelect('callSession')
            ->leftJoin('item.callRecording', 'callRecording')->addSelect('callRecording')
            ->leftJoin('item.callTranscript', 'callTranscript')->addSelect('callTranscript')
            ->leftJoin('item.callSummary', 'callSummary')->addSelect('callSummary')
            ->leftJoin('item.quote', 'quote')->addSelect('quote')
            ->leftJoin('item.invoice', 'invoice')->addSelect('invoice')
            ->andWhere('item.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->andWhere(
                $expr->orX(
                    'LOWER(COALESCE(item.bodyText, \'\')) LIKE :term',
                    'LOWER(COALESCE(item.sourceKey, \'\')) LIKE :term',
                    'LOWER(COALESCE(item.disposition, \'\')) LIKE :term',
                    'LOWER(COALESCE(property.addressLine1, \'\')) LIKE :term',
                    'LOWER(COALESCE(property.city, \'\')) LIKE :term',
                    'LOWER(COALESCE(property.postalCode, \'\')) LIKE :term',
                    'LOWER(COALESCE(contact.displayName, \'\')) LIKE :term',
                    'LOWER(COALESCE(contact.primaryEmail, \'\')) LIKE :term',
                    'LOWER(COALESCE(contact.primaryPhone, \'\')) LIKE :term',
                    'LOWER(COALESCE(callSession.inboundFrom, \'\')) LIKE :term',
                    'LOWER(COALESCE(callSession.inboundTo, \'\')) LIKE :term',
                    'LOWER(COALESCE(callTranscript.transcriptText, \'\')) LIKE :term',
                    'LOWER(COALESCE(callSummary.summaryText, \'\')) LIKE :term',
                    'LOWER(COALESCE(quote.quoteNumber, \'\')) LIKE :term',
                    'LOWER(COALESCE(invoice.invoiceNumber, \'\')) LIKE :term',
                    'LOWER(COALESCE(callRecording.providerRecordingId, \'\')) LIKE :term',
                ),
            )
            ->setParameter('term', $term)
            ->orderBy('item.occurredAt', 'DESC')
            ->addOrderBy('item.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $itemTypes && [] !== $itemTypes) {
            $qb->andWhere('item.itemType IN (:itemTypes)')
                ->setParameter('itemTypes', $itemTypes);
        }

        return $qb->getQuery()->getResult();
    }
}
