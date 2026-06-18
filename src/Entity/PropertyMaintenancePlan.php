<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'property_maintenance_plan')]
#[ORM\Index(name: 'idx_pmp_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_pmp_property', columns: ['property_id'])]
class PropertyMaintenancePlan
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
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MaintenancePlan $maintenancePlan;

    #[Assert\NotBlank]
    #[ORM\Column(length: 255)]
    private string $planNameAtAssignment;

    #[ORM\Column(options: ['default' => false])]
    private bool $isCancelled = false;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancellationDate = null;

    public function __construct(Tenant $tenant, Property $property, MaintenancePlan $maintenancePlan)
    {
        $this->tenant = $tenant;
        $this->property = $property;
        $this->maintenancePlan = $maintenancePlan;
        $this->planNameAtAssignment = $maintenancePlan->getName();
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getProperty(): Property { return $this->property; }
    public function getMaintenancePlan(): MaintenancePlan { return $this->maintenancePlan; }
    public function setMaintenancePlan(MaintenancePlan $plan): static { $this->maintenancePlan = $plan; return $this; }
    public function getPlanNameAtAssignment(): string { return $this->planNameAtAssignment; }
    public function setPlanNameAtAssignment(string $name): static { $this->planNameAtAssignment = trim($name); return $this; }
    public function isCancelled(): bool { return $this->isCancelled; }

    public function cancel(?\DateTimeImmutable $at = null): static
    {
        $this->isCancelled     = true;
        $this->cancellationDate = $at ?? new \DateTimeImmutable();

        return $this;
    }

    public function getCancellationDate(): ?\DateTimeImmutable { return $this->cancellationDate; }

    /** Get a snapshot of the plan's current values (independent of plan changes). */
    public function getSnapshotAsArray(): array
    {
        return [
            'id'                  => $this->maintenancePlan->getId(),
            'planType'            => $this->maintenancePlan->getPlanType(),
            'name'                => $this->planNameAtAssignment,
            'visitFrequencyDays'  => $this->maintenancePlan->getVisitFrequencyDays(),
            'discountPercentage'  => $this->maintenancePlan->getDiscountPercentage(),
            'priorityScheduling'  => $this->maintenancePlan->isPriorityScheduling(),
            'includedServices'    => $this->maintenancePlan->getIncludedServices(),
            'isActive'            => $this->maintenancePlan->isActive(),
            'startDate'           => $this->maintenancePlan->getStartDate(),
        ];
    }
}
