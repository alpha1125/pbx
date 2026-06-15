<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;

class MoneyCalculator
{
    public function calculateLineTotalCents(string $quantity, int $unitPriceCents): int
    {
        return (int) round(((float) $quantity) * $unitPriceCents);
    }

    /**
     * @param iterable<object> $lineItems
     *
     * @return array{subtotalCents:int,taxCents:int,totalCents:int}
     */
    public function summarize(iterable $lineItems, ?Tenant $tenant = null, int $discountCents = 0): array
    {
        $subtotal = 0;
        foreach ($lineItems as $lineItem) {
            $subtotal += method_exists($lineItem, 'getTotalCents') ? (int) $lineItem->getTotalCents() : 0;
        }

        $discountCents = max(0, min($discountCents, $subtotal));
        $taxRateBps = $tenant?->getQuoteTaxRateBps() ?? 0;
        $taxableSubtotal = max(0, $subtotal - $discountCents);
        $taxCents = (int) round($taxableSubtotal * $taxRateBps / 10000);

        return [
            'subtotalCents' => $subtotal,
            'taxCents' => $taxCents,
            'totalCents' => $taxableSubtotal + $taxCents,
        ];
    }
}
