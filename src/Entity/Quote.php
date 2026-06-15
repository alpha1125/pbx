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
#[ORM\Index(name: 'idx_quote_parent_quote', columns: ['parent_quote_id'])]
#[ORM\Index(name: 'idx_quote_root_quote', columns: ['root_quote_id'])]
class Quote
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_SENT = 'sent';
    public const STATUS_VIEWED = 'viewed';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_SUPERSEDED = 'superseded';

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

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $revisionNumber = 1;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Quote $parentQuote = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Quote $rootQuote = null;

    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $shareToken = null;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $viewedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $declinedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $internalReviewAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $subtotalCents = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $taxCents = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $totalCents = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $discountCents = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $depositCents = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $financingNotes = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $acceptedByName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $acceptedByEmail = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $acceptedMessage = null;

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
    public function getRevisionNumber(): int { return $this->revisionNumber; }
    public function setRevisionNumber(int $revisionNumber): static { $this->revisionNumber = max(1, $revisionNumber); return $this; }
    public function getParentQuote(): ?Quote { return $this->parentQuote; }
    public function setParentQuote(?Quote $parentQuote): static { $this->parentQuote = $parentQuote; return $this; }
    public function getRootQuote(): ?Quote { return $this->rootQuote; }
    public function setRootQuote(?Quote $rootQuote): static { $this->rootQuote = $rootQuote; return $this; }
    public function getShareToken(): ?string { return $this->shareToken; }
    public function setShareToken(?string $shareToken): static { $this->shareToken = null !== $shareToken ? trim($shareToken) : null; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = trim($status); return $this; }
    public function getValidUntil(): ?\DateTimeImmutable { return $this->validUntil; }
    public function setValidUntil(?\DateTimeImmutable $validUntil): static { $this->validUntil = $validUntil; return $this; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function setSentAt(?\DateTimeImmutable $sentAt): static { $this->sentAt = $sentAt; return $this; }
    public function getViewedAt(): ?\DateTimeImmutable { return $this->viewedAt; }
    public function setViewedAt(?\DateTimeImmutable $viewedAt): static { $this->viewedAt = $viewedAt; return $this; }
    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }
    public function setAcceptedAt(?\DateTimeImmutable $acceptedAt): static { $this->acceptedAt = $acceptedAt; return $this; }
    public function getDeclinedAt(): ?\DateTimeImmutable { return $this->declinedAt; }
    public function setDeclinedAt(?\DateTimeImmutable $declinedAt): static { $this->declinedAt = $declinedAt; return $this; }
    public function getInternalReviewAt(): ?\DateTimeImmutable { return $this->internalReviewAt; }
    public function setInternalReviewAt(?\DateTimeImmutable $internalReviewAt): static { $this->internalReviewAt = $internalReviewAt; return $this; }
    public function getSubtotalCents(): int { return $this->subtotalCents; }
    public function setSubtotalCents(int $subtotalCents): static { $this->subtotalCents = $subtotalCents; return $this; }
    public function getTaxCents(): int { return $this->taxCents; }
    public function setTaxCents(int $taxCents): static { $this->taxCents = $taxCents; return $this; }
    public function getTotalCents(): int { return $this->totalCents; }
    public function setTotalCents(int $totalCents): static { $this->totalCents = $totalCents; return $this; }
    public function getDiscountCents(): int { return $this->discountCents; }
    public function setDiscountCents(int $discountCents): static { $this->discountCents = max(0, $discountCents); return $this; }
    public function getDepositCents(): int { return $this->depositCents; }
    public function setDepositCents(int $depositCents): static { $this->depositCents = max(0, $depositCents); return $this; }
    public function getFinancingNotes(): ?string { return $this->financingNotes; }
    public function setFinancingNotes(?string $financingNotes): static { $this->financingNotes = null !== $financingNotes ? trim($financingNotes) : null; return $this; }
    public function getAcceptedByName(): ?string { return $this->acceptedByName; }
    public function setAcceptedByName(?string $acceptedByName): static { $this->acceptedByName = null !== $acceptedByName ? trim($acceptedByName) : null; return $this; }
    public function getAcceptedByEmail(): ?string { return $this->acceptedByEmail; }
    public function setAcceptedByEmail(?string $acceptedByEmail): static { $this->acceptedByEmail = null !== $acceptedByEmail ? trim($acceptedByEmail) : null; return $this; }
    public function getAcceptedMessage(): ?string { return $this->acceptedMessage; }
    public function setAcceptedMessage(?string $acceptedMessage): static { $this->acceptedMessage = $acceptedMessage; return $this; }
}
