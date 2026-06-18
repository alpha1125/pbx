<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RetentionOpportunityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RetentionOpportunityRepository::class)]
#[ORM\Table(name: 'retention_opportunity')]
#[ORM\Index(name: 'idx_retention_opportunity_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_retention_opportunity_property', columns: ['property_id'])]
#[ORM\Index(name: 'idx_retention_opportunity_status', columns: ['status'])]
#[ORM\Index(name: 'idx_retention_opportunity_lookup', columns: ['tenant_id', 'property_id', 'opportunity_type', 'source_key', 'status'])]
class RetentionOpportunity
{
    public const TYPE_NO_RECENT_SERVICE = 'no_recent_service';
    public const TYPE_OLD_EQUIPMENT = 'old_equipment';
    public const TYPE_NO_RECENT_CALLS = 'no_recent_calls';
    public const TYPE_WARRANTY_NEARING_EXPIRATION = 'warranty_nearing_expiration';
    public const TYPE_DORMANT_CUSTOMER = 'dormant_customer';
    public const TYPE_OPEN_INVOICE = 'open_invoice';
    public const TYPE_MAINTENANCE_PLAN_MISSING = 'maintenance_plan_missing';

    public const STATUS_OPEN = 'open';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_CONVERTED = 'converted';

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
    private ?Equipment $equipment = null;

    #[ORM\Column(length: 50)]
    private string $opportunityType;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_OPEN])]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(length: 255)]
    private string $sourceKey;

    #[ORM\Column(type: Types::TEXT)]
    private string $detectedReason;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $detectedAt;

    public function __construct(
        Tenant $tenant,
        Property $property,
        string $opportunityType,
        string $sourceKey,
        string $detectedReason,
        ?Contact $contact = null,
        ?Equipment $equipment = null,
        ?\DateTimeImmutable $detectedAt = null,
    ) {
        $this->tenant = $tenant;
        $this->property = $property;
        $this->opportunityType = trim($opportunityType);
        $this->sourceKey = trim($sourceKey);
        $this->detectedReason = trim($detectedReason);
        $this->contact = $contact;
        $this->equipment = $equipment;
        $this->detectedAt = $detectedAt ?? new \DateTimeImmutable();
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getProperty(): Property
    {
        return $this->property;
    }

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function setContact(?Contact $contact): static
    {
        $this->contact = $contact;

        return $this;
    }

    public function getEquipment(): ?Equipment
    {
        return $this->equipment;
    }

    public function setEquipment(?Equipment $equipment): static
    {
        $this->equipment = $equipment;

        return $this;
    }

    public function getOpportunityType(): string
    {
        return $this->opportunityType;
    }

    public function setOpportunityType(string $opportunityType): static
    {
        $this->opportunityType = trim($opportunityType);

        return $this;
    }

    public function getOpportunityTypeLabel(): string
    {
        return match ($this->opportunityType) {
            self::TYPE_NO_RECENT_SERVICE => 'No recent service',
            self::TYPE_OLD_EQUIPMENT => 'Old equipment',
            self::TYPE_NO_RECENT_CALLS => 'No recent calls',
            self::TYPE_WARRANTY_NEARING_EXPIRATION => 'Warranty nearing expiration',
            self::TYPE_DORMANT_CUSTOMER => 'Dormant customer',
            self::TYPE_OPEN_INVOICE => 'Open invoice',
            self::TYPE_MAINTENANCE_PLAN_MISSING => 'Maintenance plan missing',
            default => ucfirst(str_replace('_', ' ', $this->opportunityType)),
        };
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = trim($status);

        return $this;
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'Open',
            self::STATUS_REVIEWED => 'Reviewed',
            self::STATUS_DISMISSED => 'Dismissed',
            self::STATUS_CONVERTED => 'Converted',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    public function isOpen(): bool
    {
        return self::STATUS_OPEN === $this->status;
    }

    public function markReviewed(): static
    {
        $this->status = self::STATUS_REVIEWED;

        return $this;
    }

    public function dismiss(): static
    {
        $this->status = self::STATUS_DISMISSED;

        return $this;
    }

    public function convert(): static
    {
        $this->status = self::STATUS_CONVERTED;

        return $this;
    }

    public function getSourceKey(): string
    {
        return $this->sourceKey;
    }

    public function setSourceKey(string $sourceKey): static
    {
        $this->sourceKey = trim($sourceKey);

        return $this;
    }

    public function getDetectedReason(): string
    {
        return $this->detectedReason;
    }

    public function setDetectedReason(string $detectedReason): static
    {
        $this->detectedReason = trim($detectedReason);

        return $this;
    }

    public function getDetectedAt(): \DateTimeImmutable
    {
        return $this->detectedAt;
    }

    public function setDetectedAt(\DateTimeImmutable $detectedAt): static
    {
        $this->detectedAt = $detectedAt;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getOpportunityTypeKeys(): array
    {
        return [
            self::TYPE_NO_RECENT_SERVICE,
            self::TYPE_OLD_EQUIPMENT,
            self::TYPE_NO_RECENT_CALLS,
            self::TYPE_WARRANTY_NEARING_EXPIRATION,
            self::TYPE_DORMANT_CUSTOMER,
            self::TYPE_OPEN_INVOICE,
            self::TYPE_MAINTENANCE_PLAN_MISSING,
        ];
    }

    /**
     * @return list<string>
     */
    public function getStatusKeys(): array
    {
        return [
            self::STATUS_OPEN,
            self::STATUS_REVIEWED,
            self::STATUS_DISMISSED,
            self::STATUS_CONVERTED,
        ];
    }
}
