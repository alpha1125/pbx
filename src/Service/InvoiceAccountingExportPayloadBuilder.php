<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\InvoiceAccountingSyncRecord;
use App\Entity\Payment;
use App\Repository\InvoiceLineItemRepository;
use App\Repository\PaymentAllocationRepository;

final class InvoiceAccountingExportPayloadBuilder
{
    public function __construct(
        private readonly InvoiceLineItemRepository $invoiceLineItemRepository,
        private readonly PaymentAllocationRepository $paymentAllocationRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildQuickBooksOnline(Invoice $invoice): array
    {
        return $this->buildCommon($invoice, InvoiceAccountingSyncRecord::PROVIDER_QUICKBOOKS_ONLINE) + [
            'quickBooksOnline' => [
                'txnType' => 'Invoice',
                'customerRef' => $this->customerRef($invoice),
                'salesTaxCode' => $this->taxCode($invoice),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildXero(Invoice $invoice): array
    {
        return $this->buildCommon($invoice, InvoiceAccountingSyncRecord::PROVIDER_XERO) + [
            'xero' => [
                'type' => 'ACCREC',
                'contact' => $this->customerRef($invoice),
                'lineAmountTypes' => $invoice->getTenant()->getQuoteTaxRateBps() > 0 ? 'Inclusive' : 'Exclusive',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCommon(Invoice $invoice, string $provider): array
    {
        $lineItems = [];
        foreach ($this->invoiceLineItemRepository->findByInvoice($invoice) as $lineItem) {
            $lineItems[] = [
                'description' => $lineItem->getDescription(),
                'section' => $lineItem->getSectionLabel(),
                'quantity' => $lineItem->getQuantity(),
                'unitAmount' => $lineItem->getUnitPriceCents() / 100,
                'lineAmount' => $lineItem->getTotalCents() / 100,
                'sortOrder' => $lineItem->getSortOrder(),
            ];
        }

        $receivedCents = 0;
        $refundedCents = 0;
        foreach ($this->paymentAllocationRepository->findByInvoice($invoice) as $allocation) {
            $amount = $allocation->getAmountCents();
            if (Payment::KIND_REFUND === $allocation->getPayment()->getKind()) {
                $refundedCents += $amount;
                continue;
            }

            $receivedCents += $amount;
        }

        return [
            'provider' => $provider,
            'invoiceNumber' => $invoice->getInvoiceNumber(),
            'status' => $invoice->getStatus(),
            'issueDate' => $invoice->getIssuedAt()?->format('Y-m-d'),
            'dueDate' => $invoice->getDueAt()?->format('Y-m-d'),
            'balanceCents' => $invoice->getBalanceCents(),
            'totals' => [
                'subtotal' => $invoice->getSubtotalCents() / 100,
                'tax' => $invoice->getTaxCents() / 100,
                'total' => $invoice->getTotalCents() / 100,
                'paid' => $receivedCents / 100,
                'refunded' => $refundedCents / 100,
            ],
            'customer' => [
                'name' => $invoice->getContact()?->getDisplayName() ?? $invoice->getProperty()->getDisplayAddress(),
                'email' => $invoice->getContact()?->getPrimaryEmail(),
                'phone' => $invoice->getContact()?->getPrimaryPhone(),
            ],
            'property' => [
                'id' => $invoice->getProperty()->getId(),
                'address' => $invoice->getProperty()->getDisplayAddress(),
            ],
            'paymentInstructions' => $invoice->getPaymentInstructions(),
            'footer' => $invoice->getTenant()->getInvoiceFooter(),
            'lineItems' => $lineItems,
        ];
    }

    private function customerRef(Invoice $invoice): string
    {
        return $invoice->getContact()?->getDisplayName() ?? $invoice->getProperty()->getDisplayAddress();
    }

    private function taxCode(Invoice $invoice): string
    {
        return $invoice->getTenant()->getQuoteTaxRateBps() > 0 ? 'TAX' : 'NON';
    }
}
