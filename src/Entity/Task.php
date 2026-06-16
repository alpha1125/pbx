<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'task')]
#[ORM\Index(name: 'idx_task_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_task_job', columns: ['job_id'])]
#[ORM\Index(name: 'idx_task_status', columns: ['status'])]
#[ORM\Index(name: 'idx_task_scheduled_start_at', columns: ['scheduled_start_at'])]
class Task
{
    public const KIND_MANUAL = 'manual';
    public const KIND_FOLLOW_UP = 'follow_up';
    public const KIND_SERVICE_REMINDER = 'service_reminder';

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
    private Job $job;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $assignedAt = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 50, options: ['default' => self::KIND_MANUAL])]
    private string $kind = self::KIND_MANUAL;

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
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct(Tenant $tenant, Job $job, string $title)
    {
        $this->tenant = $tenant;
        $this->job = $job;
        $this->title = trim($title);
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getJob(): Job { return $this->job; }
    public function setJob(Job $job): static { $this->job = $job; return $this; }
    public function getAssignedTo(): ?User { return $this->assignedTo; }
    public function setAssignedTo(?User $assignedTo): static { $this->assignedTo = $assignedTo; return $this; }
    public function getAssignedAt(): ?\DateTimeImmutable { return $this->assignedAt; }
    public function setAssignedAt(?\DateTimeImmutable $assignedAt): static { $this->assignedAt = $assignedAt; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = trim($title); return $this; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): static { $this->kind = trim($kind); return $this; }
    public function getKindLabel(): string
    {
        return match ($this->kind) {
            self::KIND_MANUAL => 'Manual',
            self::KIND_FOLLOW_UP => 'Follow-up',
            self::KIND_SERVICE_REMINDER => 'Service reminder',
            default => ucfirst(str_replace('_', ' ', $this->kind)),
        };
    }
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
    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }
    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): static { $this->cancelledAt = $cancelledAt; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = null !== $description ? trim($description) : null; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = null !== $notes ? trim($notes) : null; return $this; }
}
