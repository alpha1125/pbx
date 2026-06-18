<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\Estimate;
use App\Entity\EstimateLineItem;
use App\Repository\EstimateLineItemRepository;
use App\Repository\EstimateRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\AuditLogger;
use App\Service\CrmSuggestionService;
use App\Service\EstimateDuplicationService;
use App\Service\CurrentTenantProviderInterface;
use App\Service\EstimateToQuoteService;
use App\Service\CommunicationTimelineProjector;
use App\Service\MoneyCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EstimateController extends AbstractController
{
    #[Route('/crm/estimates/{id<\d+>}', name: 'crm_estimate_show', methods: ['GET'])]
    public function show(
        int $id,
        CurrentTenantProviderInterface $tenantProvider,
        EstimateRepository $estimateRepository,
        EstimateLineItemRepository $estimateLineItemRepository,
        CommunicationTimelineProjector $timelineProjector,
        CrmSuggestionService $suggestionService,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $estimate = $estimateRepository->findOneByTenantAndId($tenant, $id);
        if (null === $estimate) {
            throw $this->createNotFoundException('Estimate not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $estimate);
        $timelineProjector->syncProperty($estimate->getProperty());

        return $this->render('crm/estimate/show.html.twig', [
            'tenant' => $tenant,
            'estimate' => $estimate,
            'lineItems' => $estimateLineItemRepository->findByEstimate($estimate),
            'suggestions' => $suggestionService->buildForEstimate($estimate),
        ]);
    }

    #[Route('/crm/estimates/{id<\d+>}/update', name: 'crm_estimate_update', methods: ['POST'])]
    public function update(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        EstimateRepository $estimateRepository,
        EstimateLineItemRepository $estimateLineItemRepository,
        MoneyCalculator $moneyCalculator,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $estimate = $estimateRepository->findOneByTenantAndId($tenant, $id);
        if (null === $estimate) {
            throw $this->createNotFoundException('Estimate not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $estimate);

        if (!$this->isCsrfTokenValid('estimate_update_'.$estimate->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $estimate
            ->setTitle(trim((string) $request->request->get('title', '')) ?: null)
            ->setNotes(trim((string) $request->request->get('notes', '')) ?: null)
            ->setExclusions(trim((string) $request->request->get('exclusions', '')) ?: null)
            ->setAssumptions(trim((string) $request->request->get('assumptions', '')) ?: null)
            ->touch();

        $this->recalculateEstimateTotals($estimate, $estimateLineItemRepository, $moneyCalculator);
        $auditLogger->log($tenant, 'estimate', (string) $estimate->getId(), 'estimate.updated', null, [
            'title' => $estimate->getTitle(),
        ], ['propertyId' => $estimate->getProperty()->getId()]);
        $entityManager->flush();

        return $this->redirectToRoute('crm_estimate_show', ['id' => $estimate->getId()]);
    }

    #[Route('/crm/estimates/{id<\d+>}/line-items', name: 'crm_estimate_add_line_item', methods: ['POST'])]
    public function addLineItem(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        EstimateRepository $estimateRepository,
        EstimateLineItemRepository $estimateLineItemRepository,
        MoneyCalculator $moneyCalculator,
        AuditLogger $auditLogger,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $estimate = $estimateRepository->findOneByTenantAndId($tenant, $id);
        if (null === $estimate) {
            throw $this->createNotFoundException('Estimate not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $estimate);

        if (!$this->isCsrfTokenValid('estimate_line_item_'.$estimate->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $description = trim((string) $request->request->get('description'));
        if ('' === $description) {
            throw $this->createAccessDeniedException('Description is required.');
        }

        $quantity = $this->normalizeQuantity((string) $request->request->get('quantity', '1'));
        $sectionLabel = trim((string) $request->request->get('sectionLabel', ''));
        $unitPriceCents = $this->parseMoneyToCents((string) $request->request->get('unitPrice'));
        $sortOrder = count($estimateLineItemRepository->findByEstimate($estimate)) + 1;

        $lineItem = (new EstimateLineItem($tenant, $estimate, $description))
            ->setSectionLabel('' !== $sectionLabel ? $sectionLabel : null)
            ->setQuantity($quantity)
            ->setUnitPriceCents($unitPriceCents)
            ->setTotalCents($moneyCalculator->calculateLineTotalCents($quantity, $unitPriceCents))
            ->setSortOrder($sortOrder);

        $entityManager->persist($lineItem);
        $entityManager->flush();

        $auditLogger->log(
            $tenant,
            'estimate',
            (string) $estimate->getId(),
            'estimate.line_item_added',
            null,
            ['description' => $lineItem->getDescription(), 'totalCents' => $lineItem->getTotalCents()],
            ['propertyId' => $estimate->getProperty()->getId()],
        );

        $entityManager->flush();
        $this->recalculateEstimateTotals($estimate, $estimateLineItemRepository, $moneyCalculator);
        $entityManager->flush();

        return $this->redirectToRoute('crm_estimate_show', ['id' => $estimate->getId()]);
    }

    #[Route('/crm/estimates/{estimateId<\d+>}/line-items/{lineItemId<\d+>}', name: 'crm_estimate_update_line_item', methods: ['POST'])]
    public function updateLineItem(
        int $estimateId,
        int $lineItemId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        EstimateRepository $estimateRepository,
        EstimateLineItemRepository $estimateLineItemRepository,
        MoneyCalculator $moneyCalculator,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $estimate = $estimateRepository->findOneByTenantAndId($tenant, $estimateId);
        if (null === $estimate) {
            throw $this->createNotFoundException('Estimate not found.');
        }

        $lineItem = $estimateLineItemRepository->findOneBy(['id' => $lineItemId, 'estimate' => $estimate]);
        if (!$lineItem instanceof EstimateLineItem) {
            throw $this->createNotFoundException('Estimate line item not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $estimate);

        if (!$this->isCsrfTokenValid('estimate_line_item_update_'.$lineItem->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $lineItem
            ->setSectionLabel(trim((string) $request->request->get('sectionLabel', '')) ?: null)
            ->setDescription(trim((string) $request->request->get('description', $lineItem->getDescription())))
            ->setQuantity($this->normalizeQuantity((string) $request->request->get('quantity', $lineItem->getQuantity())))
            ->setUnitPriceCents($this->parseMoneyToCents((string) $request->request->get('unitPrice', (string) ($lineItem->getUnitPriceCents() / 100))))
            ->setTotalCents($moneyCalculator->calculateLineTotalCents(
                $this->normalizeQuantity((string) $request->request->get('quantity', $lineItem->getQuantity())),
                $this->parseMoneyToCents((string) $request->request->get('unitPrice', (string) ($lineItem->getUnitPriceCents() / 100))),
            ))
            ->touch();

        $entityManager->flush();
        $this->recalculateEstimateTotals($estimate, $estimateLineItemRepository, $moneyCalculator);
        $entityManager->flush();

        return $this->redirectToRoute('crm_estimate_show', ['id' => $estimate->getId()]);
    }

    #[Route('/crm/estimates/{estimateId<\d+>}/line-items/{lineItemId<\d+>}/delete', name: 'crm_estimate_delete_line_item', methods: ['POST'])]
    public function deleteLineItem(
        int $estimateId,
        int $lineItemId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        EstimateRepository $estimateRepository,
        EstimateLineItemRepository $estimateLineItemRepository,
        MoneyCalculator $moneyCalculator,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $estimate = $estimateRepository->findOneByTenantAndId($tenant, $estimateId);
        if (null === $estimate) {
            throw $this->createNotFoundException('Estimate not found.');
        }

        $lineItem = $estimateLineItemRepository->findOneBy(['id' => $lineItemId, 'estimate' => $estimate]);
        if (!$lineItem instanceof EstimateLineItem) {
            throw $this->createNotFoundException('Estimate line item not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $estimate);

        if (!$this->isCsrfTokenValid('estimate_line_item_delete_'.$lineItem->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($lineItem);
        $entityManager->flush();
        $this->normalizeEstimateSortOrder($estimateLineItemRepository->findByEstimate($estimate), $entityManager);
        $this->recalculateEstimateTotals($estimate, $estimateLineItemRepository, $moneyCalculator);
        $entityManager->flush();

        return $this->redirectToRoute('crm_estimate_show', ['id' => $estimate->getId()]);
    }

    #[Route('/crm/estimates/{estimateId<\d+>}/line-items/{lineItemId<\d+>}/move', name: 'crm_estimate_move_line_item', methods: ['POST'])]
    public function moveLineItem(
        int $estimateId,
        int $lineItemId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        EstimateRepository $estimateRepository,
        EstimateLineItemRepository $estimateLineItemRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $estimate = $estimateRepository->findOneByTenantAndId($tenant, $estimateId);
        if (null === $estimate) {
            throw $this->createNotFoundException('Estimate not found.');
        }

        $lineItem = $estimateLineItemRepository->findOneBy(['id' => $lineItemId, 'estimate' => $estimate]);
        if (!$lineItem instanceof EstimateLineItem) {
            throw $this->createNotFoundException('Estimate line item not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $estimate);

        if (!$this->isCsrfTokenValid('estimate_line_item_move_'.$lineItem->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $direction = (string) $request->request->get('direction', 'up');
        $lineItems = $estimateLineItemRepository->findByEstimate($estimate);
        $index = array_search($lineItem, $lineItems, true);
        if (false !== $index) {
            $swapIndex = 'down' === $direction ? $index + 1 : $index - 1;
            if (isset($lineItems[$swapIndex])) {
                $currentSort = $lineItem->getSortOrder();
                $other = $lineItems[$swapIndex];
                $lineItem->setSortOrder($other->getSortOrder());
                $other->setSortOrder($currentSort);
                $estimate->touch();
                $entityManager->flush();
            }
        }

        return $this->redirectToRoute('crm_estimate_show', ['id' => $estimate->getId()]);
    }

    #[Route('/crm/estimates/{id<\d+>}/duplicate', name: 'crm_estimate_duplicate', methods: ['POST'])]
    public function duplicate(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        EstimateRepository $estimateRepository,
        EstimateDuplicationService $duplicationService,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $estimate = $estimateRepository->findOneByTenantAndId($tenant, $id);
        if (null === $estimate) {
            throw $this->createNotFoundException('Estimate not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $estimate);

        if (!$this->isCsrfTokenValid('estimate_duplicate_'.$estimate->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $duplicate = $duplicationService->duplicate($estimate);

        return $this->redirectToRoute('crm_estimate_show', ['id' => $duplicate->getId()]);
    }

    #[Route('/crm/estimates/{id<\d+>}/convert-to-quote', name: 'crm_estimate_convert_to_quote', methods: ['POST'])]
    public function convertToQuote(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        EstimateRepository $estimateRepository,
        EstimateToQuoteService $estimateToQuoteService,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $estimate = $estimateRepository->findOneByTenantAndId($tenant, $id);
        if (null === $estimate) {
            throw $this->createNotFoundException('Estimate not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $estimate);

        if (!$this->isCsrfTokenValid('estimate_convert_'.$estimate->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $quote = $estimateToQuoteService->convert($estimate);

        return $this->redirectToRoute('crm_quote_show', ['id' => $quote->getId()]);
    }

    private function parseMoneyToCents(string $value): int
    {
        return (int) round(((float) str_replace([',', '$'], '', trim($value))) * 100);
    }

    private function normalizeQuantity(string $value): string
    {
        $value = trim($value);

        return '' === $value ? '1.00' : number_format((float) $value, 2, '.', '');
    }

    /**
     * @param list<EstimateLineItem> $lineItems
     */
    private function normalizeEstimateSortOrder(array $lineItems, EntityManagerInterface $entityManager): void
    {
        foreach (array_values($lineItems) as $index => $lineItem) {
            $lineItem->setSortOrder($index + 1);
            $entityManager->persist($lineItem);
        }
    }

    private function recalculateEstimateTotals(Estimate $estimate, EstimateLineItemRepository $estimateLineItemRepository, MoneyCalculator $moneyCalculator): void
    {
        $totals = $moneyCalculator->summarize($estimateLineItemRepository->findByEstimate($estimate), $estimate->getTenant());
        $estimate
            ->setSubtotalCents($totals['subtotalCents'])
            ->setTaxCents($totals['taxCents'])
            ->setTotalCents($totals['totalCents'])
            ->touch();
    }
}
