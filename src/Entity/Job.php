<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: JobRepository::class)]
#[ORM\Table(name: 'job')]
#[ORM\Index(name: 'idx_job_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_job_property', columns: ['property_id'])]
#[ORM\Index(name: 'idx_job_status', columns: ['status'])]
#[ORM\Index(name: 'idx_job_scheduled_start_at', columns: ['scheduled_start_at'])]
class Job
{
    public const STATUS_UNSCHEDULED = 'unscheduled';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Property $property;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contact $contact = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Quote $quote = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $invoice = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Equipment $equipment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $assignedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_UNSCHEDULED;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledStartAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledEndAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $arrivedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $followUpGeneratedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $cancelledReason = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $technicianNotes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $recommendedRepairNotes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $recommendedReplacementNotes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $unresolvedIssueNotes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $serviceReminderAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $serviceReminderNotes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $followUpSummary = null;

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
    public function getQuote(): ?Quote { return $this->quote; }
    public function setQuote(?Quote $quote): static { $this->quote = $quote; return $this; }
    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function setInvoice(?Invoice $invoice): static { $this->invoice = $invoice; return $this; }
    public function getEquipment(): ?Equipment { return $this->equipment; }
    public function setEquipment(?Equipment $equipment): static { $this->equipment = $equipment; return $this; }
    public function getAssignedTo(): ?User { return $this->assignedTo; }
    public function setAssignedTo(?User $assignedTo): static { $this->assignedTo = $assignedTo; return $this; }
    public function getAssignedAt(): ?\DateTimeImmutable { return $this->assignedAt; }
    public function setAssignedAt(?\DateTimeImmutable $assignedAt): static { $this->assignedAt = $assignedAt; return $this; }
    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): static { $this->title = null !== $title ? trim($title) : null; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = trim($status); return $this; }
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_UNSCHEDULED => 'Unscheduled',
            self::STATUS_SCHEDULED => 'Scheduled',
            self::STATUS_IN_PROGRESS => 'In progress',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }
    public function getScheduledStartAt(): ?\DateTimeImmutable { return $this->scheduledStartAt; }
    public function setScheduledStartAt(?\DateTimeImmutable $scheduledStartAt): static { $this->scheduledStartAt = $scheduledStartAt; return $this; }
    public function getScheduledEndAt(): ?\DateTimeImmutable { return $this->scheduledEndAt; }
    public function setScheduledEndAt(?\DateTimeImmutable $scheduledEndAt): static { $this->scheduledEndAt = $scheduledEndAt; return $this; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $completedAt): static { $this->completedAt = $completedAt; return $this; }
    public function getArrivedAt(): ?\DateTimeImmutable { return $this->arrivedAt; }
    public function setArrivedAt(?\DateTimeImmutable $arrivedAt): static { $this->arrivedAt = $arrivedAt; return $this; }
    public function getFollowUpGeneratedAt(): ?\DateTimeImmutable { return $this->followUpGeneratedAt; }
    public function setFollowUpGeneratedAt(?\DateTimeImmutable $followUpGeneratedAt): static { $this->followUpGeneratedAt = $followUpGeneratedAt; return $this; }
    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }
    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): static { $this->cancelledAt = $cancelledAt; return $this; }
    public function getCancelledReason(): ?string { return $this->cancelledReason; }
    public function setCancelledReason(?string $cancelledReason): static { $this->cancelledReason = null !== $cancelledReason ? trim($cancelledReason) : null; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = null !== $notes ? trim($notes) : null; return $this; }
    public function getTechnicianNotes(): ?string { return $this->technicianNotes; }
    public function setTechnicianNotes(?string $technicianNotes): static { $this->technicianNotes = null !== $technicianNotes ? trim($technicianNotes) : null; return $this; }
    public function getRecommendedRepairNotes(): ?string { return $this->recommendedRepairNotes; }
    public function setRecommendedRepairNotes(?string $recommendedRepairNotes): static { $this->recommendedRepairNotes = null !== $recommendedRepairNotes ? trim($recommendedRepairNotes) : null; return $this; }
    public function getRecommendedReplacementNotes(): ?string { return $this->recommendedReplacementNotes; }
    public function setRecommendedReplacementNotes(?string $recommendedReplacementNotes): static { $this->recommendedReplacementNotes = null !== $recommendedReplacementNotes ? trim($recommendedReplacementNotes) : null; return $this; }
    public function getUnresolvedIssueNotes(): ?string { return $this->unresolvedIssueNotes; }
    public function setUnresolvedIssueNotes(?string $unresolvedIssueNotes): static { $this->unresolvedIssueNotes = null !== $unresolvedIssueNotes ? trim($unresolvedIssueNotes) : null; return $this; }
    public function getServiceReminderAt(): ?\DateTimeImmutable { return $this->serviceReminderAt; }
    public function setServiceReminderAt(?\DateTimeImmutable $serviceReminderAt): static { $this->serviceReminderAt = $serviceReminderAt; return $this; }
    public function getServiceReminderNotes(): ?string { return $this->serviceReminderNotes; }
    public function setServiceReminderNotes(?string $serviceReminderNotes): static { $this->serviceReminderNotes = null !== $serviceReminderNotes ? trim($serviceReminderNotes) : null; return $this; }
    public function getFollowUpSummary(): ?string { return $this->followUpSummary; }
    public function setFollowUpSummary(?string $followUpSummary): static { $this->followUpSummary = null !== $followUpSummary ? trim($followUpSummary) : null; return $this; }
}
