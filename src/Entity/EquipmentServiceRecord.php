<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EquipmentServiceRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EquipmentServiceRecordRepository::class)]
#[ORM\Table(name: 'equipment_service_record')]
#[ORM\Index(name: 'idx_equipment_service_record_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_equipment_service_record_property', columns: ['property_id'])]
#[ORM\Index(name: 'idx_equipment_service_record_equipment', columns: ['equipment_id'])]
#[ORM\Index(name: 'idx_equipment_service_record_job', columns: ['job_id'])]
#[ORM\Index(name: 'idx_equipment_service_record_completed_at', columns: ['completed_at'])]
class EquipmentServiceRecord
{
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
    private ?Equipment $equipment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Job $job = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Task $task = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $technician = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $arrivedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $technicianNotes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $recommendedRepairNotes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $recommendedReplacementNotes = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $serviceType = null;

    public function __construct(Tenant $tenant, Property $property)
    {
        $this->tenant = $tenant;
        $this->property = $property;
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getProperty(): Property { return $this->property; }
    public function getEquipment(): ?Equipment { return $this->equipment; }
    public function setEquipment(?Equipment $equipment): static { $this->equipment = $equipment; return $this; }
    public function getJob(): ?Job { return $this->job; }
    public function setJob(?Job $job): static { $this->job = $job; return $this; }
    public function getTask(): ?Task { return $this->task; }
    public function setTask(?Task $task): static { $this->task = $task; return $this; }
    public function getTechnician(): ?User { return $this->technician; }
    public function setTechnician(?User $technician): static { $this->technician = $technician; return $this; }
    public function getArrivedAt(): ?\DateTimeImmutable { return $this->arrivedAt; }
    public function setArrivedAt(?\DateTimeImmutable $arrivedAt): static { $this->arrivedAt = $arrivedAt; return $this; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $completedAt): static { $this->completedAt = $completedAt; return $this; }
    public function getTechnicianNotes(): ?string { return $this->technicianNotes; }
    public function setTechnicianNotes(?string $technicianNotes): static { $this->technicianNotes = null !== $technicianNotes ? trim($technicianNotes) : null; return $this; }
    public function getRecommendedRepairNotes(): ?string { return $this->recommendedRepairNotes; }
    public function setRecommendedRepairNotes(?string $recommendedRepairNotes): static { $this->recommendedRepairNotes = null !== $recommendedRepairNotes ? trim($recommendedRepairNotes) : null; return $this; }
    public function getRecommendedReplacementNotes(): ?string { return $this->recommendedReplacementNotes; }
    public function setRecommendedReplacementNotes(?string $recommendedReplacementNotes): static { $this->recommendedReplacementNotes = null !== $recommendedReplacementNotes ? trim($recommendedReplacementNotes) : null; return $this; }
    public function getServiceType(): ?string { return $this->serviceType; }
    public function setServiceType(?string $serviceType): static { $this->serviceType = null !== $serviceType ? trim($serviceType) : null; return $this; }
}
