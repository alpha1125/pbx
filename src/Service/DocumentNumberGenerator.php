<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;

class DocumentNumberGenerator
{
    public function generateQuoteNumber(Tenant $tenant): string
    {
        return sprintf('Q-%d-%s', $tenant->getId() ?? 0, strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)));
    }

    public function generateInvoiceNumber(Tenant $tenant): string
    {
        return sprintf('I-%d-%s', $tenant->getId() ?? 0, strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)));
    }
}
