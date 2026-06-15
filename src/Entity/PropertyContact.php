<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PropertyContactRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PropertyContactRepository::class)]
#[ORM\Table(name: 'property_contact')]
#[ORM\Index(name: 'idx_property_contact_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_property_contact_property', columns: ['property_id'])]
#[ORM\Index(name: 'idx_property_contact_contact', columns: ['contact_id'])]
class PropertyContact
{
    public const RELATIONSHIP_OWNER = 'owner';
    public const RELATIONSHIP_TENANT = 'tenant';
    public const RELATIONSHIP_PROPERTY_MANAGER = 'property_manager';
    public const RELATIONSHIP_BILLING_CONTACT = 'billing_contact';
    public const RELATIONSHIP_REALTOR = 'realtor';
    public const RELATIONSHIP_EMERGENCY_CONTACT = 'emergency_contact';
    public const RELATIONSHIP_OTHER = 'other';

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
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Contact $contact;

    #[ORM\Column(length: 50)]
    private string $relationshipType = self::RELATIONSHIP_OTHER;

    #[ORM\Column(options: ['default' => false])]
    private bool $isPrimary = false;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    public function __construct(Tenant $tenant, Property $property, Contact $contact)
    {
        $this->tenant = $tenant;
        $this->property = $property;
        $this->contact = $contact;
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getProperty(): Property { return $this->property; }
    public function getContact(): Contact { return $this->contact; }
    public function getRelationshipType(): string { return $this->relationshipType; }
    public function setRelationshipType(string $relationshipType): static { $this->relationshipType = trim($relationshipType); return $this; }
    public function isPrimary(): bool { return $this->isPrimary; }
    public function setIsPrimary(bool $isPrimary): static { $this->isPrimary = $isPrimary; return $this; }
    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function setStartDate(?\DateTimeImmutable $startDate): static { $this->startDate = $startDate; return $this; }
    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function setEndDate(?\DateTimeImmutable $endDate): static { $this->endDate = $endDate; return $this; }
}
