<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EstimateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EstimateRepository::class)]
#[ORM\Table(name: 'estimate')]
#[ORM\Index(name: 'idx_estimate_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_estimate_property', columns: ['property_id'])]
#[ORM\Index(name: 'idx_estimate_status', columns: ['status'])]
class Estimate
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_APPROVED_FOR_QUOTE = 'approved_for_quote';
    public const STATUS_CONVERTED_TO_QUOTE = 'converted_to_quote';
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
    private ?RfqInvitation $rfqInvitation = null;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $exclusions = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $assumptions = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $subtotalCents = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $taxCents = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $totalCents = 0;

    public function __construct(Tenant $tenant, Property $property)
    {
        $this->tenant = $tenant;
        $this->property = $property;
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getProperty(): Property { return $this->property; }
    public function getContact(): ?Contact { return $this->contact; }
    public function setContact(?Contact $contact): static { $this->contact = $contact; return $this; }
    public function getRfqInvitation(): ?RfqInvitation { return $this->rfqInvitation; }
    public function setRfqInvitation(?RfqInvitation $rfqInvitation): static { $this->rfqInvitation = $rfqInvitation; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = trim($status); return $this; }
    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): static { $this->title = null !== $title ? trim($title) : null; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }
    public function getExclusions(): ?string { return $this->exclusions; }
    public function setExclusions(?string $exclusions): static { $this->exclusions = $exclusions; return $this; }
    public function getAssumptions(): ?string { return $this->assumptions; }
    public function setAssumptions(?string $assumptions): static { $this->assumptions = $assumptions; return $this; }
    public function getSubtotalCents(): int { return $this->subtotalCents; }
    public function setSubtotalCents(int $subtotalCents): static { $this->subtotalCents = $subtotalCents; return $this; }
    public function getTaxCents(): int { return $this->taxCents; }
    public function setTaxCents(int $taxCents): static { $this->taxCents = $taxCents; return $this; }
    public function getTotalCents(): int { return $this->totalCents; }
    public function setTotalCents(int $totalCents): static { $this->totalCents = $totalCents; return $this; }
}
