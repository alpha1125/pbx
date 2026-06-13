<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClickToCallRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClickToCallRequestRepository::class)]
#[ORM\Table(name: 'click_to_call_request')]
#[ORM\Index(name: 'idx_click_to_call_request_status', columns: ['status'])]
#[ORM\Index(name: 'idx_click_to_call_request_client_state_token', columns: ['client_state_token'])]
#[ORM\Index(name: 'idx_click_to_call_request_agent_call_leg_id', columns: ['agent_call_leg_id'])]
#[ORM\Index(name: 'idx_click_to_call_request_target_call_leg_id', columns: ['target_call_leg_id'])]
#[ORM\Index(name: 'idx_click_to_call_request_created_at', columns: ['created_at'])]
class ClickToCallRequest
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_DIALING_AGENT = 'dialing_agent';
    public const STATUS_AGENT_ANSWERED = 'agent_answered';
    public const STATUS_SPEAKING_INTRO = 'speaking_intro';
    public const STATUS_DIALING_TARGET = 'dialing_target';
    public const STATUS_TARGET_ANSWERED = 'target_answered';
    public const STATUS_BRIDGED = 'bridged';
    public const STATUS_RECORDING = 'recording';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallSession $callSession = null;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_REQUESTED;

    #[ORM\Column(length: 255)]
    private string $agentNumber;

    #[ORM\Column(length: 255)]
    private string $targetNumber;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $targetName = null;

    #[ORM\Column(length: 255)]
    private string $fromNumber;

    #[ORM\Column(length: 255)]
    private string $connectionId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $agentCallControlId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $agentCallLegId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $targetCallControlId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $targetCallLegId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $bridgeStartedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $recordingStartedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $clientStateToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $agentNumber,
        string $targetNumber,
        string $fromNumber,
        string $connectionId,
    ) {
        $this->agentNumber = $agentNumber;
        $this->targetNumber = $targetNumber;
        $this->fromNumber = $fromNumber;
        $this->connectionId = $connectionId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCallSession(): ?CallSession { return $this->callSession; }
    public function setCallSession(?CallSession $callSession): static { $this->callSession = $callSession; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getAgentNumber(): string { return $this->agentNumber; }
    public function setAgentNumber(string $agentNumber): static { $this->agentNumber = $agentNumber; return $this; }
    public function getTargetNumber(): string { return $this->targetNumber; }
    public function setTargetNumber(string $targetNumber): static { $this->targetNumber = $targetNumber; return $this; }
    public function getTargetName(): ?string { return $this->targetName; }
    public function setTargetName(?string $targetName): static { $this->targetName = $targetName; return $this; }
    public function getFromNumber(): string { return $this->fromNumber; }
    public function setFromNumber(string $fromNumber): static { $this->fromNumber = $fromNumber; return $this; }
    public function getConnectionId(): string { return $this->connectionId; }
    public function setConnectionId(string $connectionId): static { $this->connectionId = $connectionId; return $this; }
    public function getAgentCallControlId(): ?string { return $this->agentCallControlId; }
    public function setAgentCallControlId(?string $agentCallControlId): static { $this->agentCallControlId = $agentCallControlId; return $this; }
    public function getAgentCallLegId(): ?string { return $this->agentCallLegId; }
    public function setAgentCallLegId(?string $agentCallLegId): static { $this->agentCallLegId = $agentCallLegId; return $this; }
    public function getTargetCallControlId(): ?string { return $this->targetCallControlId; }
    public function setTargetCallControlId(?string $targetCallControlId): static { $this->targetCallControlId = $targetCallControlId; return $this; }
    public function getTargetCallLegId(): ?string { return $this->targetCallLegId; }
    public function setTargetCallLegId(?string $targetCallLegId): static { $this->targetCallLegId = $targetCallLegId; return $this; }
    public function getBridgeStartedAt(): ?\DateTimeImmutable { return $this->bridgeStartedAt; }
    public function setBridgeStartedAt(?\DateTimeImmutable $bridgeStartedAt): static { $this->bridgeStartedAt = $bridgeStartedAt; return $this; }
    public function getRecordingStartedAt(): ?\DateTimeImmutable { return $this->recordingStartedAt; }
    public function setRecordingStartedAt(?\DateTimeImmutable $recordingStartedAt): static { $this->recordingStartedAt = $recordingStartedAt; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): static { $this->errorMessage = $errorMessage; return $this; }
    public function getClientStateToken(): ?string { return $this->clientStateToken; }
    public function setClientStateToken(?string $clientStateToken): static { $this->clientStateToken = $clientStateToken; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(?\DateTimeImmutable $at = null): static { $this->updatedAt = $at ?? new \DateTimeImmutable(); return $this; }
}
