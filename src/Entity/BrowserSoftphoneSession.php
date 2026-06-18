<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BrowserSoftphoneSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BrowserSoftphoneSessionRepository::class)]
#[ORM\Table(name: 'browser_softphone_session')]
#[ORM\UniqueConstraint(name: 'UNIQ_BROWSER_SOFTPHONE_SESSION_TOKEN', fields: ['sessionToken'])]
#[ORM\UniqueConstraint(name: 'UNIQ_BROWSER_SOFTPHONE_SESSION_CALL_SESSION', fields: ['callSession'])]
#[ORM\Index(name: 'idx_browser_softphone_session_status', columns: ['status'])]
#[ORM\Index(name: 'idx_browser_softphone_session_allocated_at', columns: ['allocated_at'])]
class BrowserSoftphoneSession
{
    use TimestampableTrait;

    public const STATUS_ALLOCATED = 'allocated';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ENDED = 'ended';
    public const CONNECTION_STATE_IDLE = 'idle';
    public const CONNECTION_STATE_CONNECTING = 'sdk_connecting';
    public const CONNECTION_STATE_READY = 'sdk_ready';
    public const CONNECTION_STATE_FAILED = 'sdk_failed';
    public const CONNECTION_STATE_DISCONNECTED = 'sdk_disconnected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CallSession $callSession;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255, unique: true)]
    private string $sessionToken;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_ALLOCATED;

    #[ORM\Column(length: 50)]
    private string $connectionState = self::CONNECTION_STATE_IDLE;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $connectionErrorCode = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $connectionErrorMessage = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $connectionMeta = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $telnyxConnectionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $telnyxCallControlId = null;


    #[ORM\Column(length: 255, nullable: true)]
    private ?string $callId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $callState = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $callErrorCode = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $callErrorMessage = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $callMeta = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $allocatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $connectionAttemptedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $connectionReadyAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $connectionFailedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $callStartedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $callAnsweredAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $callEndedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $callFailedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    public function __construct(CallSession $callSession, Tenant $tenant, User $user, string $sessionToken)
    {
        $this->callSession = $callSession;
        $this->tenant = $tenant;
        $this->user = $user;
        $this->sessionToken = $sessionToken;
        $this->allocatedAt = new \DateTimeImmutable();
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCallSession(): CallSession
    {
        return $this->callSession;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSessionToken(): string
    {
        return $this->sessionToken;
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

    public function getConnectionState(): string
    {
        return $this->connectionState;
    }

    public function setConnectionState(string $connectionState): static
    {
        $this->connectionState = $connectionState;

        return $this;
    }

    public function getConnectionErrorCode(): ?string
    {
        return $this->connectionErrorCode;
    }

    public function setConnectionErrorCode(?string $connectionErrorCode): static
    {
        $this->connectionErrorCode = $connectionErrorCode;

        return $this;
    }

    public function getConnectionErrorMessage(): ?string
    {
        return $this->connectionErrorMessage;
    }

    public function setConnectionErrorMessage(?string $connectionErrorMessage): static
    {
        $this->connectionErrorMessage = null !== $connectionErrorMessage ? trim($connectionErrorMessage) : null;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getConnectionMeta(): ?array
    {
        return $this->connectionMeta;
    }

    /** @param array<string, mixed>|null $connectionMeta */
    public function setConnectionMeta(?array $connectionMeta): static
    {
        $this->connectionMeta = $connectionMeta;

        return $this;
    }

    public function getCallId(): ?string
    {
        return $this->callId;
    }

    public function setCallId(?string $callId): static
    {
        $this->callId = null !== $callId ? trim($callId) : null;

        return $this;
    }

    public function getCallState(): ?string
    {
        return $this->callState;
    }

    public function setCallState(?string $callState): static
    {
        $this->callState = null !== $callState ? trim($callState) : null;

        return $this;
    }

    public function getCallErrorCode(): ?string
    {
        return $this->callErrorCode;
    }

    public function setCallErrorCode(?string $callErrorCode): static
    {
        $this->callErrorCode = null !== $callErrorCode ? trim($callErrorCode) : null;

        return $this;
    }

    public function getCallErrorMessage(): ?string
    {
        return $this->callErrorMessage;
    }

    public function setCallErrorMessage(?string $callErrorMessage): static
    {
        $this->callErrorMessage = null !== $callErrorMessage ? trim($callErrorMessage) : null;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getCallMeta(): ?array
    {
        return $this->callMeta;
    }

    /** @param array<string, mixed>|null $callMeta */
    public function setCallMeta(?array $callMeta): static
    {
        $this->callMeta = $callMeta;

        return $this;
    }


    public function getTelnyxConnectionId(): ?string
    {
        return $this->telnyxConnectionId;
    }

    public function setTelnyxConnectionId(?string $telnyxConnectionId): static
    {
        $trimmed = null !== $telnyxConnectionId ? trim($telnyxConnectionId) : '';
        $this->telnyxConnectionId = '' === $trimmed ? null : $trimmed;

        return $this;
    }

    public function getTelnyxCallControlId(): ?string
    {
        return $this->telnyxCallControlId;
    }

    public function setTelnyxCallControlId(?string $telnyxCallControlId): static
    {
        $trimmed = null !== $telnyxCallControlId ? trim($telnyxCallControlId) : '';
        $this->telnyxCallControlId = '' === $trimmed ? null : $trimmed;

        return $this;
    }

    public function getAllocatedAt(): \DateTimeImmutable
    {
        return $this->allocatedAt;
    }

    public function getConnectionAttemptedAt(): ?\DateTimeImmutable
    {
        return $this->connectionAttemptedAt;
    }

    public function setConnectionAttemptedAt(?\DateTimeImmutable $connectionAttemptedAt): static
    {
        $this->connectionAttemptedAt = $connectionAttemptedAt;

        return $this;
    }

    public function getConnectionReadyAt(): ?\DateTimeImmutable
    {
        return $this->connectionReadyAt;
    }

    public function setConnectionReadyAt(?\DateTimeImmutable $connectionReadyAt): static
    {
        $this->connectionReadyAt = $connectionReadyAt;

        return $this;
    }

    public function getConnectionFailedAt(): ?\DateTimeImmutable
    {
        return $this->connectionFailedAt;
    }

    public function setConnectionFailedAt(?\DateTimeImmutable $connectionFailedAt): static
    {
        $this->connectionFailedAt = $connectionFailedAt;

        return $this;
    }

    public function getCallStartedAt(): ?\DateTimeImmutable
    {
        return $this->callStartedAt;
    }

    public function setCallStartedAt(?\DateTimeImmutable $callStartedAt): static
    {
        $this->callStartedAt = $callStartedAt;

        return $this;
    }

    public function getCallAnsweredAt(): ?\DateTimeImmutable
    {
        return $this->callAnsweredAt;
    }

    public function setCallAnsweredAt(?\DateTimeImmutable $callAnsweredAt): static
    {
        $this->callAnsweredAt = $callAnsweredAt;

        return $this;
    }

    public function getCallEndedAt(): ?\DateTimeImmutable
    {
        return $this->callEndedAt;
    }

    public function setCallEndedAt(?\DateTimeImmutable $callEndedAt): static
    {
        $this->callEndedAt = $callEndedAt;

        return $this;
    }

    public function getCallFailedAt(): ?\DateTimeImmutable
    {
        return $this->callFailedAt;
    }

    public function setCallFailedAt(?\DateTimeImmutable $callFailedAt): static
    {
        $this->callFailedAt = $callFailedAt;

        return $this;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }
}
