<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\InvoiceLineItem;
use App\Entity\Quote;
use App\Repository\QuoteLineItemRepository;
use App\Service\CommunicationTimelineProjector;
use Doctrine\ORM\EntityManagerInterface;

class QuoteToInvoiceService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QuoteLineItemRepository $quoteLineItemRepository,
        private readonly DocumentNumberGenerator $documentNumberGenerator,
        private readonly AuditLogger $auditLogger,
        private readonly InvoiceTimelineProjectorInterface $timelineProjector,
    ) {
    }

    public function convert(Quote $quote): Invoice
    {
        return $this->entityManager->wrapInTransaction(function () use ($quote): Invoice {
            if (Quote::STATUS_ACCEPTED !== $quote->getStatus()) {
                throw new \RuntimeException(sprintf('Quote %s must be accepted before converting to an invoice.', $quote->getQuoteNumber()));
            }

            $invoice = (new Invoice(
                $quote->getTenant(),
                $quote->getProperty(),
                $this->documentNumberGenerator->generateInvoiceNumber($quote->getTenant()),
            ))
                ->setContact($quote->getContact())
                ->setQuote($quote)
                ->setIssuedAt(new \DateTimeImmutable('today'))
                ->setDueAt(new \DateTimeImmutable(sprintf('+%d days', $quote->getTenant()->getInvoiceDueDays())))
                ->setStatus(Invoice::STATUS_UNPAID)
                ->setSubtotalCents($quote->getSubtotalCents())
                ->setTaxCents($quote->getTaxCents())
                ->setTotalCents($quote->getTotalCents())
                ->setAmountPaidCents(0)
                ->setPaymentInstructions($quote->getTenant()->getInvoicePaymentInstructions());
            $this->entityManager->persist($invoice);

            foreach ($this->quoteLineItemRepository->findByQuote($quote) as $lineItem) {
                $invoiceLineItem = (new InvoiceLineItem($quote->getTenant(), $invoice, $lineItem->getDescription()))
                    ->setSectionLabel($lineItem->getSectionLabel())
                    ->setQuantity($lineItem->getQuantity())
                    ->setUnitPriceCents($lineItem->getUnitPriceCents())
                    ->setTotalCents($lineItem->getTotalCents())
                    ->setSortOrder($lineItem->getSortOrder());
                $this->entityManager->persist($invoiceLineItem);
            }

            $this->auditLogger->log(
                $quote->getTenant(),
                'invoice',
                $invoice->getInvoiceNumber(),
                'invoice.created_from_quote',
                null,
                ['status' => $invoice->getStatus(), 'invoiceNumber' => $invoice->getInvoiceNumber()],
                ['quoteId' => $quote->getId(), 'propertyId' => $quote->getProperty()->getId()],
            );

            $this->entityManager->flush();
            $this->timelineProjector->recordInvoiceEvent(
                $invoice,
                'invoice.created_from_quote',
                'Invoice created from quote.',
                ['quoteId' => $quote->getId(), 'propertyId' => $quote->getProperty()->getId()],
            );

            return $invoice;
        });
    }
}
