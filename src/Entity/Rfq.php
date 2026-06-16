<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RfqRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Platform-level homeowner procurement request.
 *
 * RFQs are intentionally global instead of tenant-scoped because one RFQ may be
 * sent to multiple HVAC-company tenants through tenant-scoped invitations.
 */
#[ORM\Entity(repositoryClass: RfqRepository::class)]
#[ORM\Table(name: 'rfq')]
#[ORM\UniqueConstraint(name: 'uniq_rfq_external_reference', columns: ['external_reference'])]
#[ORM\Index(name: 'idx_rfq_status', columns: ['status'])]
#[ORM\Index(name: 'idx_rfq_created_at', columns: ['created_at'])]
class Rfq
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_SENT_TO_VENDORS = 'sent_to_vendors';
    public const STATUS_QUOTED = 'quoted';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalReference = null;

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

    #[Assert\NotBlank]
    #[Assert\Country]
    #[ORM\Column(length: 2, options: ['default' => 'CA'])]
    private string $country = 'CA';

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customerName = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customerPhone = null;

    #[Assert\Email]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customerEmail = null;

    #[Assert\Length(max: 120)]
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $projectType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[Assert\Choice(choices: [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_SENT_TO_VENDORS,
        self::STATUS_QUOTED,
        self::STATUS_CLOSED,
        self::STATUS_CANCELLED,
    ])]
    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_DRAFT;

    public function __construct(string $addressLine1, string $city, string $province, string $postalCode)
    {
        $this->addressLine1 = trim($addressLine1);
        $this->city = trim($city);
        $this->province = trim($province);
        $this->postalCode = strtoupper(trim($postalCode));
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getExternalReference(): ?string { return $this->externalReference; }
    public function setExternalReference(?string $externalReference): static { $this->externalReference = $this->normalizeNullableString($externalReference); return $this; }
    public function getAddressLine1(): string { return $this->addressLine1; }
    public function setAddressLine1(string $addressLine1): static { $this->addressLine1 = trim($addressLine1); return $this; }
    public function getAddressLine2(): ?string { return $this->addressLine2; }
    public function setAddressLine2(?string $addressLine2): static { $this->addressLine2 = $this->normalizeNullableString($addressLine2); return $this; }
    public function getCity(): string { return $this->city; }
    public function setCity(string $city): static { $this->city = trim($city); return $this; }
    public function getProvince(): string { return $this->province; }
    public function setProvince(string $province): static { $this->province = trim($province); return $this; }
    public function getPostalCode(): string { return $this->postalCode; }
    public function setPostalCode(string $postalCode): static { $this->postalCode = strtoupper(trim($postalCode)); return $this; }
    public function getCountry(): string { return $this->country; }
    public function setCountry(string $country): static { $this->country = strtoupper(trim($country)); return $this; }
    public function getCustomerName(): ?string { return $this->customerName; }
    public function setCustomerName(?string $customerName): static { $this->customerName = $this->normalizeNullableString($customerName); return $this; }
    public function getCustomerPhone(): ?string { return $this->customerPhone; }
    public function setCustomerPhone(?string $customerPhone): static { $this->customerPhone = $this->normalizeNullableString($customerPhone); return $this; }
    public function getCustomerEmail(): ?string { return $this->customerEmail; }
    public function setCustomerEmail(?string $customerEmail): static { $this->customerEmail = $this->normalizeNullableString($customerEmail); return $this; }
    public function getProjectType(): ?string { return $this->projectType; }
    public function setProjectType(?string $projectType): static { $this->projectType = $this->normalizeNullableString($projectType); return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = trim($status); return $this; }

    private function normalizeNullableString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
