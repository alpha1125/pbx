<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Repository\QuoteRepository;

class DocumentNumberGenerator
{
    public function __construct(
        private readonly QuoteRepository $quoteRepository,
    ) {
    }

    public function generateQuoteNumber(Tenant $tenant, int $revisionNumber = 1): string
    {
        $sequence = $this->quoteRepository->countByTenant($tenant) + 1;
        $base = sprintf('Q-%d-%05d', $tenant->getId() ?? 0, $sequence);
        if ($revisionNumber > 1) {
            $base .= sprintf('-R%d', $revisionNumber);
        }

        return $base;
    }

    public function generateInvoiceNumber(Tenant $tenant): string
    {
        return sprintf('I-%d-%s', $tenant->getId() ?? 0, strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)));
    }
}
