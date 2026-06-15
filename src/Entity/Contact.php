<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContactRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ContactRepository::class)]
#[ORM\Table(name: 'contact')]
#[ORM\Index(name: 'idx_contact_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_contact_display_name', columns: ['display_name'])]
class Contact
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[Assert\Length(max: 120)]
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $firstName = null;

    #[Assert\Length(max: 120)]
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $lastName = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $companyName = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $displayName;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $primaryPhone = null;

    #[Assert\Email]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $primaryEmail = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isArchived = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(Tenant $tenant, string $displayName)
    {
        $this->tenant = $tenant;
        $this->displayName = trim($displayName);
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(?string $firstName): static { $this->firstName = null !== $firstName ? trim($firstName) : null; return $this; }
    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(?string $lastName): static { $this->lastName = null !== $lastName ? trim($lastName) : null; return $this; }
    public function getCompanyName(): ?string { return $this->companyName; }
    public function setCompanyName(?string $companyName): static { $this->companyName = null !== $companyName ? trim($companyName) : null; return $this; }
    public function getDisplayName(): string { return $this->displayName; }
    public function setDisplayName(string $displayName): static { $this->displayName = trim($displayName); return $this; }
    public function getPrimaryPhone(): ?string { return $this->primaryPhone; }
    public function setPrimaryPhone(?string $primaryPhone): static { $this->primaryPhone = null !== $primaryPhone ? trim($primaryPhone) : null; return $this; }
    public function getPrimaryEmail(): ?string { return $this->primaryEmail; }
    public function setPrimaryEmail(?string $primaryEmail): static { $this->primaryEmail = null !== $primaryEmail ? trim($primaryEmail) : null; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }
    public function isArchived(): bool { return $this->isArchived; }
    public function archive(): static { $this->isArchived = true; $this->archivedAt = new \DateTimeImmutable(); return $this; }
    public function restore(): static { $this->isArchived = false; $this->archivedAt = null; return $this; }
    public function getArchivedAt(): ?\DateTimeImmutable { return $this->archivedAt; }
}
