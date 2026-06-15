<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RfqInvitationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RfqInvitationRepository::class)]
#[ORM\Table(name: 'rfq_invitation')]
#[ORM\Index(name: 'idx_rfq_invitation_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_rfq_invitation_status', columns: ['status'])]
class RfqInvitation
{
    public const STATUS_SENT = 'sent';
    public const STATUS_VIEWED = 'viewed';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_ACCEPTED_FOR_QUOTE = 'accepted_for_quote';
    public const STATUS_QUOTE_SUBMITTED = 'quote_submitted';
    public const STATUS_EXPIRED = 'expired';

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
    private Rfq $rfq;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_SENT;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $declinedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Property $createdProperty = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Estimate $createdEstimate = null;

    public function __construct(Tenant $tenant, Rfq $rfq)
    {
        $this->tenant = $tenant;
        $this->rfq = $rfq;
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getRfq(): Rfq { return $this->rfq; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = trim($status); return $this; }
    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }
    public function setAcceptedAt(?\DateTimeImmutable $acceptedAt): static { $this->acceptedAt = $acceptedAt; return $this; }
    public function getDeclinedAt(): ?\DateTimeImmutable { return $this->declinedAt; }
    public function setDeclinedAt(?\DateTimeImmutable $declinedAt): static { $this->declinedAt = $declinedAt; return $this; }
    public function getCreatedProperty(): ?Property { return $this->createdProperty; }
    public function setCreatedProperty(?Property $createdProperty): static { $this->createdProperty = $createdProperty; return $this; }
    public function getCreatedEstimate(): ?Estimate { return $this->createdEstimate; }
    public function setCreatedEstimate(?Estimate $createdEstimate): static { $this->createdEstimate = $createdEstimate; return $this; }
}
