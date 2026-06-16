<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvoiceAccountingSyncRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceAccountingSyncRecordRepository::class)]
#[ORM\Table(name: 'invoice_accounting_sync_record')]
#[ORM\UniqueConstraint(name: 'uniq_invoice_accounting_sync_record_invoice_provider', columns: ['invoice_id', 'provider'])]
#[ORM\Index(name: 'idx_invoice_accounting_sync_record_invoice', columns: ['invoice_id'])]
#[ORM\Index(name: 'idx_invoice_accounting_sync_record_provider', columns: ['provider'])]
#[ORM\Index(name: 'idx_invoice_accounting_sync_record_status', columns: ['status'])]
class InvoiceAccountingSyncRecord
{
    public const PROVIDER_QUICKBOOKS_ONLINE = 'quickbooks_online';
    public const PROVIDER_XERO = 'xero';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RETRY_SCHEDULED = 'retry_scheduled';
    public const STATUS_EXPORTED = 'exported';
    public const STATUS_FAILED = 'failed';

    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\Column(length: 50)]
    private string $provider;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextRetryAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalNumber = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $exportedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $errorContext = null;

    public function __construct(Invoice $invoice, string $provider)
    {
        $this->invoice = $invoice;
        $this->provider = $provider;
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $provider): static { $this->provider = $provider; return $this; }
    public function getProviderLabel(): string
    {
        return match ($this->provider) {
            self::PROVIDER_QUICKBOOKS_ONLINE => 'QuickBooks Online',
            self::PROVIDER_XERO => 'Xero',
            default => ucfirst(str_replace('_', ' ', $this->provider)),
        };
    }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending export',
            self::STATUS_RETRY_SCHEDULED => 'Retry scheduled',
            self::STATUS_EXPORTED => 'Exported',
            self::STATUS_FAILED => 'Export failed',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }
    public function getRetryCount(): int { return $this->retryCount; }
    public function setRetryCount(int $retryCount): static { $this->retryCount = max(0, $retryCount); return $this; }
    public function incrementRetryCount(): static { $this->retryCount = max(0, $this->retryCount + 1); return $this; }
    public function getLastAttemptAt(): ?\DateTimeImmutable { return $this->lastAttemptAt; }
    public function setLastAttemptAt(?\DateTimeImmutable $lastAttemptAt): static { $this->lastAttemptAt = $lastAttemptAt; return $this; }
    public function getNextRetryAt(): ?\DateTimeImmutable { return $this->nextRetryAt; }
    public function setNextRetryAt(?\DateTimeImmutable $nextRetryAt): static { $this->nextRetryAt = $nextRetryAt; return $this; }
    public function getExternalId(): ?string { return $this->externalId; }
    public function setExternalId(?string $externalId): static { $this->externalId = null !== $externalId ? trim($externalId) : null; return $this; }
    public function getExternalNumber(): ?string { return $this->externalNumber; }
    public function setExternalNumber(?string $externalNumber): static { $this->externalNumber = null !== $externalNumber ? trim($externalNumber) : null; return $this; }
    public function getExportedAt(): ?\DateTimeImmutable { return $this->exportedAt; }
    public function setExportedAt(?\DateTimeImmutable $exportedAt): static { $this->exportedAt = $exportedAt; return $this; }
    public function getSyncedAt(): ?\DateTimeImmutable { return $this->syncedAt; }
    public function setSyncedAt(?\DateTimeImmutable $syncedAt): static { $this->syncedAt = $syncedAt; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): static { $this->errorMessage = null !== $errorMessage ? trim($errorMessage) : null; return $this; }
    /** @return array<string, mixed>|null */
    public function getErrorContext(): ?array { return $this->errorContext; }
    /** @param array<string, mixed>|null $errorContext */
    public function setErrorContext(?array $errorContext): static { $this->errorContext = $errorContext; return $this; }
    public function markPending(): static
    {
        $this->status = self::STATUS_PENDING;
        $this->retryCount = 0;
        $this->externalId = null;
        $this->externalNumber = null;
        $this->exportedAt = null;
        $this->syncedAt = null;
        $this->lastAttemptAt = null;
        $this->nextRetryAt = null;
        $this->errorMessage = null;
        $this->errorContext = null;

        return $this;
    }
    public function markRetryScheduled(\DateTimeImmutable $nextRetryAt, ?string $errorMessage = null, ?array $errorContext = null): static
    {
        $this->status = self::STATUS_RETRY_SCHEDULED;
        $this->incrementRetryCount();
        $this->lastAttemptAt = new \DateTimeImmutable();
        $this->nextRetryAt = $nextRetryAt;
        $this->errorMessage = null !== $errorMessage ? trim($errorMessage) : null;
        $this->errorContext = $errorContext;

        return $this;
    }
    public function markExported(string $externalId, ?string $externalNumber = null, ?\DateTimeImmutable $exportedAt = null): static
    {
        $this->status = self::STATUS_EXPORTED;
        $this->lastAttemptAt = new \DateTimeImmutable();
        $this->nextRetryAt = null;
        $this->externalId = trim($externalId);
        $this->externalNumber = null !== $externalNumber ? trim($externalNumber) : null;
        $this->exportedAt = $exportedAt ?? new \DateTimeImmutable();
        $this->syncedAt = null;
        $this->errorMessage = null;
        $this->errorContext = null;

        return $this;
    }
    /** @param array<string, mixed>|null $errorContext */
    public function markFailed(string $errorMessage, ?array $errorContext = null): static
    {
        $this->status = self::STATUS_FAILED;
        $this->lastAttemptAt = new \DateTimeImmutable();
        $this->nextRetryAt = null;
        $this->errorMessage = trim($errorMessage);
        $this->errorContext = $errorContext;

        return $this;
    }
}
