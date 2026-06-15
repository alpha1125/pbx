<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvoiceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoice')]
#[ORM\UniqueConstraint(name: 'uniq_invoice_number', columns: ['invoice_number'])]
#[ORM\Index(name: 'idx_invoice_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_invoice_property', columns: ['property_id'])]
#[ORM\Index(name: 'idx_invoice_status', columns: ['status'])]
class Invoice
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_VOID = 'void';

    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Property $property;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contact $contact = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Quote $quote = null;

    #[ORM\Column(length: 64)]
    private string $invoiceNumber;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $subtotalCents = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $taxCents = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $totalCents = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $amountPaidCents = 0;

    public function __construct(Tenant $tenant, Property $property, string $invoiceNumber)
    {
        $this->tenant = $tenant;
        $this->property = $property;
        $this->invoiceNumber = trim($invoiceNumber);
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getProperty(): Property { return $this->property; }
    public function getContact(): ?Contact { return $this->contact; }
    public function setContact(?Contact $contact): static { $this->contact = $contact; return $this; }
    public function getQuote(): ?Quote { return $this->quote; }
    public function setQuote(?Quote $quote): static { $this->quote = $quote; return $this; }
    public function getInvoiceNumber(): string { return $this->invoiceNumber; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = trim($status); return $this; }
    public function getIssuedAt(): ?\DateTimeImmutable { return $this->issuedAt; }
    public function setIssuedAt(?\DateTimeImmutable $issuedAt): static { $this->issuedAt = $issuedAt; return $this; }
    public function getDueAt(): ?\DateTimeImmutable { return $this->dueAt; }
    public function setDueAt(?\DateTimeImmutable $dueAt): static { $this->dueAt = $dueAt; return $this; }
    public function getSubtotalCents(): int { return $this->subtotalCents; }
    public function setSubtotalCents(int $subtotalCents): static { $this->subtotalCents = $subtotalCents; return $this; }
    public function getTaxCents(): int { return $this->taxCents; }
    public function setTaxCents(int $taxCents): static { $this->taxCents = $taxCents; return $this; }
    public function getTotalCents(): int { return $this->totalCents; }
    public function setTotalCents(int $totalCents): static { $this->totalCents = $totalCents; return $this; }
    public function getAmountPaidCents(): int { return $this->amountPaidCents; }
    public function setAmountPaidCents(int $amountPaidCents): static { $this->amountPaidCents = $amountPaidCents; return $this; }
}
