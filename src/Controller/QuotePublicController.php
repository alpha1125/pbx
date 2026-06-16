<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Quote;
use App\Repository\QuoteLineItemRepository;
use App\Repository\QuoteRepository;
use App\Service\CommunicationTimelineProjector;
use App\Service\QuoteToJobService;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class QuotePublicController extends AbstractController
{
    #[Route('/quotes/{token}', name: 'quote_public_view', methods: ['GET'])]
    public function view(
        string $token,
        QuoteRepository $quoteRepository,
        QuoteLineItemRepository $quoteLineItemRepository,
        EntityManagerInterface $entityManager,
        CommunicationTimelineProjector $timelineProjector,
    ): Response {
        $quote = $quoteRepository->findOneByShareToken($token);
        if (null === $quote) {
            throw $this->createNotFoundException('Quote not found.');
        }

        $this->expireIfNeeded($quote, $entityManager, $timelineProjector);
        $this->markViewed($quote, $entityManager, $timelineProjector);

        return $this->render('crm/quote/public.html.twig', [
            'quote' => $quote,
            'lineItems' => $quoteLineItemRepository->findByQuote($quote),
            'printable' => false,
        ]);
    }

    #[Route('/quotes/{token}/print', name: 'quote_public_print', methods: ['GET'])]
    public function print(
        string $token,
        QuoteRepository $quoteRepository,
        QuoteLineItemRepository $quoteLineItemRepository,
        EntityManagerInterface $entityManager,
        CommunicationTimelineProjector $timelineProjector,
    ): Response {
        $quote = $quoteRepository->findOneByShareToken($token);
        if (null === $quote) {
            throw $this->createNotFoundException('Quote not found.');
        }

        $this->expireIfNeeded($quote, $entityManager, $timelineProjector);
        $this->markViewed($quote, $entityManager, $timelineProjector);

        return $this->render('crm/quote/public.html.twig', [
            'quote' => $quote,
            'lineItems' => $quoteLineItemRepository->findByQuote($quote),
            'printable' => true,
        ]);
    }

    #[Route('/quotes/{token}/pdf', name: 'quote_public_pdf', methods: ['GET'])]
    public function pdf(
        string $token,
        QuoteRepository $quoteRepository,
        QuoteLineItemRepository $quoteLineItemRepository,
        EntityManagerInterface $entityManager,
        CommunicationTimelineProjector $timelineProjector,
    ): Response {
        $quote = $quoteRepository->findOneByShareToken($token);
        if (null === $quote) {
            throw $this->createNotFoundException('Quote not found.');
        }

        $this->expireIfNeeded($quote, $entityManager, $timelineProjector);
        $this->markViewed($quote, $entityManager, $timelineProjector);
        $html = $this->renderView('crm/quote/public.html.twig', [
            'quote' => $quote,
            'lineItems' => $quoteLineItemRepository->findByQuote($quote),
            'printable' => true,
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter');
        $dompdf->render();

        return new Response($dompdf->output(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s.pdf"', $quote->getQuoteNumber()),
        ]);
    }

    #[Route('/quotes/{token}/accept', name: 'quote_public_accept', methods: ['POST'])]
    public function accept(
        string $token,
        Request $request,
        QuoteRepository $quoteRepository,
        EntityManagerInterface $entityManager,
        CommunicationTimelineProjector $timelineProjector,
        QuoteToJobService $quoteToJobService,
    ): RedirectResponse {
        $quote = $quoteRepository->findOneByShareToken($token);
        if (null === $quote) {
            throw $this->createNotFoundException('Quote not found.');
        }

        if (!$this->isCsrfTokenValid('quote_public_accept_'.$quote->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->expireIfNeeded($quote, $entityManager, $timelineProjector);
        $this->markViewed($quote, $entityManager, $timelineProjector);
        if (Quote::STATUS_EXPIRED === $quote->getStatus()) {
            $this->addFlash('error', 'This quote has expired.');

            return $this->redirectToRoute('quote_public_view', ['token' => $token]);
        }
        $quote
            ->setStatus(Quote::STATUS_ACCEPTED)
            ->setAcceptedAt(new \DateTimeImmutable())
            ->setAcceptedByName(trim((string) $request->request->get('acceptedByName', '')) ?: null)
            ->setAcceptedByEmail(trim((string) $request->request->get('acceptedByEmail', '')) ?: null)
            ->setAcceptedMessage(trim((string) $request->request->get('acceptedMessage', '')) ?: null)
            ->touch();
        $entityManager->flush();
        $timelineProjector->recordQuoteEvent($quote, 'quote.accepted', 'Quote accepted by customer.', [
            'acceptedByName' => $quote->getAcceptedByName(),
            'acceptedByEmail' => $quote->getAcceptedByEmail(),
        ]);
        $quoteToJobService->createFromAcceptedQuote($quote);

        $this->addFlash('success', 'Quote accepted.');

        return $this->redirectToRoute('quote_public_view', ['token' => $token]);
    }

    #[Route('/quotes/{token}/decline', name: 'quote_public_decline', methods: ['POST'])]
    public function decline(
        string $token,
        Request $request,
        QuoteRepository $quoteRepository,
        EntityManagerInterface $entityManager,
        CommunicationTimelineProjector $timelineProjector,
    ): RedirectResponse {
        $quote = $quoteRepository->findOneByShareToken($token);
        if (null === $quote) {
            throw $this->createNotFoundException('Quote not found.');
        }

        if (!$this->isCsrfTokenValid('quote_public_decline_'.$quote->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->expireIfNeeded($quote, $entityManager, $timelineProjector);
        $this->markViewed($quote, $entityManager, $timelineProjector);
        $quote
            ->setStatus(Quote::STATUS_DECLINED)
            ->setDeclinedAt(new \DateTimeImmutable())
            ->setAcceptedMessage(trim((string) $request->request->get('declineReason', '')) ?: null)
            ->touch();
        $entityManager->flush();
        $timelineProjector->recordQuoteEvent($quote, 'quote.declined', 'Quote declined by customer.', []);

        $this->addFlash('success', 'Quote declined.');

        return $this->redirectToRoute('quote_public_view', ['token' => $token]);
    }

    private function markViewed(Quote $quote, EntityManagerInterface $entityManager, CommunicationTimelineProjector $timelineProjector): void
    {
        if (null !== $quote->getViewedAt()) {
            return;
        }

        $quote->setViewedAt(new \DateTimeImmutable());
        if (Quote::STATUS_SENT === $quote->getStatus()) {
            $quote->setStatus(Quote::STATUS_VIEWED);
        }
        $quote->touch();
        $entityManager->flush();
        $timelineProjector->recordQuoteEvent($quote, 'quote.viewed', 'Quote viewed by customer.', []);
    }

    private function expireIfNeeded(Quote $quote, EntityManagerInterface $entityManager, CommunicationTimelineProjector $timelineProjector): void
    {
        if (null === $quote->getValidUntil()) {
            return;
        }

        if (in_array($quote->getStatus(), [Quote::STATUS_ACCEPTED, Quote::STATUS_DECLINED, Quote::STATUS_CANCELLED, Quote::STATUS_SUPERSEDED, Quote::STATUS_EXPIRED], true)) {
            return;
        }

        $today = new \DateTimeImmutable('today');
        if ($quote->getValidUntil() >= $today) {
            return;
        }

        $quote->setStatus(Quote::STATUS_EXPIRED)->touch();
        $entityManager->flush();
        $timelineProjector->recordQuoteEvent($quote, 'quote.expired', 'Quote expired.', ['validUntil' => $quote->getValidUntil()->format('Y-m-d')]);
    }
}
