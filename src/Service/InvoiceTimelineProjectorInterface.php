<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;

interface InvoiceTimelineProjectorInterface
{
    public function recordInvoiceEvent(Invoice $invoice, string $action, ?string $bodyText = null, ?array $metadata = null): void;
}
