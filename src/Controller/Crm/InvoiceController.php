<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\Invoice;
use App\Entity\InvoiceLineItem;
use App\Entity\Payment;
use App\Entity\PaymentAllocation;
use App\Repository\InvoiceLineItemRepository;
use App\Repository\InvoiceAccountingSyncRecordRepository;
use App\Repository\AuditLogRepository;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentAllocationRepository;
use App\Repository\PaymentRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\AuditLogger;
use App\Service\CommunicationTimelineProjector;
use App\Service\CurrentTenantProviderInterface;
use App\Service\InvoiceAccountingExportPayloadBuilder;
use App\Service\InvoiceAccountingService;
use App\Service\MoneyCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

final class InvoiceController extends AbstractController
{
    #[Route('/crm/invoices/aging', name: 'crm_invoice_aging', methods: ['GET'])]
    public function aging(
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        InvoiceAccountingService $invoiceAccountingService,
        EntityManagerInterface $entityManager,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invoices = $invoiceRepository->findAgingByTenant($tenant);
        foreach ($invoices as $invoice) {
            $invoiceAccountingService->refresh($invoice);
        }
        $entityManager->flush();

        return $this->render('crm/invoice/aging.html.twig', [
            'tenant' => $tenant,
            'invoices' => $invoices,
            'agingBuckets' => $this->bucketInvoices($invoices),
        ]);
    }

    #[Route('/crm/invoices/{id<\d+>}', name: 'crm_invoice_show', methods: ['GET'])]
    public function show(
        int $id,
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        InvoiceLineItemRepository $invoiceLineItemRepository,
        InvoiceAccountingSyncRecordRepository $invoiceAccountingSyncRecordRepository,
        AuditLogRepository $auditLogRepository,
        InvoiceAccountingExportPayloadBuilder $invoiceAccountingExportPayloadBuilder,
        PaymentAllocationRepository $paymentAllocationRepository,
        InvoiceAccountingService $invoiceAccountingService,
        EntityManagerInterface $entityManager,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invoice = $invoiceRepository->findOneByTenantAndId($tenant, $id);
        if (null === $invoice) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $invoice);
        $invoiceAccountingService->refresh($invoice);
        $entityManager->flush();

