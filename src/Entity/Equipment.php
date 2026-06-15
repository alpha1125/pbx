<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EquipmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EquipmentRepository::class)]
#[ORM\Table(name: 'equipment')]
#[ORM\Index(name: 'idx_equipment_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_equipment_property', columns: ['property_id'])]
class Equipment
{
    public const TYPE_FURNACE = 'furnace';
    public const TYPE_AIR_CONDITIONER = 'air_conditioner';
    public const TYPE_HEAT_PUMP = 'heat_pump';
    public const TYPE_EVAPORATOR_COIL = 'evaporator_coil';
    public const TYPE_THERMOSTAT = 'thermostat';
    public const TYPE_HUMIDIFIER = 'humidifier';
    public const TYPE_ERV = 'erv';
    public const TYPE_HRV = 'hrv';
    public const TYPE_WATER_HEATER = 'water_heater';
    public const TYPE_OTHER = 'other';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REPLACED = 'replaced';
    public const STATUS_REMOVED = 'removed';
    public const STATUS_UNKNOWN = 'unknown';

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

    #[ORM\Column(length: 50)]
    private string $equipmentType = self::TYPE_OTHER;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $brand = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $modelNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $serialNumber = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $installedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $warrantyExpiresAt = null;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_UNKNOWN;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct(Tenant $tenant, Property $property, string $equipmentType)
    {
        $this->tenant = $tenant;
        $this->property = $property;
        $this->equipmentType = trim($equipmentType);
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getProperty(): Property { return $this->property; }
    public function getEquipmentType(): string { return $this->equipmentType; }
    public function setEquipmentType(string $equipmentType): static { $this->equipmentType = trim($equipmentType); return $this; }
    public function getBrand(): ?string { return $this->brand; }
    public function setBrand(?string $brand): static { $this->brand = null !== $brand ? trim($brand) : null; return $this; }
    public function getModelNumber(): ?string { return $this->modelNumber; }
    public function setModelNumber(?string $modelNumber): static { $this->modelNumber = null !== $modelNumber ? trim($modelNumber) : null; return $this; }
    public function getSerialNumber(): ?string { return $this->serialNumber; }
    public function setSerialNumber(?string $serialNumber): static { $this->serialNumber = null !== $serialNumber ? trim($serialNumber) : null; return $this; }
    public function getInstalledAt(): ?\DateTimeImmutable { return $this->installedAt; }
    public function setInstalledAt(?\DateTimeImmutable $installedAt): static { $this->installedAt = $installedAt; return $this; }
    public function getWarrantyExpiresAt(): ?\DateTimeImmutable { return $this->warrantyExpiresAt; }
    public function setWarrantyExpiresAt(?\DateTimeImmutable $warrantyExpiresAt): static { $this->warrantyExpiresAt = $warrantyExpiresAt; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = trim($status); return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }
}
