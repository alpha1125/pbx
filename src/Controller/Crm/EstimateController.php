<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\EstimateLineItem;
use App\Repository\EstimateLineItemRepository;
use App\Repository\EstimateRepository;
use App\Service\AuditLogger;
use App\Service\CurrentTenantProviderInterface;
use App\Service\EstimateToQuoteService;
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
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $estimate = $estimateRepository->findOneByTenantAndId($tenant, $id);
        if (null === $estimate) {
            throw $this->createNotFoundException('Estimate not found.');
        }

        return $this->render('crm/estimate/show.html.twig', [
            'tenant' => $tenant,
            'estimate' => $estimate,
            'lineItems' => $estimateLineItemRepository->findByEstimate($estimate),
        ]);
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

        if (!$this->isCsrfTokenValid('estimate_line_item_'.$estimate->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $description = trim((string) $request->request->get('description'));
        if ('' === $description) {
            throw $this->createAccessDeniedException('Description is required.');
        }

        $quantity = $this->normalizeQuantity((string) $request->request->get('quantity', '1'));
        $unitPriceCents = $this->parseMoneyToCents((string) $request->request->get('unitPrice'));
        $sortOrder = count($estimateLineItemRepository->findByEstimate($estimate)) + 1;

        $lineItem = (new EstimateLineItem($tenant, $estimate, $description))
            ->setQuantity($quantity)
            ->setUnitPriceCents($unitPriceCents)
            ->setTotalCents($moneyCalculator->calculateLineTotalCents($quantity, $unitPriceCents))
            ->setSortOrder($sortOrder);

        $entityManager->persist($lineItem);
        $entityManager->flush();

        $lineItems = $estimateLineItemRepository->findByEstimate($estimate);
        $totals = $moneyCalculator->summarize($lineItems);
        $estimate
            ->setSubtotalCents($totals['subtotalCents'])
            ->setTaxCents($totals['taxCents'])
            ->setTotalCents($totals['totalCents'])
            ->touch();

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

        return $this->redirectToRoute('crm_estimate_show', ['id' => $estimate->getId()]);
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
}