        return $this->render('crm/invoice/show.html.twig', [
            'tenant' => $tenant,
            'invoice' => $invoice,
            'lineItems' => $invoiceLineItemRepository->findByInvoice($invoice),
            'accountingSyncRecords' => $invoiceAccountingSyncRecordRepository->findByInvoice($invoice),
            'accountingExportLogs' => $auditLogRepository->findRecentByInvoice($invoice),
            'accountingExportPayloads' => [
                'quickbooksOnline' => $invoiceAccountingExportPayloadBuilder->buildQuickBooksOnline($invoice),
                'xero' => $invoiceAccountingExportPayloadBuilder->buildXero($invoice),
            ],
            'paymentAllocations' => $paymentAllocationRepository->findByInvoice($invoice),
        ]);
    }

    #[Route('/crm/invoices/{id<\d+>}/print', name: 'crm_invoice_print', methods: ['GET'])]
    public function print(
        int $id,
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        InvoiceLineItemRepository $invoiceLineItemRepository,
        PaymentAllocationRepository $paymentAllocationRepository,
        InvoiceAccountingService $invoiceAccountingService,
        EntityManagerInterface $entityManager,
    ): Response {
        return $this->renderInvoiceDocument(
            $id,
            $tenantProvider,
            $invoiceRepository,
            $invoiceLineItemRepository,
            $paymentAllocationRepository,
            $invoiceAccountingService,
            $entityManager,
            true,
        );
    }

    #[Route('/crm/invoices/{id<\d+>}/pdf', name: 'crm_invoice_pdf', methods: ['GET'])]
    public function pdf(
        int $id,
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        InvoiceLineItemRepository $invoiceLineItemRepository,
        PaymentAllocationRepository $paymentAllocationRepository,
        InvoiceAccountingService $invoiceAccountingService,
        EntityManagerInterface $entityManager,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invoice = $invoiceRepository->findOneByTenantAndId($tenant, $id);
        if (null === $invoice) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $invoice);
        $invoiceAccountingService->refresh($invoice);
        $entityManager->flush();

        $html = $this->renderView('crm/invoice/public.html.twig', [
            'tenant' => $tenant,
            'invoice' => $invoice,
            'lineItems' => $invoiceLineItemRepository->findByInvoice($invoice),
            'paymentAllocations' => $paymentAllocationRepository->findByInvoice($invoice),
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
            'Content-Disposition' => sprintf('inline; filename="%s.pdf"', $invoice->getInvoiceNumber()),
        ]);
    }

    #[Route('/crm/invoices/{id<\d+>}/send', name: 'crm_invoice_send', methods: ['POST'])]
    public function send(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        InvoiceLineItemRepository $invoiceLineItemRepository,
        PaymentAllocationRepository $paymentAllocationRepository,
        InvoiceAccountingService $invoiceAccountingService,
        MailerInterface $mailer,
        AuditLogger $auditLogger,
        CommunicationTimelineProjector $timelineProjector,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invoice = $invoiceRepository->findOneByTenantAndId($tenant, $id);
        if (null === $invoice) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $invoice);
        $this->assertEditable($invoice);

        if (!$this->isCsrfTokenValid('invoice_send_'.$invoice->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $contact = $invoice->getContact();
        if (null === $contact || null === $contact->getPrimaryEmail()) {
            throw $this->createAccessDeniedException('Invoice contact email is required.');
        }

        $invoiceAccountingService->refresh($invoice);
        $invoice
            ->setStatus(Invoice::STATUS_SENT)
            ->setSentAt(new \DateTimeImmutable())
            ->touch();

        $html = $this->renderView('crm/invoice/public.html.twig', [
            'tenant' => $tenant,
            'invoice' => $invoice,
            'lineItems' => $invoiceLineItemRepository->findByInvoice($invoice),
            'paymentAllocations' => $paymentAllocationRepository->findByInvoice($invoice),
            'printable' => true,
        ]);
        $pdf = $this->renderInvoicePdf($html);

        $email = (new Email())
            ->from((string) ($tenant->getEmail() ?? 'no-reply@localhost'))
            ->to($contact->getPrimaryEmail())
            ->subject(sprintf('Invoice %s from %s', $invoice->getInvoiceNumber(), $tenant->getName()))
            ->text(sprintf(
                "Invoice %s is attached.\n\nBalance due: $%.2f",
                $invoice->getInvoiceNumber(),
                $invoice->getBalanceCents() / 100,
            ))
            ->attach($pdf, sprintf('%s.pdf', $invoice->getInvoiceNumber()), 'application/pdf');
        $mailer->send($email);

        $auditLogger->log(
            $tenant,
            'invoice',
            $invoice->getInvoiceNumber(),
            'invoice.sent',
            ['status' => $invoice->getStatus()],
            ['status' => Invoice::STATUS_SENT, 'sentAt' => $invoice->getSentAt()?->format(DATE_ATOM)],
            ['propertyId' => $invoice->getProperty()->getId()],
        );
        $entityManager->flush();
        $timelineProjector->recordInvoiceEvent(
            $invoice,
            'invoice.sent',
            'Invoice emailed to customer.',
            ['sentAt' => $invoice->getSentAt()?->format(DATE_ATOM)],
        );

        return $this->redirectToRoute('crm_invoice_show', ['id' => $invoice->getId()]);
    }

    #[Route('/crm/invoices/{id<\d+>}/remind', name: 'crm_invoice_remind', methods: ['POST'])]
    public function remind(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        InvoiceLineItemRepository $invoiceLineItemRepository,
        PaymentAllocationRepository $paymentAllocationRepository,
        InvoiceAccountingService $invoiceAccountingService,
        MailerInterface $mailer,
        AuditLogger $auditLogger,
        CommunicationTimelineProjector $timelineProjector,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invoice = $invoiceRepository->findOneByTenantAndId($tenant, $id);
        if (null === $invoice) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $invoice);
        $this->assertEditable($invoice);

        if (!$this->isCsrfTokenValid('invoice_remind_'.$invoice->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $invoiceAccountingService->refresh($invoice);
        if ($invoice->getBalanceCents() <= 0) {
            throw $this->createAccessDeniedException('Invoice does not have an outstanding balance.');
        }
        if ((null === $invoice->getDueAt() || $invoice->getDueAt() >= new \DateTimeImmutable('today')) && Invoice::STATUS_OVERDUE !== $invoice->getStatus()) {
            throw $this->createAccessDeniedException('Invoice is not overdue yet.');
        }

        $contact = $invoice->getContact();
        if (null === $contact || null === $contact->getPrimaryEmail()) {
            throw $this->createAccessDeniedException('Invoice contact email is required.');
        }

        $invoice
            ->setLastReminderAt(new \DateTimeImmutable())
            ->incrementReminderCount()
            ->touch();

        $html = $this->renderView('crm/invoice/public.html.twig', [
            'tenant' => $tenant,
            'invoice' => $invoice,
            'lineItems' => $invoiceLineItemRepository->findByInvoice($invoice),
            'paymentAllocations' => $paymentAllocationRepository->findByInvoice($invoice),
            'printable' => true,
        ]);
        $pdf = $this->renderInvoicePdf($html);

        $email = (new Email())
            ->from((string) ($tenant->getEmail() ?? 'no-reply@localhost'))
            ->to($contact->getPrimaryEmail())
            ->subject(sprintf('Overdue reminder for invoice %s', $invoice->getInvoiceNumber()))
            ->text(sprintf(
                "This is a reminder that invoice %s has a balance of $%.2f.\n\nPlease remit payment as soon as possible.",
                $invoice->getInvoiceNumber(),
                $invoice->getBalanceCents() / 100,
            ))
            ->attach($pdf, sprintf('%s.pdf', $invoice->getInvoiceNumber()), 'application/pdf');
        $mailer->send($email);

        $auditLogger->log(
            $tenant,
            'invoice',
            $invoice->getInvoiceNumber(),
            'invoice.reminder_sent',
            null,
            [
                'lastReminderAt' => $invoice->getLastReminderAt()?->format(DATE_ATOM),
                'reminderCount' => $invoice->getReminderCount(),
            ],
            ['propertyId' => $invoice->getProperty()->getId()],
        );
        $entityManager->flush();
        $timelineProjector->recordInvoiceEvent(
            $invoice,
            'invoice.reminder_sent',
            'Overdue invoice reminder emailed.',
            ['reminderCount' => $invoice->getReminderCount()],
        );

        return $this->redirectToRoute('crm_invoice_show', ['id' => $invoice->getId()]);
    }

    #[Route('/crm/invoices/{id<\d+>}/details', name: 'crm_invoice_update_details', methods: ['POST'])]
    public function updateDetails(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        InvoiceAccountingService $invoiceAccountingService,
        AuditLogger $auditLogger,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invoice = $invoiceRepository->findOneByTenantAndId($tenant, $id);
        if (null === $invoice) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $invoice);
        $this->assertEditable($invoice);

        if (!$this->isCsrfTokenValid('invoice_details_'.$invoice->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $before = ['status' => $invoice->getStatus(), 'issuedAt' => $invoice->getIssuedAt()?->format('Y-m-d')];
        $invoice
            ->setIssuedAt($this->parseDateOrNull((string) $request->request->get('issuedAt', '')) ?? $invoice->getIssuedAt())
            ->setDueAt($this->parseDateOrNull((string) $request->request->get('dueAt', '')) ?? $invoice->getDueAt())
            ->setNotes($this->blankToNull((string) $request->request->get('notes', '')))
            ->setPaymentInstructions($this->blankToNull((string) $request->request->get('paymentInstructions', '')))
            ->setStatus($this->normalizeInvoiceStatus((string) $request->request->get('status', $invoice->getStatus())))
            ->setVoidedAt(Invoice::STATUS_VOID === $request->request->get('status') ? new \DateTimeImmutable() : $invoice->getVoidedAt())
            ->setVoidReason(Invoice::STATUS_VOID === $request->request->get('status') ? $this->blankToNull((string) $request->request->get('voidReason', '')) : $invoice->getVoidReason())
            ->touch();

        if (in_array($invoice->getStatus(), [Invoice::STATUS_SENT, Invoice::STATUS_UNPAID], true) && null === $invoice->getIssuedAt()) {
            $invoice->setIssuedAt(new \DateTimeImmutable('today'));
        }

        $invoiceAccountingService->refresh($invoice);
        $auditLogger->log(
            $tenant,
            'invoice',
            $invoice->getInvoiceNumber(),
            'invoice.updated',
            $before,
            ['status' => $invoice->getStatus(), 'issuedAt' => $invoice->getIssuedAt()?->format('Y-m-d')],
            ['propertyId' => $invoice->getProperty()->getId()],
        );
        $entityManager->flush();

        return $this->redirectToRoute('crm_invoice_show', ['id' => $invoice->getId()]);
    }

    #[Route('/crm/invoices/{id<\d+>}/line-items', name: 'crm_invoice_add_line_item', methods: ['POST'])]
    public function addLineItem(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        InvoiceLineItemRepository $invoiceLineItemRepository,
        MoneyCalculator $moneyCalculator,
        InvoiceAccountingService $invoiceAccountingService,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invoice = $invoiceRepository->findOneByTenantAndId($tenant, $id);
        if (null === $invoice) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $invoice);
        $this->assertEditable($invoice);

        if (!$this->isCsrfTokenValid('invoice_line_item_'.$invoice->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $description = trim((string) $request->request->get('description'));
        if ('' === $description) {
            throw $this->createAccessDeniedException('Description is required.');
        }

        $quantity = $this->normalizeQuantity((string) $request->request->get('quantity', '1'));
        $unitPriceCents = $this->parseMoneyToCents((string) $request->request->get('unitPrice'));
        $sectionLabel = trim((string) $request->request->get('sectionLabel', ''));
        $sortOrder = count($invoiceLineItemRepository->findByInvoice($invoice)) + 1;

        $lineItem = (new InvoiceLineItem($tenant, $invoice, $description))
            ->setSectionLabel('' !== $sectionLabel ? $sectionLabel : null)
            ->setQuantity($quantity)
            ->setUnitPriceCents($unitPriceCents)
            ->setTotalCents($moneyCalculator->calculateLineTotalCents($quantity, $unitPriceCents))
            ->setSortOrder($sortOrder);

        $entityManager->persist($lineItem);
        $entityManager->flush();

        $invoiceAccountingService->refresh($invoice);
        $entityManager->flush();

        return $this->redirectToRoute('crm_invoice_show', ['id' => $invoice->getId()]);
    }

    #[Route('/crm/invoices/{invoiceId<\d+>}/line-items/{lineItemId<\d+>}', name: 'crm_invoice_update_line_item', methods: ['POST'])]
    public function updateLineItem(
        int $invoiceId,
        int $lineItemId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        InvoiceLineItemRepository $invoiceLineItemRepository,
        MoneyCalculator $moneyCalculator,
        InvoiceAccountingService $invoiceAccountingService,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invoice = $invoiceRepository->findOneByTenantAndId($tenant, $invoiceId);
        if (null === $invoice) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        $lineItem = $invoiceLineItemRepository->findOneBy(['id' => $lineItemId, 'invoice' => $invoice]);
        if (!$lineItem instanceof InvoiceLineItem) {
            throw $this->createNotFoundException('Invoice line item not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $invoice);
        $this->assertEditable($invoice);

        if (!$this->isCsrfTokenValid('invoice_line_item_update_'.$lineItem->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $quantity = $this->normalizeQuantity((string) $request->request->get('quantity', $lineItem->getQuantity()));
        $unitPriceCents = $this->parseMoneyToCents((string) $request->request->get('unitPrice', (string) ($lineItem->getUnitPriceCents() / 100)));
        $lineItem
            ->setSectionLabel($this->blankToNull((string) $request->request->get('sectionLabel', '')))
            ->setDescription(trim((string) $request->request->get('description', $lineItem->getDescription())))
            ->setQuantity($quantity)
            ->setUnitPriceCents($unitPriceCents)
            ->setTotalCents($moneyCalculator->calculateLineTotalCents($quantity, $unitPriceCents))
            ->touch();

        $entityManager->flush();
        $invoiceAccountingService->refresh($invoice);
        $entityManager->flush();

        return $this->redirectToRoute('crm_invoice_show', ['id' => $invoice->getId()]);
    }

    #[Route('/crm/invoices/{invoiceId<\d+>}/line-items/{lineItemId<\d+>}/delete', name: 'crm_invoice_delete_line_item', methods: ['POST'])]
    public function deleteLineItem(
        int $invoiceId,
        int $lineItemId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        InvoiceLineItemRepository $invoiceLineItemRepository,
        InvoiceAccountingService $invoiceAccountingService,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invoice = $invoiceRepository->findOneByTenantAndId($tenant, $invoiceId);
        if (null === $invoice) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        $lineItem = $invoiceLineItemRepository->findOneBy(['id' => $lineItemId, 'invoice' => $invoice]);
        if (!$lineItem instanceof InvoiceLineItem) {
            throw $this->createNotFoundException('Invoice line item not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $invoice);
        $this->assertEditable($invoice);

        if (!$this->isCsrfTokenValid('invoice_line_item_delete_'.$lineItem->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($lineItem);
        $entityManager->flush();

        $remainingLineItems = $invoiceLineItemRepository->findByInvoice($invoice);
        foreach (array_values($remainingLineItems) as $index => $remainingLineItem) {
            $remainingLineItem->setSortOrder($index + 1);
        }
        $invoiceAccountingService->refresh($invoice);
        $entityManager->flush();

        return $this->redirectToRoute('crm_invoice_show', ['id' => $invoice->getId()]);
    }

    #[Route('/crm/invoices/{invoiceId<\d+>}/line-items/{lineItemId<\d+>}/move', name: 'crm_invoice_move_line_item', methods: ['POST'])]
    public function moveLineItem(
        int $invoiceId,
        int $lineItemId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        InvoiceLineItemRepository $invoiceLineItemRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invoice = $invoiceRepository->findOneByTenantAndId($tenant, $invoiceId);
        if (null === $invoice) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        $lineItem = $invoiceLineItemRepository->findOneBy(['id' => $lineItemId, 'invoice' => $invoice]);
        if (!$lineItem instanceof InvoiceLineItem) {
            throw $this->createNotFoundException('Invoice line item not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $invoice);
        $this->assertEditable($invoice);

        if (!$this->isCsrfTokenValid('invoice_line_item_move_'.$lineItem->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $direction = (string) $request->request->get('direction', 'up');
        $lineItems = $invoiceLineItemRepository->findByInvoice($invoice);
        $index = array_search($lineItem, $lineItems, true);
        if (false !== $index) {
            $swapIndex = 'down' === $direction ? $index + 1 : $index - 1;
            if (isset($lineItems[$swapIndex])) {
                $currentSort = $lineItem->getSortOrder();
                $other = $lineItems[$swapIndex];
                $lineItem->setSortOrder($other->getSortOrder());
                $other->setSortOrder($currentSort);
                $invoice->touch();
                $entityManager->flush();
            }
        }

        return $this->redirectToRoute('crm_invoice_show', ['id' => $invoice->getId()]);
    }

    #[Route('/crm/invoices/{id<\d+>}/payments', name: 'crm_invoice_record_payment', methods: ['POST'])]
    public function recordPayment(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        PaymentRepository $paymentRepository,
        InvoiceAccountingService $invoiceAccountingService,
        CommunicationTimelineProjector $timelineProjector,
        AuditLogger $auditLogger,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invoice = $invoiceRepository->findOneByTenantAndId($tenant, $id);
        if (null === $invoice) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $invoice);
        $this->assertEditable($invoice);

        if (!$this->isCsrfTokenValid('invoice_payment_'.$invoice->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $amountCents = $this->parseMoneyToCents((string) $request->request->get('amount'));
        if ($amountCents <= 0) {
            throw $this->createAccessDeniedException('Payment amount must be greater than zero.');
        }

        $kind = (string) $request->request->get('kind', Payment::KIND_RECEIVED);
        if (!in_array($kind, [Payment::KIND_RECEIVED, Payment::KIND_REFUND], true)) {
            throw $this->createAccessDeniedException('Invalid payment kind.');
        }

        $paymentNumber = sprintf('P-%d-%05d', $tenant->getId() ?? 0, $paymentRepository->countByTenant($tenant) + 1);
        $payment = (new Payment($tenant, $paymentNumber))
            ->setKind($kind)
            ->setAmountCents($amountCents)
            ->setReceivedAt($this->parseDateOrNull((string) $request->request->get('receivedAt', '')) ?? new \DateTimeImmutable('today'))
            ->setMethod($this->blankToNull((string) $request->request->get('method', '')))
            ->setReference($this->blankToNull((string) $request->request->get('reference', '')))
            ->setMemo($this->blankToNull((string) $request->request->get('memo', '')));
        $allocation = new PaymentAllocation($tenant, $payment, $invoice, $amountCents);

        $entityManager->persist($payment);
        $entityManager->persist($allocation);
        $entityManager->flush();

        $invoiceAccountingService->refresh($invoice);
        $auditLogger->log(
            $tenant,
            'invoice',
            $invoice->getInvoiceNumber(),
            'invoice.payment_recorded',
            null,
            [
                'amountCents' => $amountCents,
                'kind' => $kind,
                'paymentNumber' => $payment->getPaymentNumber(),
            ],
            ['propertyId' => $invoice->getProperty()->getId()],
        );
        $entityManager->flush();
        $timelineProjector->recordInvoiceEvent(
            $invoice,
            'invoice.payment_recorded',
            sprintf('Payment %s recorded.', $payment->getPaymentNumber()),
            ['paymentNumber' => $payment->getPaymentNumber(), 'amountCents' => $amountCents, 'kind' => $kind],
        );

        return $this->redirectToRoute('crm_invoice_show', ['id' => $invoice->getId()]);
    }

    #[Route('/crm/invoices/{id<\d+>}/void', name: 'crm_invoice_void', methods: ['POST'])]
    public function void(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        AuditLogger $auditLogger,
        CommunicationTimelineProjector $timelineProjector,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invoice = $invoiceRepository->findOneByTenantAndId($tenant, $id);
        if (null === $invoice) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $invoice);

        if (!$this->isCsrfTokenValid('invoice_void_'.$invoice->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $invoice
            ->setStatus(Invoice::STATUS_VOID)
            ->setVoidedAt(new \DateTimeImmutable())
            ->setVoidReason($this->blankToNull((string) $request->request->get('voidReason', '')))
            ->touch();
        $auditLogger->log(
            $tenant,
            'invoice',
            $invoice->getInvoiceNumber(),
            'invoice.voided',
            null,
            ['voidReason' => $invoice->getVoidReason()],
            ['propertyId' => $invoice->getProperty()->getId()],
        );
        $entityManager->flush();
        $timelineProjector->recordInvoiceEvent($invoice, 'invoice.voided', 'Invoice voided.', ['voidReason' => $invoice->getVoidReason()]);

        return $this->redirectToRoute('crm_invoice_show', ['id' => $invoice->getId()]);
    }

    #[Route('/crm/invoices/{id<\d+>}/status', name: 'crm_invoice_update_status', methods: ['POST'])]
    public function updateStatus(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        AuditLogger $auditLogger,
        EntityManagerInterface $entityManager,
        CommunicationTimelineProjector $timelineProjector,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invoice = $invoiceRepository->findOneByTenantAndId($tenant, $id);
        if (null === $invoice) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $invoice);

        if (!$this->isCsrfTokenValid('invoice_status_'.$invoice->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $newStatus = $this->normalizeInvoiceStatus((string) $request->request->get('status'));
        $before = ['status' => $invoice->getStatus()];
        $invoice->setStatus($newStatus)->touch();
        if (in_array($newStatus, [Invoice::STATUS_SENT, Invoice::STATUS_UNPAID], true) && null === $invoice->getIssuedAt()) {
            $invoice->setIssuedAt(new \DateTimeImmutable('today'));
        }
        if (Invoice::STATUS_VOID === $newStatus) {
            $invoice->setVoidedAt(new \DateTimeImmutable());
        }

        $auditLogger->log(
            $tenant,
            'invoice',
            $invoice->getInvoiceNumber(),
            'invoice.status_changed',
            $before,
            ['status' => $invoice->getStatus()],
            ['propertyId' => $invoice->getProperty()->getId()],
        );
        $entityManager->flush();
        $timelineProjector->recordInvoiceEvent(
            $invoice,
            'invoice.status_changed',
            sprintf('Invoice status changed to %s.', $invoice->getStatus()),
            ['before' => $before, 'propertyId' => $invoice->getProperty()->getId()],
        );

        return $this->redirectToRoute('crm_invoice_show', ['id' => $invoice->getId()]);
    }

    private function renderInvoiceDocument(
        int $id,
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        InvoiceLineItemRepository $invoiceLineItemRepository,
        PaymentAllocationRepository $paymentAllocationRepository,
        InvoiceAccountingService $invoiceAccountingService,
        EntityManagerInterface $entityManager,
        bool $printable,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invoice = $invoiceRepository->findOneByTenantAndId($tenant, $id);
        if (null === $invoice) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $invoice);
        $invoiceAccountingService->refresh($invoice);
        $entityManager->flush();

        return $this->render('crm/invoice/public.html.twig', [
            'tenant' => $tenant,
            'invoice' => $invoice,
            'lineItems' => $invoiceLineItemRepository->findByInvoice($invoice),
            'paymentAllocations' => $paymentAllocationRepository->findByInvoice($invoice),
            'printable' => $printable,
        ]);
    }

    /**
     * @param list<Invoice> $invoices
     *
     * @return array<string, list<array{invoice:Invoice,days:int,range:string}>>
     */
    private function bucketInvoices(array $invoices): array
    {
        $buckets = [
            'current' => [],
            '1-30' => [],
            '31-60' => [],
            '61-90' => [],
            '90+' => [],
        ];

        $today = new \DateTimeImmutable('today');
        foreach ($invoices as $invoice) {
            if (null === $invoice->getDueAt()) {
                $buckets['current'][] = ['invoice' => $invoice, 'days' => 0, 'range' => 'No due date'];
                continue;
            }

            $days = (int) $today->diff($invoice->getDueAt())->format('%r%a');
            $daysOverdue = max(0, -$days);
            $range = $this->agingRange($daysOverdue);
            $buckets[$range][] = [
                'invoice' => $invoice,
                'days' => $daysOverdue,
                'range' => $range,
            ];
        }

        return $buckets;
    }

    private function agingRange(int $daysOverdue): string
    {
        if ($daysOverdue <= 0) {
            return 'current';
        }

        if ($daysOverdue <= 30) {
            return '1-30';
        }

        if ($daysOverdue <= 60) {
            return '31-60';
        }

        if ($daysOverdue <= 90) {
            return '61-90';
        }

        return '90+';
    }

    private function assertEditable(Invoice $invoice): void
    {
        if (Invoice::STATUS_VOID === $invoice->getStatus()) {
            throw $this->createAccessDeniedException('Void invoices cannot be edited.');
        }
    }

    private function renderInvoicePdf(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter');
        $dompdf->render();

        return $dompdf->output();
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

    private function blankToNull(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private function parseDateOrNull(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }

    private function normalizeInvoiceStatus(string $status): string
    {
        $status = trim($status);
        if (!in_array($status, [
            Invoice::STATUS_DRAFT,
            Invoice::STATUS_SENT,
            Invoice::STATUS_UNPAID,
            Invoice::STATUS_PARTIALLY_PAID,
            Invoice::STATUS_PAID,
            Invoice::STATUS_REFUNDED,
            Invoice::STATUS_OVERDUE,
            Invoice::STATUS_VOID,
        ], true)) {
            throw $this->createAccessDeniedException('Invalid invoice status.');
        }

        return $status;
    }
}
