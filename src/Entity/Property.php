<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PropertyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PropertyRepository::class)]
#[ORM\Table(name: 'property')]
#[ORM\Index(name: 'idx_property_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_property_tenant_city', columns: ['tenant_id', 'city'])]
#[ORM\Index(name: 'idx_property_tenant_postal_code', columns: ['tenant_id', 'postal_code'])]
class Property
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $addressLine1;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressLine2 = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[ORM\Column(length: 120)]
    private string $city;

    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[ORM\Column(length: 120)]
    private string $province;

    #[Assert\NotBlank]
    #[Assert\Length(max: 32)]
    #[ORM\Column(length: 32)]
    private string $postalCode;

    #[Assert\Length(max: 2)]
    #[ORM\Column(length: 2, options: ['default' => 'CA'])]
    private string $country = 'CA';

    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $propertyType = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $approximateSquareFeet = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $yearBuilt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isArchived = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(
        Tenant $tenant,
        string $addressLine1,
        string $city,
        string $province,
        string $postalCode,
    ) {
        $this->tenant = $tenant;
        $this->addressLine1 = trim($addressLine1);
        $this->city = trim($city);
        $this->province = trim($province);
        $this->postalCode = strtoupper(trim($postalCode));
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getAddressLine1(): string { return $this->addressLine1; }
    public function setAddressLine1(string $addressLine1): static { $this->addressLine1 = trim($addressLine1); return $this; }
    public function getAddressLine2(): ?string { return $this->addressLine2; }
    public function setAddressLine2(?string $addressLine2): static { $this->addressLine2 = null !== $addressLine2 ? trim($addressLine2) : null; return $this; }
    public function getCity(): string { return $this->city; }
    public function setCity(string $city): static { $this->city = trim($city); return $this; }
    public function getProvince(): string { return $this->province; }
    public function setProvince(string $province): static { $this->province = trim($province); return $this; }
    public function getPostalCode(): string { return $this->postalCode; }
    public function setPostalCode(string $postalCode): static { $this->postalCode = strtoupper(trim($postalCode)); return $this; }
    public function getCountry(): string { return $this->country; }
    public function setCountry(string $country): static { $this->country = strtoupper(trim($country)); return $this; }
    public function getPropertyType(): ?string { return $this->propertyType; }
    public function setPropertyType(?string $propertyType): static { $this->propertyType = null !== $propertyType ? trim($propertyType) : null; return $this; }
    public function getApproximateSquareFeet(): ?int { return $this->approximateSquareFeet; }
    public function setApproximateSquareFeet(?int $approximateSquareFeet): static { $this->approximateSquareFeet = $approximateSquareFeet; return $this; }
    public function getYearBuilt(): ?int { return $this->yearBuilt; }
    public function setYearBuilt(?int $yearBuilt): static { $this->yearBuilt = $yearBuilt; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }
    public function isArchived(): bool { return $this->isArchived; }
    public function archive(): static { $this->isArchived = true; $this->archivedAt = new \DateTimeImmutable(); return $this; }
    public function restore(): static { $this->isArchived = false; $this->archivedAt = null; return $this; }
    public function getArchivedAt(): ?\DateTimeImmutable { return $this->archivedAt; }

    public function getDisplayAddress(): string
    {
        $lines = [$this->addressLine1];
        if (null !== $this->addressLine2 && '' !== $this->addressLine2) {
            $lines[] = $this->addressLine2;
        }

        $lines[] = sprintf('%s, %s %s', $this->city, $this->province, $this->postalCode);

        return implode(', ', $lines);
    }
}
