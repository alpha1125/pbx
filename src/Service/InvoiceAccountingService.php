<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\Payment;
use App\Repository\InvoiceLineItemRepository;
use App\Repository\PaymentAllocationRepository;

final class InvoiceAccountingService
{
    public function __construct(
        private readonly InvoiceLineItemRepository $invoiceLineItemRepository,
        private readonly PaymentAllocationRepository $paymentAllocationRepository,
        private readonly MoneyCalculator $moneyCalculator,
    ) {
    }

    public function refresh(Invoice $invoice): void
    {
        if (Invoice::STATUS_VOID === $invoice->getStatus()) {
            return;
        }

        $lineItems = $this->invoiceLineItemRepository->findByInvoice($invoice);
        $totals = $this->moneyCalculator->summarize($lineItems, $invoice->getTenant());
        $allocations = $this->paymentAllocationRepository->findByInvoice($invoice);

        $receivedCents = 0;
        $refundedCents = 0;
        foreach ($allocations as $allocation) {
            $amount = $allocation->getAmountCents();
            if (Payment::KIND_REFUND === $allocation->getPayment()->getKind()) {
                $refundedCents += $amount;
                continue;
            }

            $receivedCents += $amount;
        }

        $netPaidCents = max(0, $receivedCents - $refundedCents);
        $invoice
            ->setSubtotalCents($totals['subtotalCents'])
            ->setTaxCents($totals['taxCents'])
            ->setTotalCents($totals['totalCents'])
            ->setAmountPaidCents($netPaidCents);

        if ($totals['totalCents'] <= 0) {
            $invoice->setStatus(Invoice::STATUS_PAID);
            return;
        }

        if ($refundedCents > 0 && 0 === $netPaidCents) {
            $invoice->setStatus(Invoice::STATUS_REFUNDED);
            return;
        }

        if ($netPaidCents >= $totals['totalCents']) {
            $invoice->setStatus(Invoice::STATUS_PAID);
            return;
        }

        if ($netPaidCents > 0) {
            $invoice->setStatus(Invoice::STATUS_PARTIALLY_PAID);
        } elseif (in_array($invoice->getStatus(), [Invoice::STATUS_PAID, Invoice::STATUS_PARTIALLY_PAID, Invoice::STATUS_REFUNDED, Invoice::STATUS_OVERDUE], true)) {
            $invoice->setStatus(Invoice::STATUS_UNPAID);
        }

        if (null !== $invoice->getDueAt() && $invoice->getDueAt() < new \DateTimeImmutable('today') && $invoice->getBalanceCents() > 0) {
            $invoice->setStatus(Invoice::STATUS_OVERDUE);
        }
    }
}
