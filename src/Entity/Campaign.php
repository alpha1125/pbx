<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CampaignRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CampaignRepository::class)]
#[ORM\Table(name: 'campaign')]
#[ORM\Index(name: 'idx_campaign_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_campaign_status', columns: ['status'])]
#[ORM\Index(name: 'idx_campaign_scheduled_date', columns: ['scheduled_date'])]
class Campaign
{
    public const TYPE_SPRING_AC_TUNE_UP = 'spring_ac_tune_up';
    public const TYPE_FALL_FURNACE_INSPECTION = 'fall_furnace_inspection';
    public const TYPE_FILTER_REPLACEMENT = 'filter_replacement';
    public const TYPE_WARRANTY_REMINDER = 'warranty_reminder';
    public const TYPE_MAINTENANCE_RENEWAL = 'maintenance_renewal';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100)]
    private string $name;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::TYPE_SPRING_AC_TUNE_UP,
        self::TYPE_FALL_FURNACE_INSPECTION,
        self::TYPE_FILTER_REPLACEMENT,
        self::TYPE_WARRANTY_REMINDER,
        self::TYPE_MAINTENANCE_RENEWAL,
    ])]
    #[ORM\Column(length: 50)]
    private string $campaignType = self::TYPE_SPRING_AC_TUNE_UP;

    #[Assert\NotBlank]
    #[Assert\Length(max: 1000)]
    #[ORM\Column(type: Types::TEXT)]
    private string $audienceDescription;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledDate = null;

    #[Assert\Choice(choices: [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
        self::STATUS_APPROVED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ])]
    #[ORM\Column(length: 20, options: ['default' => self::STATUS_DRAFT])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct(Tenant $tenant, string $name)
    {
        $this->tenant = $tenant;
        $this->name = trim($name);
        $this->audienceDescription = '';
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getCampaignType(): string
    {
        return $this->campaignType;
    }

    public function setCampaignType(string $campaignType): static
    {
        $this->campaignType = trim($campaignType);

        return $this;
    }

    public function getAudienceDescription(): string
    {
        return $this->audienceDescription;
    }

    public function setAudienceDescription(string $audienceDescription): static
    {
        $this->audienceDescription = trim($audienceDescription);

        return $this;
    }

    public function getScheduledDate(): ?\DateTimeImmutable
    {
        return $this->scheduledDate;
    }

    public function setScheduledDate(?\DateTimeImmutable $scheduledDate): static
    {
        $this->scheduledDate = $scheduledDate;

        return $this;
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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = null !== $notes ? trim($notes) : null;

        return $this;
    }

    public function getCampaignTypeLabel(): string
    {
        return match ($this->campaignType) {
            self::TYPE_SPRING_AC_TUNE_UP => 'Spring AC Tune-Up',
            self::TYPE_FALL_FURNACE_INSPECTION => 'Fall Furnace Inspection',
            self::TYPE_FILTER_REPLACEMENT => 'Filter Replacement',
            self::TYPE_WARRANTY_REMINDER => 'Warranty Reminder',
            self::TYPE_MAINTENANCE_RENEWAL => 'Maintenance Renewal',
            default => ucfirst(str_replace('_', ' ', $this->campaignType)),
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SCHEDULED => 'Scheduled',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    public static function getCampaignTypeChoices(): array
    {
        return [
            self::TYPE_SPRING_AC_TUNE_UP => 'Spring AC Tune-Up',
            self::TYPE_FALL_FURNACE_INSPECTION => 'Fall Furnace Inspection',
            self::TYPE_FILTER_REPLACEMENT => 'Filter Replacement',
            self::TYPE_WARRANTY_REMINDER => 'Warranty Reminder',
            self::TYPE_MAINTENANCE_RENEWAL => 'Maintenance Renewal',
        ];
    }

    public static function getStatusChoices(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SCHEDULED => 'Scheduled',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    /**
     * @return list<string>
     */
    public static function getCampaignTypeKeys(): array
    {
        return array_keys(self::getCampaignTypeChoices());
    }

    /**
     * @return list<string>
     */
    public static function getStatusKeys(): array
    {
        return array_keys(self::getStatusChoices());
    }
}
