<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MaintenancePlanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MaintenancePlanRepository::class)]
#[ORM\Table(name: 'maintenance_plan')]
#[ORM\Index(name: 'idx_maintenance_plan_tenant', columns: ['tenant_id'])]
class MaintenancePlan
{
    public const PLAN_BRONZE   = 'bronze';
    public const PLAN_SILVER   = 'silver';
    public const PLAN_GOLD     = 'gold';

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
    #[ORM\Column(length: 20)]
    private string $planType = self::PLAN_BRONZE;

    /** @var int visit frequency in days (e.g., 90 for quarterly) */
    #[ORM\Column(type: Types::INTEGER)]
    private int $visitFrequencyDays = 180;

    #[Assert\Range(min: 0, max: 100)]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $discountPercentage = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $priorityScheduling = false;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $includedServices = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = false;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $renewalDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancellationDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct(Tenant $tenant, string $name)
    {
        $this->tenant = $tenant;
        $this->name = trim($name);
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = trim($name); return $this; }
    public function getPlanType(): string { return $this->planType; }
    public function setPlanType(string $planType): static { $this->planType = trim($planType); return $this; }
    public function getVisitFrequencyDays(): int { return $this->visitFrequencyDays; }
    public function setVisitFrequencyDays(int $days): static { $this->visitFrequencyDays = max(7, $days); return $this; }
    public function getDiscountPercentage(): int { return $this->discountPercentage; }
    public function setDiscountPercentage(int $pct): static { $this->discountPercentage = max(0, min(100, $pct)); return $this; }
    public function isPriorityScheduling(): bool { return $this->priorityScheduling; }
    public function setPriorityScheduling(bool $flag): static { $this->priorityScheduling = $flag; return $this; }
    /** @return list<string> */
    public function getIncludedServices(): array { return $this->includedServices; }
    /** @param list<string> $services */
    public function setIncludedServices(array $services): static { $this->includedServices = array_map('trim', array_values(array_filter($services))); return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }
    public function deactivate(): static { $this->isActive = false; return $this; }
    public function activate(): static { $this->isActive = true; return $this; }
    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function setStartDate(?\DateTimeImmutable $date): static { $this->startDate = $date; return $this; }
    public function getRenewalDate(): ?\DateTimeImmutable { return $this->renewalDate; }
    public function setRenewalDate(?\DateTimeImmutable $date): static { $this->renewalDate = $date; return $this; }
    public function getCancellationDate(): ?\DateTimeImmutable { return $this->cancellationDate; }
    public function setCancellationDate(?\DateTimeImmutable $date): static { $this->cancellationDate = $date; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = null !== $notes ? trim($notes) : null; return $this; }

    /** @return string label display name */
    public function getPlanTypeLabel(): string
    {
        return match ($this->planType) {
            self::PLAN_BRONZE => 'Bronze',
            self::PLAN_SILVER => 'Silver',
            self::PLAN_GOLD   => 'Gold',
            default           => ucfirst($this->planType),
        };
    }

    /** @return list<string> short keys */
    public function getPlanTypeKeys(): array
    {
        return [self::PLAN_BRONZE, self::PLAN_SILVER, self::PLAN_GOLD];
    }
}
