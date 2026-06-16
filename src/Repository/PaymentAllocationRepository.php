<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\PaymentAllocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PaymentAllocation> */
class PaymentAllocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentAllocation::class);
    }

    /** @return list<PaymentAllocation> */
    public function findByInvoice(Invoice $invoice): array
    {
        return $this->findBy(['invoice' => $invoice], ['allocatedAt' => 'ASC', 'id' => 'ASC']);
    }
}
