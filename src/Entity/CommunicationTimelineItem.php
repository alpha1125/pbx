<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CommunicationTimelineItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommunicationTimelineItemRepository::class)]
#[ORM\Table(name: 'communication_timeline_item')]
#[ORM\UniqueConstraint(name: 'uniq_timeline_source_key', columns: ['source_key'])]
#[ORM\Index(name: 'idx_timeline_tenant_property_occurred_at', columns: ['tenant_id', 'property_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_timeline_item_type', columns: ['item_type'])]
class CommunicationTimelineItem
{
    public const TYPE_CALL = 'call';
    public const TYPE_RECORDING = 'recording';
    public const TYPE_TRANSCRIPT = 'transcript';
    public const TYPE_SUMMARY = 'summary';
    public const TYPE_MANUAL_NOTE = 'manual_note';
    public const TYPE_STATUS_CHANGE = 'status_change';
    public const TYPE_QUOTE_EVENT = 'quote_event';
    public const TYPE_INVOICE_EVENT = 'invoice_event';

    public const DISPOSITION_NO_ANSWER = 'no_answer';
    public const DISPOSITION_QUOTE_REQUESTED = 'quote_requested';
    public const DISPOSITION_FOLLOW_UP_REQUIRED = 'follow_up_required';
    public const DISPOSITION_JOB_BOOKED = 'job_booked';
    public const DISPOSITION_SPAM = 'spam';

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
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contact $contact = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Estimate $estimate = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Quote $quote = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $invoice = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RfqInvitation $rfqInvitation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallSession $callSession = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallRecording $callRecording = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallTranscript $callTranscript = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallSummary $callSummary = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(length: 50)]
    private string $itemType;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $sourceKey = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bodyText = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $metadata = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $disposition = null;

    public function __construct(Tenant $tenant, string $itemType, \DateTimeImmutable $occurredAt)
    {
        $this->tenant = $tenant;
        $this->itemType = trim($itemType);
        $this->occurredAt = $occurredAt;
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

    public function getEstimate(): ?Estimate
    {
        return $this->estimate;
    }

    public function setEstimate(?Estimate $estimate): static
    {
        $this->estimate = $estimate;

        return $this;
    }

    public function getQuote(): ?Quote
    {
        return $this->quote;
    }

    public function setQuote(?Quote $quote): static
    {
        $this->quote = $quote;

        return $this;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(?Invoice $invoice): static
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function getRfqInvitation(): ?RfqInvitation
    {
        return $this->rfqInvitation;
    }

    public function setRfqInvitation(?RfqInvitation $rfqInvitation): static
    {
        $this->rfqInvitation = $rfqInvitation;

        return $this;
    }

    public function getCallSession(): ?CallSession
    {
        return $this->callSession;
    }

    public function setCallSession(?CallSession $callSession): static
    {
        $this->callSession = $callSession;

        return $this;
    }

    public function getCallRecording(): ?CallRecording
    {
        return $this->callRecording;
    }

    public function setCallRecording(?CallRecording $callRecording): static
    {
        $this->callRecording = $callRecording;

        return $this;
    }

    public function getCallTranscript(): ?CallTranscript
    {
        return $this->callTranscript;
    }

    public function setCallTranscript(?CallTranscript $callTranscript): static
    {
        $this->callTranscript = $callTranscript;

        return $this;
    }

    public function getCallSummary(): ?CallSummary
    {
        return $this->callSummary;
    }

    public function setCallSummary(?CallSummary $callSummary): static
    {
        $this->callSummary = $callSummary;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getItemType(): string
    {
        return $this->itemType;
    }

    public function setItemType(string $itemType): static
    {
        $this->itemType = trim($itemType);

        return $this;
    }

    public function getSourceKey(): ?string
    {
        return $this->sourceKey;
    }

    public function setSourceKey(?string $sourceKey): static
    {
        $this->sourceKey = null !== $sourceKey ? trim($sourceKey) : null;

        return $this;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getBodyText(): ?string
    {
        return $this->bodyText;
    }

    public function setBodyText(?string $bodyText): static
    {
        $this->bodyText = null !== $bodyText ? trim($bodyText) : null;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /** @param array<string, mixed>|null $metadata */
    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getDisposition(): ?string
    {
        return $this->disposition;
    }

    public function setDisposition(?string $disposition): static
    {
        $this->disposition = null !== $disposition ? trim($disposition) : null;

        return $this;
    }
}
