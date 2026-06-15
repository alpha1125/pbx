<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Estimate;
use App\Entity\Quote;
use App\Entity\QuoteLineItem;
use App\Repository\EstimateLineItemRepository;
use Doctrine\ORM\EntityManagerInterface;

class EstimateToQuoteService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EstimateLineItemRepository $estimateLineItemRepository,
        private readonly DocumentNumberGenerator $documentNumberGenerator,
        private readonly MoneyCalculator $moneyCalculator,
        private readonly AuditLogger $auditLogger,
        private readonly CommunicationTimelineProjector $timelineProjector,
    ) {
    }

    public function convert(Estimate $estimate): Quote
    {
        return $this->entityManager->wrapInTransaction(function () use ($estimate): Quote {
            if (!in_array($estimate->getStatus(), [Estimate::STATUS_DRAFT, Estimate::STATUS_IN_REVIEW, Estimate::STATUS_APPROVED_FOR_QUOTE], true)) {
                throw new \RuntimeException(sprintf('Estimate %d cannot be converted from status "%s".', $estimate->getId(), $estimate->getStatus()));
            }

            $lineItems = $this->estimateLineItemRepository->findByEstimate($estimate);
            $quote = (new Quote(
                $estimate->getTenant(),
                $estimate->getProperty(),
                $this->documentNumberGenerator->generateQuoteNumber($estimate->getTenant()),
            ))
                ->setContact($estimate->getContact())
                ->setEstimate($estimate)
                ->setRevisionNumber(1)
                ->setSubtotalCents(0)
                ->setTaxCents(0)
                ->setTotalCents(0)
                ->setDiscountCents(0)
                ->setDepositCents(0);
            $this->entityManager->persist($quote);

            foreach ($lineItems as $lineItem) {
                $quoteLineItem = (new QuoteLineItem($estimate->getTenant(), $quote, $lineItem->getDescription()))
                    ->setSectionLabel($lineItem->getSectionLabel())
                    ->setQuantity($lineItem->getQuantity())
                    ->setUnitPriceCents($lineItem->getUnitPriceCents())
                    ->setTotalCents($lineItem->getTotalCents())
                    ->setSortOrder($lineItem->getSortOrder());
                $this->entityManager->persist($quoteLineItem);
            }

            $computedTotals = $this->moneyCalculator->summarize($lineItems, $estimate->getTenant());
            $quote
                ->setSubtotalCents($computedTotals['subtotalCents'])
                ->setTaxCents($computedTotals['taxCents'])
                ->setTotalCents($computedTotals['totalCents']);

            $before = ['status' => $estimate->getStatus()];
            $estimate->setStatus(Estimate::STATUS_CONVERTED_TO_QUOTE)->touch();

            $this->auditLogger->log(
                $estimate->getTenant(),
                'estimate',
                (string) $estimate->getId(),
                'estimate.converted_to_quote',
                $before,
                ['status' => $estimate->getStatus()],
                ['quoteNumber' => $quote->getQuoteNumber(), 'propertyId' => $estimate->getProperty()->getId()],
            );

            $this->auditLogger->log(
                $estimate->getTenant(),
                'quote',
                $quote->getQuoteNumber(),
                'quote.created_from_estimate',
                null,
                ['status' => $quote->getStatus(), 'quoteNumber' => $quote->getQuoteNumber()],
                ['estimateId' => $estimate->getId(), 'propertyId' => $estimate->getProperty()->getId()],
            );

            $this->entityManager->flush();
            $this->timelineProjector->recordQuoteEvent(
                $quote,
                'quote.created_from_estimate',
                'Quote created from estimate.',
                ['estimateId' => $estimate->getId(), 'propertyId' => $estimate->getProperty()->getId()],
            );

            return $quote;
        });
    }
}
