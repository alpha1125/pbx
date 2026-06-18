<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NextBestActionSuggestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: NextBestActionSuggestionRepository::class)]
#[ORM\Table(name: 'next_best_action_suggestion')]
#[ORM\UniqueConstraint(name: 'uniq_next_best_action_source', columns: ['tenant_id', 'property_id', 'suggestion_type', 'source_key'])]
#[ORM\Index(name: 'idx_next_best_action_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_next_best_action_property', columns: ['property_id'])]
#[ORM\Index(name: 'idx_next_best_action_status', columns: ['status'])]
#[ORM\Index(name: 'idx_next_best_action_type', columns: ['suggestion_type'])]
class NextBestActionSuggestion
{
    public const TYPE_BOOK_MAINTENANCE = 'book_maintenance';
    public const TYPE_CALL_CUSTOMER = 'call_customer';
    public const TYPE_REPLACE_EQUIPMENT = 'replace_equipment';
    public const TYPE_OFFER_MAINTENANCE_PLAN = 'offer_maintenance_plan';
    public const TYPE_INSPECT_SYSTEM = 'inspect_system';
    public const TYPE_SCHEDULE_FOLLOW_UP = 'schedule_follow_up';
    public const TYPE_REVIEW_OVERDUE_INVOICE = 'review_overdue_invoice';

    public const CONFIDENCE_LOW = 'low';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_HIGH = 'high';

    public const STATUS_SUGGESTED = 'suggested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_COMPLETED = 'completed';

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
    private ?RetentionOpportunity $opportunity = null;

    #[Assert\NotBlank]
    #[ORM\Column(length: 50)]
    private string $suggestionType;

    #[Assert\NotBlank]
    #[ORM\Column(length: 255)]
    private string $sourceKey;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[Assert\Choice(choices: [self::CONFIDENCE_LOW, self::CONFIDENCE_MEDIUM, self::CONFIDENCE_HIGH])]
    #[ORM\Column(length: 20, options: ['default' => self::CONFIDENCE_MEDIUM])]
    private string $confidence = self::CONFIDENCE_MEDIUM;

    #[Assert\Choice(choices: [self::STATUS_SUGGESTED, self::STATUS_APPROVED, self::STATUS_DISMISSED, self::STATUS_COMPLETED])]
    #[ORM\Column(length: 20, options: ['default' => self::STATUS_SUGGESTED])]
    private string $status = self::STATUS_SUGGESTED;

    public function __construct(
        Tenant $tenant,
        Property $property,
        string $suggestionType,
        string $sourceKey,
        string $reason,
        string $confidence = self::CONFIDENCE_MEDIUM,
        ?RetentionOpportunity $opportunity = null,
    ) {
        $this->tenant = $tenant;
        $this->property = $property;
        $this->suggestionType = trim($suggestionType);
        $this->sourceKey = trim($sourceKey);
        $this->reason = trim($reason);
        $this->confidence = trim($confidence);
        $this->opportunity = $opportunity;
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

    public function getOpportunity(): ?RetentionOpportunity
    {
        return $this->opportunity;
    }

    public function setOpportunity(?RetentionOpportunity $opportunity): static
    {
        $this->opportunity = $opportunity;

        return $this;
    }

    public function getSuggestionType(): string
    {
        return $this->suggestionType;
    }

    public function setSuggestionType(string $suggestionType): static
    {
        $this->suggestionType = trim($suggestionType);

        return $this;
    }

    public function getTypeLabel(): string
    {
        return match ($this->suggestionType) {
            self::TYPE_BOOK_MAINTENANCE => 'Book Maintenance',
            self::TYPE_CALL_CUSTOMER => 'Call Customer',
            self::TYPE_REPLACE_EQUIPMENT => 'Replace Equipment',
            self::TYPE_OFFER_MAINTENANCE_PLAN => 'Offer Maintenance Plan',
            self::TYPE_INSPECT_SYSTEM => 'Inspect System',
            self::TYPE_SCHEDULE_FOLLOW_UP => 'Schedule Follow Up',
            self::TYPE_REVIEW_OVERDUE_INVOICE => 'Review Overdue Invoice',
            default => ucfirst(str_replace('_', ' ', $this->suggestionType)),
        };
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

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): static
    {
        $this->reason = trim($reason);

        return $this;
    }

    public function getConfidence(): string
    {
        return $this->confidence;
    }

    public function setConfidence(string $confidence): static
    {
        $this->confidence = trim($confidence);

        return $this;
    }

    public function getConfidenceLabel(): string
    {
        return match ($this->confidence) {
            self::CONFIDENCE_LOW => 'Low',
            self::CONFIDENCE_MEDIUM => 'Medium',
            self::CONFIDENCE_HIGH => 'High',
            default => ucfirst($this->confidence),
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
            self::STATUS_SUGGESTED => 'Suggested',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_DISMISSED => 'Dismissed',
            self::STATUS_COMPLETED => 'Completed',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    public function isSuggested(): bool
    {
        return self::STATUS_SUGGESTED === $this->status;
    }

    public function approve(): static
    {
        $this->status = self::STATUS_APPROVED;
        $this->touch();

        return $this;
    }

    public function dismiss(): static
    {
        $this->status = self::STATUS_DISMISSED;
        $this->touch();

        return $this;
    }

    public function complete(): static
    {
        $this->status = self::STATUS_COMPLETED;
        $this->touch();

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public static function getTypeChoices(): array
    {
        return [
            self::TYPE_BOOK_MAINTENANCE => 'Book Maintenance',
            self::TYPE_CALL_CUSTOMER => 'Call Customer',
            self::TYPE_REPLACE_EQUIPMENT => 'Replace Equipment',
            self::TYPE_OFFER_MAINTENANCE_PLAN => 'Offer Maintenance Plan',
            self::TYPE_INSPECT_SYSTEM => 'Inspect System',
            self::TYPE_SCHEDULE_FOLLOW_UP => 'Schedule Follow Up',
            self::TYPE_REVIEW_OVERDUE_INVOICE => 'Review Overdue Invoice',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getConfidenceChoices(): array
    {
        return [
            self::CONFIDENCE_LOW => 'Low',
            self::CONFIDENCE_MEDIUM => 'Medium',
            self::CONFIDENCE_HIGH => 'High',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getStatusChoices(): array
    {
        return [
            self::STATUS_SUGGESTED => 'Suggested',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_DISMISSED => 'Dismissed',
            self::STATUS_COMPLETED => 'Completed',
        ];
    }
}
