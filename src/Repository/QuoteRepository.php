<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Property;
use App\Entity\Quote;
use App\Entity\Estimate;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Quote> */
class QuoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Quote::class);
    }

    /** @return list<Quote> */
    public function findByProperty(Property $property): array
    {
        return $this->findBy(['property' => $property], ['updatedAt' => 'DESC']);
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?Quote
    {
        return $this->findOneBy(['tenant' => $tenant, 'id' => $id]);
    }

    public function countByTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->andWhere('q.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByShareToken(string $token): ?Quote
    {
        return $this->findOneBy(['shareToken' => $token]);
    }

    public function findOneByEstimate(Estimate $estimate): ?Quote
    {
        return $this->findOneBy(['estimate' => $estimate]);
    }

    public function findLatestRevisionForRoot(Quote $quote): ?Quote
    {
        $root = $quote->getRootQuote() ?? $quote;

        return $this->createQueryBuilder('q')
            ->andWhere('q.rootQuote = :root OR q.id = :rootId')
            ->setParameter('root', $root)
            ->setParameter('rootId', $root->getId())
            ->orderBy('q.revisionNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<int> $tenantIds
     * @return list<Quote>
     */
    public function findForVendorAnalyticsByTenantIds(array $tenantIds): array
    {
        if ([] === $tenantIds) {
            return [];
        }

        return $this->createQueryBuilder('quote')
            ->leftJoin('quote.tenant', 'tenant')->addSelect('tenant')
            ->leftJoin('quote.property', 'property')->addSelect('property')
            ->leftJoin('quote.contact', 'contact')->addSelect('contact')
            ->leftJoin('quote.estimate', 'estimate')->addSelect('estimate')
            ->andWhere('tenant.id IN (:tenantIds)')
            ->setParameter('tenantIds', $tenantIds)
            ->orderBy('tenant.name', 'ASC')
            ->addOrderBy('quote.sentAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countSentBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('quote')
            ->select('COUNT(quote.id)')
            ->andWhere('quote.sentAt IS NOT NULL')
            ->andWhere('quote.sentAt >= :from')
            ->andWhere('quote.sentAt < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countSentBetweenForTenant(Tenant $tenant, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('quote')
            ->select('COUNT(quote.id)')
            ->andWhere('quote.tenant = :tenant')
            ->andWhere('quote.sentAt IS NOT NULL')
            ->andWhere('quote.sentAt >= :from')
            ->andWhere('quote.sentAt < :to')
            ->setParameter('tenant', $tenant)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAcceptedBetweenForTenant(Tenant $tenant, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('quote')
            ->select('COUNT(quote.id)')
            ->andWhere('quote.tenant = :tenant')
            ->andWhere('quote.acceptedAt IS NOT NULL')
            ->andWhere('quote.acceptedAt >= :from')
            ->andWhere('quote.acceptedAt < :to')
            ->setParameter('tenant', $tenant)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<array{status:string,count:int,totalCents:int}>
     */
    public function summarizePipelineBetweenForTenant(Tenant $tenant, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_map(static fn (array $row): array => [
            'status' => (string) $row['status'],
            'count' => (int) $row['quoteCount'],
            'totalCents' => (int) $row['totalCents'],
        ], $this->createQueryBuilder('quote')
            ->select('quote.status AS status, COUNT(quote.id) AS quoteCount, COALESCE(SUM(quote.totalCents), 0) AS totalCents')
            ->andWhere('quote.tenant = :tenant')
            ->andWhere('(quote.sentAt IS NOT NULL AND quote.sentAt >= :from AND quote.sentAt < :to) OR (quote.sentAt IS NULL AND quote.createdAt >= :from AND quote.createdAt < :to)')
            ->setParameter('tenant', $tenant)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('quote.status')
            ->orderBy('quote.status', 'ASC')
            ->getQuery()
            ->getArrayResult());
    }
}
