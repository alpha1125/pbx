<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Invoice;
use App\Entity\InvoiceLineItem;
use App\Entity\Contact;
use App\Entity\Payment;
use App\Entity\PaymentAllocation;
use App\Entity\Property;
use App\Entity\Tenant;
use App\Repository\InvoiceLineItemRepository;
use App\Repository\PaymentAllocationRepository;
use App\Service\InvoiceAccountingExportPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class InvoiceAccountingExportPayloadBuilderTest extends TestCase
{
    public function testBuildsProviderSpecificPayloads(): void
    {
        $tenant = (new Tenant('Tenant One'))
            ->setQuoteTaxRateBps(1300)
            ->setInvoiceDueDays(21)
            ->setInvoicePaymentInstructions('Pay within 21 days.')
            ->setInvoiceFooter('Footer text');
        $property = new Property($tenant, '10 Test St', 'Toronto', 'ON', 'M1M1M1');
        $contact = (new Contact($tenant, 'Tenant One'))->setPrimaryEmail('billing@example.com')->setPrimaryPhone('+14165550123');
        $invoice = (new Invoice($tenant, $property, 'I-1'))
            ->setContact($contact)
            ->setIssuedAt(new \DateTimeImmutable('2026-06-01'))
            ->setDueAt(new \DateTimeImmutable('2026-06-22'))
            ->setPaymentInstructions('Pay within 21 days.')
            ->setSubtotalCents(10000)
            ->setTaxCents(1300)
            ->setTotalCents(11300)
            ->setAmountPaidCents(2500);

        $lineItem = (new InvoiceLineItem($tenant, $invoice, 'Replace filter'))
            ->setQuantity('2.00')
            ->setUnitPriceCents(5000)
            ->setTotalCents(10000)
            ->setSortOrder(1);
        $payment = (new Payment($tenant, 'P-1'))->setKind(Payment::KIND_RECEIVED)->setAmountCents(2500);
        $allocation = new PaymentAllocation($tenant, $payment, $invoice, 2500);

        $lineItemRepository = $this->createMock(InvoiceLineItemRepository::class);
        $lineItemRepository->expects(self::exactly(2))->method('findByInvoice')->with($invoice)->willReturn([$lineItem]);

        $paymentAllocationRepository = $this->createMock(PaymentAllocationRepository::class);
        $paymentAllocationRepository->expects(self::exactly(2))->method('findByInvoice')->with($invoice)->willReturn([$allocation]);

        $builder = new InvoiceAccountingExportPayloadBuilder($lineItemRepository, $paymentAllocationRepository);

        $quickBooks = $builder->buildQuickBooksOnline($invoice);
        $xero = $builder->buildXero($invoice);

        self::assertSame('quickbooks_online', $quickBooks['provider']);
        self::assertSame('Invoice', $quickBooks['quickBooksOnline']['txnType']);
        self::assertSame('Tenant One', $quickBooks['customer']['name']);
        self::assertSame('Pay within 21 days.', $quickBooks['paymentInstructions']);
        self::assertCount(1, $quickBooks['lineItems']);

        self::assertSame('xero', $xero['provider']);
        self::assertSame('ACCREC', $xero['xero']['type']);
        self::assertSame('Inclusive', $xero['xero']['lineAmountTypes']);
        self::assertSame('Footer text', $xero['footer']);
        self::assertSame(113, $xero['totals']['total']);
    }
}
