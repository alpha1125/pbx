<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PaymentAllocationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentAllocationRepository::class)]
#[ORM\Table(name: 'payment_allocation')]
#[ORM\Index(name: 'idx_payment_allocation_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_payment_allocation_payment', columns: ['payment_id'])]
#[ORM\Index(name: 'idx_payment_allocation_invoice', columns: ['invoice_id'])]
class PaymentAllocation
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
    private Payment $payment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\Column(type: Types::INTEGER)]
    private int $amountCents = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $allocatedAt;

    public function __construct(Tenant $tenant, Payment $payment, Invoice $invoice, int $amountCents)
    {
        $this->tenant = $tenant;
        $this->payment = $payment;
        $this->invoice = $invoice;
        $this->amountCents = max(0, $amountCents);
        $this->allocatedAt = new \DateTimeImmutable();
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getPayment(): Payment { return $this->payment; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function setAmountCents(int $amountCents): static { $this->amountCents = max(0, $amountCents); return $this; }
    public function getAllocatedAt(): \DateTimeImmutable { return $this->allocatedAt; }
    public function setAllocatedAt(\DateTimeImmutable $allocatedAt): static { $this->allocatedAt = $allocatedAt; return $this; }
}
