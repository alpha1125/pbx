<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\InvoiceLineItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InvoiceLineItem> */
class InvoiceLineItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceLineItem::class);
    }

    /** @return list<InvoiceLineItem> */
    public function findByInvoice(Invoice $invoice): array
    {
        return $this->findBy(['invoice' => $invoice], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }
}
