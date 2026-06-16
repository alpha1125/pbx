<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Invoice;
use App\Entity\InvoiceLineItem;
use App\Entity\Payment;
use App\Entity\PaymentAllocation;
use App\Entity\Property;
use App\Entity\Tenant;
use App\Repository\InvoiceLineItemRepository;
use App\Repository\PaymentAllocationRepository;
use App\Service\InvoiceAccountingService;
use App\Service\MoneyCalculator;
use PHPUnit\Framework\TestCase;

final class InvoiceAccountingServiceTest extends TestCase
{
    public function testRefreshMarksInvoicePartiallyPaid(): void
    {
        $tenant = new Tenant('Billing Tenant');
        $property = new Property($tenant, '1 Billing Way', 'Toronto', 'ON', 'M1M1M1');
        $invoice = new Invoice($tenant, $property, 'I-1');

        $lineItems = [
            (new InvoiceLineItem($tenant, $invoice, 'Repair'))
                ->setQuantity('1.00')
                ->setUnitPriceCents(10000)
                ->setTotalCents(10000),
        ];
        $payment = (new Payment($tenant, 'P-1'))->setKind(Payment::KIND_RECEIVED)->setAmountCents(2500);
        $allocations = [
            new PaymentAllocation($tenant, $payment, $invoice, 2500),
        ];

        $service = $this->buildService($lineItems, $allocations);
        $service->refresh($invoice);

        self::assertSame(10000, $invoice->getSubtotalCents());
        self::assertSame(0, $invoice->getTaxCents());
        self::assertSame(10000, $invoice->getTotalCents());
        self::assertSame(2500, $invoice->getAmountPaidCents());
        self::assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->getStatus());
        self::assertSame(7500, $invoice->getBalanceCents());
    }

    public function testRefreshMarksRefundedWhenRefundOffsetsPayment(): void
    {
        $tenant = new Tenant('Billing Tenant');
        $property = new Property($tenant, '1 Billing Way', 'Toronto', 'ON', 'M1M1M1');
        $invoice = new Invoice($tenant, $property, 'I-2');

        $lineItems = [
            (new InvoiceLineItem($tenant, $invoice, 'Repair'))
                ->setQuantity('1.00')
                ->setUnitPriceCents(10000)
                ->setTotalCents(10000),
        ];
        $received = (new Payment($tenant, 'P-2'))->setKind(Payment::KIND_RECEIVED)->setAmountCents(10000);
        $refund = (new Payment($tenant, 'P-3'))->setKind(Payment::KIND_REFUND)->setAmountCents(10000);
        $allocations = [
            new PaymentAllocation($tenant, $received, $invoice, 10000),
            new PaymentAllocation($tenant, $refund, $invoice, 10000),
        ];

        $service = $this->buildService($lineItems, $allocations);
        $service->refresh($invoice);

        self::assertSame(0, $invoice->getAmountPaidCents());
        self::assertSame(Invoice::STATUS_REFUNDED, $invoice->getStatus());
        self::assertSame(0, $invoice->getBalanceCents());
    }

    public function testRefreshMarksPastDuePartialPaymentsOverdue(): void
    {
        $tenant = new Tenant('Billing Tenant');
        $property = new Property($tenant, '1 Billing Way', 'Toronto', 'ON', 'M1M1M1');
        $invoice = (new Invoice($tenant, $property, 'I-3'))
            ->setDueAt(new \DateTimeImmutable('yesterday'));

        $lineItems = [
            (new InvoiceLineItem($tenant, $invoice, 'Repair'))
                ->setQuantity('1.00')
                ->setUnitPriceCents(10000)
                ->setTotalCents(10000),
        ];
        $payment = (new Payment($tenant, 'P-4'))->setKind(Payment::KIND_RECEIVED)->setAmountCents(2500);
        $allocations = [
            new PaymentAllocation($tenant, $payment, $invoice, 2500),
        ];

        $service = $this->buildService($lineItems, $allocations);
        $service->refresh($invoice);

        self::assertSame(2500, $invoice->getAmountPaidCents());
        self::assertSame(Invoice::STATUS_OVERDUE, $invoice->getStatus());
        self::assertSame(7500, $invoice->getBalanceCents());
    }

    /**
     * @param list<object> $lineItems
     * @param list<PaymentAllocation> $allocations
     */
    private function buildService(array $lineItems, array $allocations): InvoiceAccountingService
    {
        $invoiceLineItemRepository = $this->createStub(InvoiceLineItemRepository::class);
        $invoiceLineItemRepository->method('findByInvoice')->willReturn($lineItems);

        $paymentAllocationRepository = $this->createStub(PaymentAllocationRepository::class);
        $paymentAllocationRepository->method('findByInvoice')->willReturn($allocations);

        return new InvoiceAccountingService(
            $invoiceLineItemRepository,
            $paymentAllocationRepository,
            new MoneyCalculator(),
        );
    }
}
