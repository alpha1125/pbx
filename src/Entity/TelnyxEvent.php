<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TelnyxEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TelnyxEventRepository::class)]
#[ORM\Table(name: 'telnyx_event')]
#[ORM\Index(name: 'idx_telnyx_event_event_type', columns: ['event_type'])]
#[ORM\Index(name: 'idx_telnyx_event_received_at', columns: ['received_at'])]
class TelnyxEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $providerEventId;

    #[ORM\Column(length: 255)]
    private string $eventType;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $callControlId;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $payload;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallSession $callSession = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallLeg $callLeg = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $providerEventId,
        string $eventType,
        array $payload,
        ?string $callControlId = null,
    ) {
        $this->providerEventId = $providerEventId;
        $this->eventType = $eventType;
        $this->payload = $payload;
        $this->callControlId = $callControlId;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProviderEventId(): string
    {
        return $this->providerEventId;
    }

    public function setProviderEventId(string $providerEventId): static
    {
        $this->providerEventId = $providerEventId;

        return $this;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): static
    {
        $this->eventType = $eventType;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): static
    {
        $this->payload = $payload;

        return $this;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(\DateTimeImmutable $receivedAt): static
    {
        $this->receivedAt = $receivedAt;

        return $this;
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

    public function getCallSession(): ?CallSession
    {
        return $this->callSession;
    }

    public function setCallSession(?CallSession $callSession): static
    {
        $this->callSession = $callSession;

        return $this;
    }

    public function getCallLeg(): ?CallLeg
    {
        return $this->callLeg;
    }

    public function setCallLeg(?CallLeg $callLeg): static
    {
        $this->callLeg = $callLeg;

        return $this;
    }
}
