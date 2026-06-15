<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\Quote;
use App\Repository\QuoteLineItemRepository;
use App\Repository\QuoteRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\AuditLogger;
use App\Service\CommunicationTimelineProjector;
use App\Service\CurrentTenantProviderInterface;
use App\Service\QuoteRevisionService;
use App\Service\QuoteToInvoiceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
        CommunicationTimelineProjector $timelineProjector,
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
            Quote::STATUS_IN_REVIEW,
            Quote::STATUS_SENT,
            Quote::STATUS_VIEWED,
            Quote::STATUS_ACCEPTED,
            Quote::STATUS_DECLINED,
            Quote::STATUS_EXPIRED,
            Quote::STATUS_CANCELLED,
            Quote::STATUS_SUPERSEDED,
        ], true)) {
            throw $this->createAccessDeniedException('Invalid quote status.');
        }

        $before = ['status' => $quote->getStatus()];
        $quote->setStatus($newStatus)->touch();
        if (Quote::STATUS_SENT === $newStatus && null === $quote->getSentAt()) {
            $quote->setSentAt(new \DateTimeImmutable());
        }
        if (Quote::STATUS_VIEWED === $newStatus && null === $quote->getViewedAt()) {
            $quote->setViewedAt(new \DateTimeImmutable());
        }
        if (Quote::STATUS_ACCEPTED === $newStatus) {
            $quote->setAcceptedAt(new \DateTimeImmutable());
        }
        if (Quote::STATUS_DECLINED === $newStatus) {
            $quote->setDeclinedAt(new \DateTimeImmutable());
        }
        if (Quote::STATUS_IN_REVIEW === $newStatus) {
            $quote->setInternalReviewAt(new \DateTimeImmutable());
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
        $timelineProjector->recordQuoteEvent(
            $quote,
            'quote.status_changed',
            sprintf('Quote status changed to %s.', $quote->getStatus()),
            ['before' => $before, 'propertyId' => $quote->getProperty()->getId()],
        );

        return $this->redirectToRoute('crm_quote_show', ['id' => $quote->getId()]);
    }

    #[Route('/crm/quotes/{id<\d+>}/review', name: 'crm_quote_request_review', methods: ['POST'])]
    public function requestReview(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        QuoteRepository $quoteRepository,
        AuditLogger $auditLogger,
        EntityManagerInterface $entityManager,
        CommunicationTimelineProjector $timelineProjector,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $quote = $quoteRepository->findOneByTenantAndId($tenant, $id);
        if (null === $quote) {
            throw $this->createNotFoundException('Quote not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $quote);
        if (!$this->isCsrfTokenValid('quote_review_'.$quote->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $before = ['status' => $quote->getStatus()];
        $quote->setStatus(Quote::STATUS_IN_REVIEW)->setInternalReviewAt(new \DateTimeImmutable())->touch();
        $auditLogger->log(
            $tenant,
            'quote',
            $quote->getQuoteNumber(),
            'quote.review_requested',
            $before,
            ['status' => $quote->getStatus()],
            ['propertyId' => $quote->getProperty()->getId()],
        );
        $entityManager->flush();
        $timelineProjector->recordQuoteEvent($quote, 'quote.review_requested', 'Quote sent to internal review.', ['before' => $before]);

        return $this->redirectToRoute('crm_quote_show', ['id' => $quote->getId()]);
    }

    #[Route('/crm/quotes/{id<\d+>}/send', name: 'crm_quote_send', methods: ['POST'])]
    public function send(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        QuoteRepository $quoteRepository,
        AuditLogger $auditLogger,
        EntityManagerInterface $entityManager,
        CommunicationTimelineProjector $timelineProjector,
        MailerInterface $mailer,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $quote = $quoteRepository->findOneByTenantAndId($tenant, $id);
        if (null === $quote) {
            throw $this->createNotFoundException('Quote not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $quote);
        if (!$this->isCsrfTokenValid('quote_send_'.$quote->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $shareToken = $quote->getShareToken() ?? bin2hex(random_bytes(16));
        $before = ['status' => $quote->getStatus(), 'shareToken' => $quote->getShareToken()];
        $quote
            ->setStatus(Quote::STATUS_SENT)
            ->setSentAt(new \DateTimeImmutable())
            ->setValidUntil($quote->getValidUntil() ?? new \DateTimeImmutable('+30 days'))
            ->setShareToken($shareToken)
            ->touch();

        $auditLogger->log(
            $tenant,
            'quote',
            $quote->getQuoteNumber(),
            'quote.sent',
            $before,
            ['status' => Quote::STATUS_SENT, 'shareToken' => $shareToken],
            ['propertyId' => $quote->getProperty()->getId()],
        );
        $entityManager->flush();
        $timelineProjector->recordQuoteEvent(
            $quote,
            'quote.sent',
            'Quote sent to customer.',
            ['shareToken' => $shareToken, 'propertyId' => $quote->getProperty()->getId()],
        );

        if (null !== $quote->getContact()?->getPrimaryEmail()) {
            $publicUrl = $this->generateUrl('quote_public_view', ['token' => $shareToken], UrlGeneratorInterface::ABSOLUTE_URL);
            $pdfUrl = $this->generateUrl('quote_public_pdf', ['token' => $shareToken], UrlGeneratorInterface::ABSOLUTE_URL);
            $email = (new Email())
                ->from((string) ($tenant->getEmail() ?? 'no-reply@localhost'))
                ->to($quote->getContact()->getPrimaryEmail())
                ->subject(sprintf('Quote %s from %s', $quote->getQuoteNumber(), $tenant->getName()))
                ->text(sprintf("Your quote is ready: %s\n\nPDF: %s", $publicUrl, $pdfUrl));
            $mailer->send($email);
        }

        return $this->redirectToRoute('crm_quote_show', ['id' => $quote->getId()]);
    }

    #[Route('/crm/quotes/{id<\d+>}/revise', name: 'crm_quote_revise', methods: ['POST'])]
    public function revise(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        QuoteRepository $quoteRepository,
        QuoteRevisionService $quoteRevisionService,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $quote = $quoteRepository->findOneByTenantAndId($tenant, $id);
        if (null === $quote) {
            throw $this->createNotFoundException('Quote not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $quote);
        if (!$this->isCsrfTokenValid('quote_revise_'.$quote->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $revision = $quoteRevisionService->revise($quote);

        return $this->redirectToRoute('crm_quote_show', ['id' => $revision->getId()]);
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
