<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Estimate;
use App\Entity\EstimateLineItem;
use App\Repository\EstimateLineItemRepository;
use Doctrine\ORM\EntityManagerInterface;

final class EstimateDuplicationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EstimateLineItemRepository $estimateLineItemRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function duplicate(Estimate $estimate): Estimate
    {
        return $this->entityManager->wrapInTransaction(function () use ($estimate): Estimate {
            $duplicate = (new Estimate($estimate->getTenant(), $estimate->getProperty()))
                ->setContact($estimate->getContact())
                ->setRfqInvitation($estimate->getRfqInvitation())
                ->setTitle($estimate->getTitle())
                ->setNotes($estimate->getNotes())
                ->setExclusions($estimate->getExclusions())
                ->setAssumptions($estimate->getAssumptions())
                ->setStatus(Estimate::STATUS_DRAFT)
                ->setSubtotalCents($estimate->getSubtotalCents())
                ->setTaxCents($estimate->getTaxCents())
                ->setTotalCents($estimate->getTotalCents());
            $this->entityManager->persist($duplicate);

            foreach ($this->estimateLineItemRepository->findByEstimate($estimate) as $lineItem) {
                $duplicateLineItem = (new EstimateLineItem($estimate->getTenant(), $duplicate, $lineItem->getDescription()))
                    ->setSectionLabel($lineItem->getSectionLabel())
                    ->setQuantity($lineItem->getQuantity())
                    ->setUnitPriceCents($lineItem->getUnitPriceCents())
                    ->setTotalCents($lineItem->getTotalCents())
                    ->setSortOrder($lineItem->getSortOrder());
                $this->entityManager->persist($duplicateLineItem);
            }

            $this->entityManager->flush();
            $this->auditLogger->log(
                $estimate->getTenant(),
                'estimate',
                (string) $estimate->getId(),
                'estimate.duplicated',
                null,
                ['duplicateEstimateId' => $duplicate->getId()],
                ['propertyId' => $estimate->getProperty()->getId()],
            );
            $this->entityManager->flush();

            return $duplicate;
        });
    }
}
