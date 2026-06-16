<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payment')]
#[ORM\UniqueConstraint(name: 'uniq_payment_number', columns: ['payment_number'])]
#[ORM\Index(name: 'idx_payment_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_payment_kind', columns: ['kind'])]
class Payment
{
    public const KIND_RECEIVED = 'received';
    public const KIND_REFUND = 'refund';

    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 64)]
    private string $paymentNumber;

    #[ORM\Column(length: 20)]
    private string $kind = self::KIND_RECEIVED;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(type: Types::INTEGER)]
    private int $amountCents = 0;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $method = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $memo = null;

    public function __construct(Tenant $tenant, string $paymentNumber)
    {
        $this->tenant = $tenant;
        $this->paymentNumber = trim($paymentNumber);
        $this->receivedAt = new \DateTimeImmutable('today');
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getPaymentNumber(): string { return $this->paymentNumber; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): static { $this->kind = trim($kind); return $this; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function setReceivedAt(\DateTimeImmutable $receivedAt): static { $this->receivedAt = $receivedAt; return $this; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function setAmountCents(int $amountCents): static { $this->amountCents = max(0, $amountCents); return $this; }
    public function getMethod(): ?string { return $this->method; }
    public function setMethod(?string $method): static { $this->method = null !== $method ? trim($method) : null; return $this; }
    public function getReference(): ?string { return $this->reference; }
    public function setReference(?string $reference): static { $this->reference = null !== $reference ? trim($reference) : null; return $this; }
    public function getMemo(): ?string { return $this->memo; }
    public function setMemo(?string $memo): static { $this->memo = null !== $memo ? trim($memo) : null; return $this; }
}
