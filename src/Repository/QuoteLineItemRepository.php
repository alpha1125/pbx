<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Quote;
use App\Entity\QuoteLineItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<QuoteLineItem> */
class QuoteLineItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuoteLineItem::class);
    }

    /** @return list<QuoteLineItem> */
    public function findByQuote(Quote $quote): array
    {
        return $this->findBy(['quote' => $quote], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }
}
