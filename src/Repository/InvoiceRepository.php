<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\Property;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Invoice> */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /** @return list<Invoice> */
    public function findByProperty(Property $property): array
    {
        return $this->findBy(['property' => $property], ['updatedAt' => 'DESC']);
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?Invoice
    {
        return $this->findOneBy(['tenant' => $tenant, 'id' => $id]);
    }

    /** @return list<Invoice> */
    public function findOpenByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenant')
            ->andWhere('i.status NOT IN (:closedStatuses)')
            ->setParameter('tenant', $tenant)
            ->setParameter('closedStatuses', [Invoice::STATUS_PAID, Invoice::STATUS_REFUNDED, Invoice::STATUS_VOID])
            ->orderBy('i.dueAt', 'ASC')
            ->addOrderBy('i.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Invoice> */
    public function findAgingByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenant')
            ->andWhere('i.status NOT IN (:closedStatuses)')
            ->andWhere('i.totalCents > i.amountPaidCents')
            ->setParameter('tenant', $tenant)
            ->setParameter('closedStatuses', [Invoice::STATUS_PAID, Invoice::STATUS_REFUNDED, Invoice::STATUS_VOID])
            ->orderBy('i.dueAt', 'ASC')
            ->addOrderBy('i.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<int> $propertyIds
     * @return list<array{propertyId:int,invoiceCount:int,totalCents:int}>
     */
    public function summarizeOpenBalancesByTenantAndPropertyIds(Tenant $tenant, array $propertyIds = []): array
    {
        $qb = $this->createQueryBuilder('invoice')
            ->select('IDENTITY(invoice.property) AS propertyId, COUNT(invoice.id) AS invoiceCount, COALESCE(SUM(invoice.totalCents - invoice.amountPaidCents), 0) AS totalCents')
            ->andWhere('invoice.tenant = :tenant')
            ->andWhere('invoice.status NOT IN (:closedStatuses)')
            ->andWhere('invoice.totalCents > invoice.amountPaidCents')
            ->setParameter('tenant', $tenant)
            ->setParameter('closedStatuses', [Invoice::STATUS_PAID, Invoice::STATUS_REFUNDED, Invoice::STATUS_VOID])
            ->groupBy('invoice.property')
            ->orderBy('totalCents', 'DESC');

        if ([] !== $propertyIds) {
            $qb->andWhere($qb->expr()->in('invoice.property', ':propertyIds'))
                ->setParameter('propertyIds', array_values(array_unique(array_map('intval', $propertyIds))));
        }

        return array_map(static fn (array $row): array => [
            'propertyId' => (int) $row['propertyId'],
            'invoiceCount' => (int) $row['invoiceCount'],
            'totalCents' => (int) $row['totalCents'],
        ], $qb->getQuery()->getArrayResult());
    }
}
