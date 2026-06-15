<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Quote;
use App\Entity\QuoteLineItem;
use App\Repository\QuoteLineItemRepository;
use Doctrine\ORM\EntityManagerInterface;

final class QuoteRevisionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QuoteLineItemRepository $quoteLineItemRepository,
        private readonly DocumentNumberGenerator $documentNumberGenerator,
        private readonly AuditLogger $auditLogger,
        private readonly CommunicationTimelineProjector $timelineProjector,
    ) {
    }

    public function revise(Quote $quote): Quote
    {
        return $this->entityManager->wrapInTransaction(function () use ($quote): Quote {
            $root = $quote->getRootQuote() ?? $quote;
            $revisionNumber = $quote->getRevisionNumber() + 1;

            $revision = (new Quote(
                $quote->getTenant(),
                $quote->getProperty(),
                $this->documentNumberGenerator->generateQuoteNumber($quote->getTenant(), $revisionNumber),
            ))
                ->setContact($quote->getContact())
                ->setEstimate($quote->getEstimate())
                ->setParentQuote($quote)
                ->setRootQuote($root)
                ->setRevisionNumber($revisionNumber)
                ->setStatus(Quote::STATUS_DRAFT)
                ->setValidUntil($quote->getValidUntil())
                ->setSubtotalCents($quote->getSubtotalCents())
                ->setTaxCents($quote->getTaxCents())
                ->setTotalCents($quote->getTotalCents())
                ->setDiscountCents($quote->getDiscountCents())
                ->setDepositCents($quote->getDepositCents())
                ->setFinancingNotes($quote->getFinancingNotes());
            $this->entityManager->persist($revision);

            foreach ($this->quoteLineItemRepository->findByQuote($quote) as $lineItem) {
                $revisionLineItem = (new QuoteLineItem($quote->getTenant(), $revision, $lineItem->getDescription()))
                    ->setSectionLabel($lineItem->getSectionLabel())
                    ->setQuantity($lineItem->getQuantity())
                    ->setUnitPriceCents($lineItem->getUnitPriceCents())
                    ->setTotalCents($lineItem->getTotalCents())
                    ->setSortOrder($lineItem->getSortOrder());
                $this->entityManager->persist($revisionLineItem);
            }

            $before = ['status' => $quote->getStatus()];
            $quote->setStatus(Quote::STATUS_SUPERSEDED)->touch();
            $this->auditLogger->log(
                $quote->getTenant(),
                'quote',
                $quote->getQuoteNumber(),
                'quote.revised',
                $before,
                ['status' => Quote::STATUS_SUPERSEDED, 'revisionNumber' => $quote->getRevisionNumber()],
                ['propertyId' => $quote->getProperty()->getId(), 'revisionNumber' => $revisionNumber],
            );

            $this->entityManager->flush();
            $this->timelineProjector->recordQuoteEvent(
                $revision,
                'quote.revised',
                'Quote revision created.',
                ['parentQuoteNumber' => $quote->getQuoteNumber(), 'propertyId' => $quote->getProperty()->getId()],
            );

            return $revision;
        });
    }
}
