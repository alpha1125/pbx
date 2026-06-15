<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuoteLineItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuoteLineItemRepository::class)]
#[ORM\Table(name: 'quote_line_item')]
#[ORM\Index(name: 'idx_quote_line_item_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_quote_line_item_quote', columns: ['quote_id'])]
class QuoteLineItem
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
    private Quote $quote;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $quantity = '1.00';

    #[ORM\Column(type: Types::INTEGER)]
    private int $unitPriceCents = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $totalCents = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $sortOrder = 0;

    public function __construct(Tenant $tenant, Quote $quote, string $description)
    {
        $this->tenant = $tenant;
        $this->quote = $quote;
        $this->description = trim($description);
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getQuote(): Quote { return $this->quote; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): static { $this->description = trim($description); return $this; }
    public function getQuantity(): string { return $this->quantity; }
    public function setQuantity(string $quantity): static { $this->quantity = $quantity; return $this; }
    public function getUnitPriceCents(): int { return $this->unitPriceCents; }
    public function setUnitPriceCents(int $unitPriceCents): static { $this->unitPriceCents = $unitPriceCents; return $this; }
    public function getTotalCents(): int { return $this->totalCents; }
    public function setTotalCents(int $totalCents): static { $this->totalCents = $totalCents; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): static { $this->sortOrder = $sortOrder; return $this; }
}
