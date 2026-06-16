<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\InvoiceAccountingSyncRecord;
use App\Service\AuditLogger;
use App\Repository\InvoiceAccountingSyncRecordRepository;
use Doctrine\ORM\EntityManagerInterface;

final class InvoiceAccountingBoundaryService
{
    public function __construct(
        private readonly InvoiceAccountingSyncRecordRepository $syncRecordRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function beginExport(Invoice $invoice, string $provider): InvoiceAccountingSyncRecord
    {
        $record = $this->getOrCreateRecord($invoice, $provider);
        $record->markPending()->touch();
        $this->auditLogger->log(
            $invoice->getTenant(),
            'invoice',
            $invoice->getInvoiceNumber(),
            'invoice.accounting_export_started',
            ['provider' => $provider],
            [
                'provider' => $provider,
                'status' => $record->getStatus(),
                'retryCount' => $record->getRetryCount(),
            ],
            ['invoiceId' => $invoice->getId(), 'propertyId' => $invoice->getProperty()->getId()],
        );
        $this->entityManager->flush();

        return $record;
    }

    public function markExported(
        Invoice $invoice,
        string $provider,
        string $externalId,
        ?string $externalNumber = null,
        ?\DateTimeImmutable $exportedAt = null,
    ): InvoiceAccountingSyncRecord {
        $record = $this->getOrCreateRecord($invoice, $provider);
        $record->markExported($externalId, $externalNumber, $exportedAt)->touch();
        $this->auditLogger->log(
            $invoice->getTenant(),
            'invoice',
            $invoice->getInvoiceNumber(),
            'invoice.accounting_export_succeeded',
            ['provider' => $provider],
            [
                'provider' => $provider,
                'status' => $record->getStatus(),
                'externalId' => $record->getExternalId(),
                'externalNumber' => $record->getExternalNumber(),
                'exportedAt' => $record->getExportedAt()?->format(DATE_ATOM),
            ],
            ['invoiceId' => $invoice->getId(), 'propertyId' => $invoice->getProperty()->getId()],
        );
        $this->entityManager->flush();

        return $record;
    }

    /**
     * @param array<string, mixed>|null $errorContext
     */
    public function markFailed(
        Invoice $invoice,
        string $provider,
        string $errorMessage,
        ?array $errorContext = null,
    ): InvoiceAccountingSyncRecord {
        $record = $this->getOrCreateRecord($invoice, $provider);
        $record->markFailed($errorMessage, $errorContext)->touch();
        $this->auditLogger->log(
            $invoice->getTenant(),
            'invoice',
            $invoice->getInvoiceNumber(),
            'invoice.accounting_export_failed',
            ['provider' => $provider],
            [
                'provider' => $provider,
                'status' => $record->getStatus(),
                'errorMessage' => $record->getErrorMessage(),
                'errorContext' => $record->getErrorContext(),
                'retryCount' => $record->getRetryCount(),
            ],
            ['invoiceId' => $invoice->getId(), 'propertyId' => $invoice->getProperty()->getId()],
        );
        $this->entityManager->flush();

        return $record;
    }

    /**
     * @param array<string, mixed>|null $errorContext
     */
    public function scheduleRetry(
        Invoice $invoice,
        string $provider,
        \DateTimeImmutable $nextRetryAt,
        ?string $errorMessage = null,
        ?array $errorContext = null,
    ): InvoiceAccountingSyncRecord {
        $record = $this->getOrCreateRecord($invoice, $provider);
        $record->markRetryScheduled($nextRetryAt, $errorMessage, $errorContext)->touch();
        $this->auditLogger->log(
            $invoice->getTenant(),
            'invoice',
            $invoice->getInvoiceNumber(),
            'invoice.accounting_retry_scheduled',
            ['provider' => $provider],
            [
                'provider' => $provider,
                'status' => $record->getStatus(),
                'errorMessage' => $record->getErrorMessage(),
                'errorContext' => $record->getErrorContext(),
                'retryCount' => $record->getRetryCount(),
                'nextRetryAt' => $record->getNextRetryAt()?->format(DATE_ATOM),
            ],
            ['invoiceId' => $invoice->getId(), 'propertyId' => $invoice->getProperty()->getId()],
        );
        $this->entityManager->flush();

        return $record;
    }

    /** @return list<InvoiceAccountingSyncRecord> */
    public function findRecords(Invoice $invoice): array
    {
        return $this->syncRecordRepository->findByInvoice($invoice);
    }

    private function getOrCreateRecord(Invoice $invoice, string $provider): InvoiceAccountingSyncRecord
    {
        if (!in_array($provider, [
            InvoiceAccountingSyncRecord::PROVIDER_QUICKBOOKS_ONLINE,
            InvoiceAccountingSyncRecord::PROVIDER_XERO,
        ], true)) {
            throw new \InvalidArgumentException('Unsupported accounting provider.');
        }

        $record = $this->syncRecordRepository->findOneByInvoiceAndProvider($invoice, $provider);
        if ($record instanceof InvoiceAccountingSyncRecord) {
            return $record;
        }

        $record = new InvoiceAccountingSyncRecord($invoice, $provider);
        $this->entityManager->persist($record);

        return $record;
    }
}
