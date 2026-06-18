<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CsrPlaybookAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CsrPlaybookAttachmentRepository::class)]
#[ORM\Table(name: 'csr_playbook_attachment')]
#[ORM\Index(name: 'idx_csr_playbook_attachment_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_csr_playbook_attachment_property', columns: ['property_id'])]
#[ORM\Index(name: 'idx_csr_playbook_attachment_contact', columns: ['contact_id'])]
#[ORM\Index(name: 'idx_csr_playbook_attachment_opportunity', columns: ['retention_opportunity_id'])]
#[ORM\Index(name: 'idx_csr_playbook_attachment_lookup', columns: ['tenant_id', 'playbook_type', 'property_id', 'contact_id', 'retention_opportunity_id'])]
class CsrPlaybookAttachment
{
    public const TYPE_MAINTENANCE_OFFER = 'maintenance_offer';
    public const TYPE_WARRANTY_DISCUSSION = 'warranty_discussion';
    public const TYPE_REPLACEMENT_DISCUSSION = 'replacement_discussion';
    public const TYPE_OVERDUE_INVOICE_DISCUSSION = 'overdue_invoice_discussion';
    public const TYPE_DORMANT_CUSTOMER_OUTREACH = 'dormant_customer_outreach';

    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Property $property = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Contact $contact = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?RetentionOpportunity $retentionOpportunity = null;

    #[Assert\Choice(choices: [
        self::TYPE_MAINTENANCE_OFFER,
        self::TYPE_WARRANTY_DISCUSSION,
        self::TYPE_REPLACEMENT_DISCUSSION,
        self::TYPE_OVERDUE_INVOICE_DISCUSSION,
        self::TYPE_DORMANT_CUSTOMER_OUTREACH,
    ])]
    #[ORM\Column(length: 50)]
    private string $playbookType;

    public function __construct(
        Tenant $tenant,
        string $playbookType,
        ?Property $property = null,
        ?Contact $contact = null,
        ?RetentionOpportunity $retentionOpportunity = null,
    ) {
        $this->tenant = $tenant;
        $this->playbookType = trim($playbookType);
        $this->property = $property;
        $this->contact = $contact;
        $this->retentionOpportunity = $retentionOpportunity;
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getProperty(): ?Property
    {
        return $this->property;
    }

    public function setProperty(?Property $property): static
    {
        $this->property = $property;

        return $this;
    }

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function setContact(?Contact $contact): static
    {
        $this->contact = $contact;

        return $this;
    }

    public function getRetentionOpportunity(): ?RetentionOpportunity
    {
        return $this->retentionOpportunity;
    }

    public function setRetentionOpportunity(?RetentionOpportunity $retentionOpportunity): static
    {
        $this->retentionOpportunity = $retentionOpportunity;

        return $this;
    }

    public function getPlaybookType(): string
    {
        return $this->playbookType;
    }

    public function setPlaybookType(string $playbookType): static
    {
        $this->playbookType = trim($playbookType);

        return $this;
    }

    public function getPlaybookTypeLabel(): string
    {
        return match ($this->playbookType) {
            self::TYPE_MAINTENANCE_OFFER => 'Maintenance Offer',
            self::TYPE_WARRANTY_DISCUSSION => 'Warranty Discussion',
            self::TYPE_REPLACEMENT_DISCUSSION => 'Replacement Discussion',
            self::TYPE_OVERDUE_INVOICE_DISCUSSION => 'Overdue Invoice Discussion',
            self::TYPE_DORMANT_CUSTOMER_OUTREACH => 'Dormant Customer Outreach',
            default => ucfirst(str_replace('_', ' ', $this->playbookType)),
        };
    }

    /**
     * @return list<string>
     */
    public static function getPlaybookTypeKeys(): array
    {
        return [
            self::TYPE_MAINTENANCE_OFFER,
            self::TYPE_WARRANTY_DISCUSSION,
            self::TYPE_REPLACEMENT_DISCUSSION,
            self::TYPE_OVERDUE_INVOICE_DISCUSSION,
            self::TYPE_DORMANT_CUSTOMER_OUTREACH,
        ];
    }

    public function hasPropertyContext(): bool
    {
        return null !== $this->property;
    }

    public function hasContactContext(): bool
    {
        return null !== $this->contact;
    }

    public function hasOpportunityContext(): bool
    {
        return null !== $this->retentionOpportunity;
    }
}
