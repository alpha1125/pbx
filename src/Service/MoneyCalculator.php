<?php

declare(strict_types=1);

namespace App\Service;

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
    public function summarize(iterable $lineItems): array
    {
        $subtotal = 0;
        foreach ($lineItems as $lineItem) {
            $subtotal += method_exists($lineItem, 'getTotalCents') ? (int) $lineItem->getTotalCents() : 0;
        }

        return [
            'subtotalCents' => $subtotal,
            'taxCents' => 0,
            'totalCents' => $subtotal,
        ];
    }
}
