<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\Quote;
use App\Repository\QuoteLineItemRepository;
use App\Repository\QuoteRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\AuditLogger;
use App\Service\CurrentTenantProviderInterface;
use App\Service\QuoteToInvoiceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class QuoteController extends AbstractController
{
    #[Route('/crm/quotes/{id<\d+>}', name: 'crm_quote_show', methods: ['GET'])]
    public function show(
        int $id,
        CurrentTenantProviderInterface $tenantProvider,
        QuoteRepository $quoteRepository,
        QuoteLineItemRepository $quoteLineItemRepository,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $quote = $quoteRepository->findOneByTenantAndId($tenant, $id);
        if (null === $quote) {
            throw $this->createNotFoundException('Quote not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $quote);

        return $this->render('crm/quote/show.html.twig', [
            'tenant' => $tenant,
            'quote' => $quote,
            'lineItems' => $quoteLineItemRepository->findByQuote($quote),
        ]);
    }

    #[Route('/crm/quotes/{id<\d+>}/status', name: 'crm_quote_update_status', methods: ['POST'])]
    public function updateStatus(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        QuoteRepository $quoteRepository,
        AuditLogger $auditLogger,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $quote = $quoteRepository->findOneByTenantAndId($tenant, $id);
        if (null === $quote) {
            throw $this->createNotFoundException('Quote not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $quote);

        if (!$this->isCsrfTokenValid('quote_status_'.$quote->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $newStatus = trim((string) $request->request->get('status'));
        if (!in_array($newStatus, [
            Quote::STATUS_DRAFT,
            Quote::STATUS_SENT,
            Quote::STATUS_VIEWED,
            Quote::STATUS_ACCEPTED,
            Quote::STATUS_DECLINED,
            Quote::STATUS_EXPIRED,
            Quote::STATUS_CANCELLED,
        ], true)) {
            throw $this->createAccessDeniedException('Invalid quote status.');
        }

        $before = ['status' => $quote->getStatus()];
        $quote->setStatus($newStatus)->touch();
        if (Quote::STATUS_SENT === $newStatus && null === $quote->getSentAt()) {
            $quote->setSentAt(new \DateTimeImmutable());
        }
        if (Quote::STATUS_ACCEPTED === $newStatus) {
            $quote->setAcceptedAt(new \DateTimeImmutable());
        }

        $auditLogger->log(
            $tenant,
            'quote',
            $quote->getQuoteNumber(),
            'quote.status_changed',
            $before,
            ['status' => $quote->getStatus()],
            ['propertyId' => $quote->getProperty()->getId()],
        );
        $entityManager->flush();

        return $this->redirectToRoute('crm_quote_show', ['id' => $quote->getId()]);
    }

    #[Route('/crm/quotes/{id<\d+>}/convert-to-invoice', name: 'crm_quote_convert_to_invoice', methods: ['POST'])]
    public function convertToInvoice(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        QuoteRepository $quoteRepository,
        QuoteToInvoiceService $quoteToInvoiceService,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $quote = $quoteRepository->findOneByTenantAndId($tenant, $id);
        if (null === $quote) {
            throw $this->createNotFoundException('Quote not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $quote);

        if (!$this->isCsrfTokenValid('quote_convert_'.$quote->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $invoice = $quoteToInvoiceService->convert($quote);

        return $this->redirectToRoute('crm_invoice_show', ['id' => $invoice->getId()]);
    }
}
