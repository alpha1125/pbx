<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CallLegRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CallLegRepository::class)]
#[ORM\Table(name: 'call_leg')]
#[ORM\Index(name: 'idx_call_leg_call_control_id', columns: ['call_control_id'])]
class CallLeg
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CallSession $callSession;

    #[ORM\Column(length: 255, unique: true)]
    private string $providerLegId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $callControlId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $connectionId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $direction = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $toNumber = null;

    #[ORM\Column(length: 50)]
    private string $status = 'initiated';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $answeredAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hangupCause = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hangupSource = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sipHangupCause = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $billedDurationSeconds = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(CallSession $callSession, string $providerLegId)
    {
        $this->callSession = $callSession;
        $this->providerLegId = $providerLegId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCallSession(): CallSession
    {
        return $this->callSession;
    }

    public function getProviderLegId(): string
    {
        return $this->providerLegId;
    }

    public function getCallControlId(): ?string
    {
        return $this->callControlId;
    }

    public function setCallControlId(?string $callControlId): static
    {
        $this->callControlId = $callControlId;

        return $this;
    }

    public function setConnectionId(?string $connectionId): static
    {
        $this->connectionId = $connectionId;

        return $this;
    }

    public function getConnectionId(): ?string
    {
        return $this->connectionId;
    }

    public function setDirection(?string $direction): static
    {
        $this->direction = $direction;

        return $this;
    }

    public function getDirection(): ?string
    {
        return $this->direction;
    }

    public function setFromNumber(?string $fromNumber): static
    {
        $this->fromNumber = $fromNumber;

        return $this;
    }

    public function getFromNumber(): ?string
    {
        return $this->fromNumber;
    }

    public function setToNumber(?string $toNumber): static
    {
        $this->toNumber = $toNumber;

        return $this;
    }

    public function getToNumber(): ?string
    {
        return $this->toNumber;
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

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setAnsweredAt(?\DateTimeImmutable $answeredAt): static
    {
        $this->answeredAt = $answeredAt;

        return $this;
    }

    public function getAnsweredAt(): ?\DateTimeImmutable
    {
        return $this->answeredAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setHangupCause(?string $hangupCause): static
    {
        $this->hangupCause = $hangupCause;

        return $this;
    }

    public function getHangupCause(): ?string
    {
        return $this->hangupCause;
    }

    public function setHangupSource(?string $hangupSource): static
    {
        $this->hangupSource = $hangupSource;

        return $this;
    }

    public function getHangupSource(): ?string
    {
        return $this->hangupSource;
    }

    public function setSipHangupCause(?string $sipHangupCause): static
    {
        $this->sipHangupCause = $sipHangupCause;

        return $this;
    }

    public function getSipHangupCause(): ?string
    {
        return $this->sipHangupCause;
    }

    public function getBilledDurationSeconds(): ?int
    {
        return $this->billedDurationSeconds;
    }

    public function setBilledDurationSeconds(?int $billedDurationSeconds): static
    {
        $this->billedDurationSeconds = $billedDurationSeconds;

        return $this;
    }

    public function touch(?\DateTimeImmutable $at = null): static
    {
        $this->updatedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }
}
