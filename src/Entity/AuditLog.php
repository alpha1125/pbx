<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_log_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_audit_log_entity', columns: ['entity_type', 'entity_id'])]
#[ORM\Index(name: 'idx_audit_log_created_at', columns: ['created_at'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $actorUserId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $actorDisplay = null;

    #[ORM\Column(length: 255)]
    private string $entityType;

    #[ORM\Column(length: 255)]
    private string $entityId;

    #[ORM\Column(length: 255)]
    private string $action;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $beforeData = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $afterData = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $entityType, string $entityId, string $action)
    {
        $this->entityType = trim($entityType);
        $this->entityId = trim($entityId);
        $this->action = trim($action);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }
    public function getActorUserId(): ?string { return $this->actorUserId; }
    public function setActorUserId(?string $actorUserId): static { $this->actorUserId = $actorUserId; return $this; }
    public function getActorDisplay(): ?string { return $this->actorDisplay; }
    public function setActorDisplay(?string $actorDisplay): static { $this->actorDisplay = null !== $actorDisplay ? trim($actorDisplay) : null; return $this; }
    public function getEntityType(): string { return $this->entityType; }
    public function getEntityId(): string { return $this->entityId; }
    public function getAction(): string { return $this->action; }
    /** @return array<string, mixed>|null */
    public function getBeforeData(): ?array { return $this->beforeData; }
    /** @param array<string, mixed>|null $beforeData */
    public function setBeforeData(?array $beforeData): static { $this->beforeData = $beforeData; return $this; }
    /** @return array<string, mixed>|null */
    public function getAfterData(): ?array { return $this->afterData; }
    /** @param array<string, mixed>|null $afterData */
    public function setAfterData(?array $afterData): static { $this->afterData = $afterData; return $this; }
    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array { return $this->metadata; }
    /** @param array<string, mixed>|null $metadata */
    public function setMetadata(?array $metadata): static { $this->metadata = $metadata; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
