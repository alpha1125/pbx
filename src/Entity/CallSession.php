<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CallSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CallSessionRepository::class)]
#[ORM\Table(name: 'call_session')]
class CallSession
{
    public const FLOW_TYPE_INBOUND_FORWARD = 'inbound_forward';
    public const FLOW_TYPE_CLICK_TO_CALL = 'click_to_call';
    public const FLOW_TYPE_TRANSCRIPTION_TEST = 'transcription_test';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 50, options: ['default' => 'telnyx'])]
    private string $provider = 'telnyx';

    #[ORM\Column(length: 255, unique: true)]
    private string $providerSessionId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $inboundFrom = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $inboundTo = null;

    #[ORM\Column(length: 50)]
    private string $status = 'initiated';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $flowType = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $answeredAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastEventAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hangupCause = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hangupSource = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallSession $parentCallSession = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Property $property = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contact $contact = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RfqInvitation $rfqInvitation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Estimate $estimate = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Quote $quote = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $invoice = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $providerSessionId)
    {
        $this->providerSessionId = $providerSessionId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getProviderSessionId(): string
    {
        return $this->providerSessionId;
    }

    public function getInboundFrom(): ?string
    {
        return $this->inboundFrom;
    }

    public function setInboundFrom(?string $inboundFrom): static
    {
        $this->inboundFrom = $inboundFrom;

        return $this;
    }

    public function getInboundTo(): ?string
    {
        return $this->inboundTo;
    }

    public function setInboundTo(?string $inboundTo): static
    {
        $this->inboundTo = $inboundTo;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getFlowType(): ?string
    {
        return $this->flowType;
    }

    public function setFlowType(?string $flowType): static
    {
        $this->flowType = $flowType;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getAnsweredAt(): ?\DateTimeImmutable
    {
        return $this->answeredAt;
    }

    public function setAnsweredAt(?\DateTimeImmutable $answeredAt): static
    {
        $this->answeredAt = $answeredAt;

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    public function getLastEventAt(): ?\DateTimeImmutable
    {
        return $this->lastEventAt;
    }

    public function setLastEventAt(?\DateTimeImmutable $lastEventAt): static
    {
        $this->lastEventAt = $lastEventAt;

        return $this;
    }

    public function getHangupCause(): ?string
    {
        return $this->hangupCause;
    }

    public function setHangupCause(?string $hangupCause): static
    {
        $this->hangupCause = $hangupCause;

        return $this;
    }

    public function getHangupSource(): ?string
    {
        return $this->hangupSource;
    }

    public function setHangupSource(?string $hangupSource): static
    {
        $this->hangupSource = $hangupSource;

        return $this;
    }

    public function getParentCallSession(): ?CallSession
    {
        return $this->parentCallSession;
    }

    public function setParentCallSession(?CallSession $parentCallSession): static
    {
        $this->parentCallSession = $parentCallSession;

        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
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

    public function getRfqInvitation(): ?RfqInvitation
    {
        return $this->rfqInvitation;
    }

    public function setRfqInvitation(?RfqInvitation $rfqInvitation): static
    {
        $this->rfqInvitation = $rfqInvitation;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(?\DateTimeImmutable $at = null): static
    {
        $this->updatedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }
}
