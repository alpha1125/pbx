<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserTenantMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserTenantMembershipRepository::class)]
#[ORM\Table(name: 'user_tenant_membership')]
#[ORM\UniqueConstraint(name: 'uniq_user_tenant_membership_user_tenant', columns: ['user_id', 'tenant_id'])]
#[ORM\Index(name: 'idx_user_tenant_membership_tenant', columns: ['tenant_id'])]
class UserTenantMembership
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tenantMemberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $roles = [];

    #[ORM\Column(options: ['default' => false])]
    private bool $isDefault = false;

    public function __construct(User $user, Tenant $tenant)
    {
        $this->user = $user;
        $this->tenant = $tenant;
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique(array_filter(array_map('strval', $roles))));

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;

        return $this;
    }
}
