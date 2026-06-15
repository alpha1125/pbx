<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[Assert\Email]
    #[Assert\NotBlank]
    #[ORM\Column(length: 180)]
    private ?string $email = null;

    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $firstName = null;

    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lastName = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $displayName = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cellPhone = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    /** @var Collection<int, UserTenantMembership> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserTenantMembership::class, orphanRemoval: true)]
    private Collection $tenantMemberships;

    public function __construct()
    {
        $this->tenantMemberships = new ArrayCollection();
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = trim($email);

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = null !== $firstName ? trim($firstName) : null;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = null !== $lastName ? trim($lastName) : null;

        return $this;
    }

    public function getDisplayName(): string
    {
        if (null !== $this->displayName && '' !== trim($this->displayName)) {
            return $this->displayName;
        }

        $name = trim(sprintf('%s %s', $this->firstName ?? '', $this->lastName ?? ''));

        return '' !== $name ? $name : (string) $this->email;
    }

    public function setDisplayName(?string $displayName): static
    {
        $this->displayName = null !== $displayName ? trim($displayName) : null;

        return $this;
    }

    public function getCellPhone(): ?string
    {
        return $this->cellPhone;
    }

    public function setCellPhone(?string $cellPhone): static
    {
        $this->cellPhone = null !== $cellPhone ? trim($cellPhone) : null;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    /** @return Collection<int, UserTenantMembership> */
    public function getTenantMemberships(): Collection
    {
        return $this->tenantMemberships;
    }

    public function addTenantMembership(UserTenantMembership $membership): static
    {
        if (!$this->tenantMemberships->contains($membership)) {
            $this->tenantMemberships->add($membership);
        }

        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', (string) $this->password);

        return $data;
    }

    public function eraseCredentials(): void
    {
    }
}
