<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\InvoiceAccountingSyncRecord;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InvoiceAccountingSyncRecord> */
class InvoiceAccountingSyncRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceAccountingSyncRecord::class);
    }

    /** @return list<InvoiceAccountingSyncRecord> */
    public function findByInvoice(Invoice $invoice): array
    {
        return $this->findBy(['invoice' => $invoice], ['provider' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneByInvoiceAndProvider(Invoice $invoice, string $provider): ?InvoiceAccountingSyncRecord
    {
        return $this->findOneBy(['invoice' => $invoice, 'provider' => $provider]);
    }

    /** @return list<InvoiceAccountingSyncRecord> */
    public function findRetryDueByTenant(Tenant $tenant, \DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.invoice', 'invoice')
            ->andWhere('invoice.tenant = :tenant')
            ->andWhere('r.status = :status')
            ->andWhere('r.nextRetryAt IS NOT NULL')
            ->andWhere('r.nextRetryAt <= :now')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', InvoiceAccountingSyncRecord::STATUS_RETRY_SCHEDULED)
            ->setParameter('now', $now)
            ->orderBy('r.nextRetryAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
