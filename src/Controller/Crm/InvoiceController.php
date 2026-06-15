<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\Invoice;
use App\Repository\InvoiceLineItemRepository;
use App\Repository\InvoiceRepository;
use App\Service\AuditLogger;
use App\Service\CurrentTenantProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InvoiceController extends AbstractController
{
    #[Route('/crm/invoices/{id<\d+>}', name: 'crm_invoice_show', methods: ['GET'])]
    public function show(
        int $id,
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        InvoiceLineItemRepository $invoiceLineItemRepository,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invoice = $invoiceRepository->findOneByTenantAndId($tenant, $id);
        if (null === $invoice) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        return $this->render('crm/invoice/show.html.twig', [
            'tenant' => $tenant,
            'invoice' => $invoice,
            'lineItems' => $invoiceLineItemRepository->findByInvoice($invoice),
        ]);
    }

    #[Route('/crm/invoices/{id<\d+>}/status', name: 'crm_invoice_update_status', methods: ['POST'])]
    public function updateStatus(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        InvoiceRepository $invoiceRepository,
        AuditLogger $auditLogger,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invoice = $invoiceRepository->findOneByTenantAndId($tenant, $id);
        if (null === $invoice) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        if (!$this->isCsrfTokenValid('invoice_status_'.$invoice->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $newStatus = trim((string) $request->request->get('status'));
        if (!in_array($newStatus, [
            Invoice::STATUS_DRAFT,
            Invoice::STATUS_SENT,
            Invoice::STATUS_PARTIALLY_PAID,
            Invoice::STATUS_PAID,
            Invoice::STATUS_OVERDUE,
            Invoice::STATUS_VOID,
        ], true)) {
            throw $this->createAccessDeniedException('Invalid invoice status.');
        }

        $before = ['status' => $invoice->getStatus()];
        $invoice->setStatus($newStatus)->touch();
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

        return $this->redirectToRoute('crm_invoice_show', ['id' => $invoice->getId()]);
    }
}
