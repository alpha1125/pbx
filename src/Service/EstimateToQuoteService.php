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
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function convert(Estimate $estimate): Quote
    {
        return $this->entityManager->wrapInTransaction(function () use ($estimate): Quote {
            if (!in_array($estimate->getStatus(), [Estimate::STATUS_DRAFT, Estimate::STATUS_IN_REVIEW, Estimate::STATUS_APPROVED_FOR_QUOTE], true)) {
                throw new \RuntimeException(sprintf('Estimate %d cannot be converted from status "%s".', $estimate->getId(), $estimate->getStatus()));
            }

            $quote = (new Quote(
                $estimate->getTenant(),
                $estimate->getProperty(),
                $this->documentNumberGenerator->generateQuoteNumber($estimate->getTenant()),
            ))
                ->setContact($estimate->getContact())
                ->setEstimate($estimate)
                ->setSubtotalCents($estimate->getSubtotalCents())
                ->setTaxCents($estimate->getTaxCents())
                ->setTotalCents($estimate->getTotalCents());
            $this->entityManager->persist($quote);

            foreach ($this->estimateLineItemRepository->findByEstimate($estimate) as $lineItem) {
                $quoteLineItem = (new QuoteLineItem($estimate->getTenant(), $quote, $lineItem->getDescription()))
                    ->setQuantity($lineItem->getQuantity())
                    ->setUnitPriceCents($lineItem->getUnitPriceCents())
                    ->setTotalCents($lineItem->getTotalCents())
                    ->setSortOrder($lineItem->getSortOrder());
                $this->entityManager->persist($quoteLineItem);
            }

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

            return $quote;
        });
    }
}
