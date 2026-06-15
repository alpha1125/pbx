<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MoneyCalculator;
use App\Entity\Tenant;
use PHPUnit\Framework\TestCase;

final class MoneyCalculatorTest extends TestCase
{
    public function testCalculateLineTotalCentsUsesQuantityAndUnitPrice(): void
    {
        $calculator = new MoneyCalculator();

        self::assertSame(3750, $calculator->calculateLineTotalCents('2.50', 1500));
    }

    public function testSummarizeReturnsSubtotalAsTotalWhenTaxIsZero(): void
    {
        $calculator = new MoneyCalculator();

        $lineItems = [
            new class() {
                public function getTotalCents(): int { return 1000; }
            },
            new class() {
                public function getTotalCents(): int { return 2500; }
            },
        ];

        self::assertSame([
            'subtotalCents' => 3500,
            'taxCents' => 0,
            'totalCents' => 3500,
        ], $calculator->summarize($lineItems));
    }

    public function testSummarizeAppliesTenantTaxRateAndDiscount(): void
    {
        $calculator = new MoneyCalculator();
        $tenant = (new Tenant('Taxed'))->setQuoteTaxRateBps(850);

        $lineItems = [
            new class() {
                public function getTotalCents(): int { return 10000; }
            },
        ];

        self::assertSame([
            'subtotalCents' => 10000,
            'taxCents' => 850,
            'totalCents' => 10850,
        ], $calculator->summarize($lineItems, $tenant));
    }
}
