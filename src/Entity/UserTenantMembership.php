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
    public const ROLE_TENANT_ADMIN = 'ROLE_TENANT_ADMIN';
    public const ROLE_DISPATCH = 'ROLE_DISPATCH';
    public const ROLE_SALES = 'ROLE_SALES';
    public const ROLE_ACCOUNTING = 'ROLE_ACCOUNTING';
    public const ROLE_TECHNICIAN = 'ROLE_TECHNICIAN';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';

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

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $inviteToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $invitedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

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

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = trim($status);

        return $this;
    }

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }

    public function isPending(): bool
    {
        return self::STATUS_PENDING === $this->status;
    }

    public function getInviteToken(): ?string
    {
        return $this->inviteToken;
    }

    public function getInvitedAt(): ?\DateTimeImmutable
    {
        return $this->invitedAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function markInvited(string $inviteToken, ?\DateTimeImmutable $invitedAt = null): static
    {
        $this->status = self::STATUS_PENDING;
        $this->inviteToken = trim($inviteToken);
        $this->invitedAt = $invitedAt ?? new \DateTimeImmutable();
        $this->acceptedAt = null;
        $this->isDefault = false;

        return $this;
    }

    public function accept(?\DateTimeImmutable $acceptedAt = null): static
    {
        $this->status = self::STATUS_ACTIVE;
        $this->acceptedAt = $acceptedAt ?? new \DateTimeImmutable();
        $this->inviteToken = null;

        return $this;
    }
}
