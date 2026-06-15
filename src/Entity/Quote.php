<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuoteRepository::class)]
#[ORM\Table(name: 'quote')]
#[ORM\UniqueConstraint(name: 'uniq_quote_number', columns: ['quote_number'])]
#[ORM\Index(name: 'idx_quote_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_quote_property', columns: ['property_id'])]
#[ORM\Index(name: 'idx_quote_status', columns: ['status'])]
class Quote
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_VIEWED = 'viewed';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

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
    private ?Estimate $estimate = null;

    #[ORM\Column(length: 64)]
    private string $quoteNumber;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $subtotalCents = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $taxCents = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $totalCents = 0;

    public function __construct(Tenant $tenant, Property $property, string $quoteNumber)
    {
        $this->tenant = $tenant;
        $this->property = $property;
        $this->quoteNumber = trim($quoteNumber);
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getProperty(): Property { return $this->property; }
    public function getContact(): ?Contact { return $this->contact; }
    public function setContact(?Contact $contact): static { $this->contact = $contact; return $this; }
    public function getEstimate(): ?Estimate { return $this->estimate; }
    public function setEstimate(?Estimate $estimate): static { $this->estimate = $estimate; return $this; }
    public function getQuoteNumber(): string { return $this->quoteNumber; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = trim($status); return $this; }
    public function getValidUntil(): ?\DateTimeImmutable { return $this->validUntil; }
    public function setValidUntil(?\DateTimeImmutable $validUntil): static { $this->validUntil = $validUntil; return $this; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function setSentAt(?\DateTimeImmutable $sentAt): static { $this->sentAt = $sentAt; return $this; }
    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }
    public function setAcceptedAt(?\DateTimeImmutable $acceptedAt): static { $this->acceptedAt = $acceptedAt; return $this; }
    public function getSubtotalCents(): int { return $this->subtotalCents; }
    public function setSubtotalCents(int $subtotalCents): static { $this->subtotalCents = $subtotalCents; return $this; }
    public function getTaxCents(): int { return $this->taxCents; }
    public function setTaxCents(int $taxCents): static { $this->taxCents = $taxCents; return $this; }
    public function getTotalCents(): int { return $this->totalCents; }
    public function setTotalCents(int $totalCents): static { $this->totalCents = $totalCents; return $this; }
}
