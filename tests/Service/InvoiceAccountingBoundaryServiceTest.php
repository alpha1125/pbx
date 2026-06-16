<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Invoice;
use App\Entity\InvoiceAccountingSyncRecord;
use App\Entity\Property;
use App\Entity\Tenant;
use App\Repository\InvoiceAccountingSyncRecordRepository;
use App\Service\AuditLogger;
use App\Service\InvoiceAccountingBoundaryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class InvoiceAccountingBoundaryServiceTest extends TestCase
{
    public function testBeginExportCreatesPendingRecord(): void
    {
        $invoice = $this->createInvoice();
        $persisted = null;

        $repository = $this->createMock(InvoiceAccountingSyncRecordRepository::class);
        $repository->expects(self::once())
            ->method('findOneByInvoiceAndProvider')
            ->with($invoice, InvoiceAccountingSyncRecord::PROVIDER_QUICKBOOKS_ONLINE)
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (object $record) use (&$persisted): bool {
                $persisted = $record;

                return $record instanceof InvoiceAccountingSyncRecord;
            }));
        $entityManager->expects(self::once())->method('flush');

        $service = new InvoiceAccountingBoundaryService($repository, $entityManager, $this->createStub(AuditLogger::class));
        $record = $service->beginExport($invoice, InvoiceAccountingSyncRecord::PROVIDER_QUICKBOOKS_ONLINE);

        self::assertSame($record, $persisted);
        self::assertSame(InvoiceAccountingSyncRecord::STATUS_PENDING, $record->getStatus());
        self::assertSame(InvoiceAccountingSyncRecord::PROVIDER_QUICKBOOKS_ONLINE, $record->getProvider());
        self::assertNull($record->getExternalId());
    }

    public function testMarkExportedAndFailedUpdateTheSameProviderRecord(): void
    {
        $invoice = $this->createInvoice();
        $record = new InvoiceAccountingSyncRecord($invoice, InvoiceAccountingSyncRecord::PROVIDER_XERO);

        $repository = $this->createMock(InvoiceAccountingSyncRecordRepository::class);
        $repository->expects(self::exactly(2))
            ->method('findOneByInvoiceAndProvider')
            ->with($invoice, InvoiceAccountingSyncRecord::PROVIDER_XERO)
            ->willReturn($record);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::exactly(2))->method('flush');

        $service = new InvoiceAccountingBoundaryService($repository, $entityManager, $this->createStub(AuditLogger::class));
        $exported = $service->markExported($invoice, InvoiceAccountingSyncRecord::PROVIDER_XERO, 'xero-123', 'X-9001');
        self::assertSame($record, $exported);
        self::assertSame(InvoiceAccountingSyncRecord::STATUS_EXPORTED, $exported->getStatus());
        self::assertSame('xero-123', $exported->getExternalId());
        self::assertSame('X-9001', $exported->getExternalNumber());
        self::assertNotNull($exported->getExportedAt());

        $failed = $service->markFailed($invoice, InvoiceAccountingSyncRecord::PROVIDER_XERO, 'API down', ['httpStatus' => 503]);

        self::assertSame($record, $failed);
        self::assertSame(InvoiceAccountingSyncRecord::STATUS_FAILED, $failed->getStatus());
        self::assertSame('API down', $failed->getErrorMessage());
        self::assertSame(['httpStatus' => 503], $failed->getErrorContext());
    }

    public function testRejectsUnsupportedProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $invoice = $this->createInvoice();
        $repository = $this->createStub(InvoiceAccountingSyncRecordRepository::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);

        $service = new InvoiceAccountingBoundaryService($repository, $entityManager, $this->createStub(AuditLogger::class));
        $service->beginExport($invoice, 'sage');
    }

    public function testScheduleRetrySetsScheduledState(): void
    {
        $invoice = $this->createInvoice();
        $record = new InvoiceAccountingSyncRecord($invoice, InvoiceAccountingSyncRecord::PROVIDER_QUICKBOOKS_ONLINE);

        $repository = $this->createMock(InvoiceAccountingSyncRecordRepository::class);
        $repository->expects(self::once())
            ->method('findOneByInvoiceAndProvider')
            ->with($invoice, InvoiceAccountingSyncRecord::PROVIDER_QUICKBOOKS_ONLINE)
            ->willReturn($record);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = new InvoiceAccountingBoundaryService($repository, $entityManager, $this->createStub(AuditLogger::class));
        $nextRetryAt = new \DateTimeImmutable('+30 minutes');
        $scheduled = $service->scheduleRetry(
            $invoice,
            InvoiceAccountingSyncRecord::PROVIDER_QUICKBOOKS_ONLINE,
            $nextRetryAt,
            'Temporary export error',
            ['attempt' => 2],
        );

        self::assertSame($record, $scheduled);
        self::assertSame(InvoiceAccountingSyncRecord::STATUS_RETRY_SCHEDULED, $scheduled->getStatus());
        self::assertSame(1, $scheduled->getRetryCount());
        self::assertSame($nextRetryAt, $scheduled->getNextRetryAt());
        self::assertNotNull($scheduled->getLastAttemptAt());
        self::assertSame('Temporary export error', $scheduled->getErrorMessage());
        self::assertSame(['attempt' => 2], $scheduled->getErrorContext());
    }

    public function testMarkFailedWritesAccountingAuditLog(): void
    {
        $invoice = $this->createInvoice();
        $record = new InvoiceAccountingSyncRecord($invoice, InvoiceAccountingSyncRecord::PROVIDER_QUICKBOOKS_ONLINE);

        $repository = $this->createMock(InvoiceAccountingSyncRecordRepository::class);
        $repository->expects(self::once())
            ->method('findOneByInvoiceAndProvider')
            ->with($invoice, InvoiceAccountingSyncRecord::PROVIDER_QUICKBOOKS_ONLINE)
            ->willReturn($record);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                $invoice->getTenant(),
                'invoice',
                $invoice->getInvoiceNumber(),
                'invoice.accounting_export_failed',
            self::arrayHasKey('provider'),
            self::callback(static function (?array $afterData): bool {
                return is_array($afterData)
                    && 'failed' === ($afterData['status'] ?? null)
                    && 'API timeout' === ($afterData['errorMessage'] ?? null);
            }),
            self::callback(static function (?array $metadata): bool {
                return is_array($metadata)
                    && array_key_exists('invoiceId', $metadata)
                    && array_key_exists('propertyId', $metadata);
            }),
        );

        $service = new InvoiceAccountingBoundaryService($repository, $entityManager, $auditLogger);
        $service->markFailed($invoice, InvoiceAccountingSyncRecord::PROVIDER_QUICKBOOKS_ONLINE, 'API timeout', ['code' => 504]);
    }

    private function createInvoice(): Invoice
    {
        $tenant = new Tenant('Boundary Tenant');
        $property = new Property($tenant, '1 Boundary Way', 'Toronto', 'ON', 'M1M1M1');

        return new Invoice($tenant, $property, 'I-BOUNDARY');
    }
}
