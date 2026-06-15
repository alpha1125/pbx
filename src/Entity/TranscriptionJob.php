<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TranscriptionJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TranscriptionJobRepository::class)]
#[ORM\Table(name: 'transcription_job')]
#[ORM\Index(name: 'idx_transcription_job_status', columns: ['status'])]
#[ORM\Index(name: 'idx_transcription_job_next_attempt_at', columns: ['next_attempt_at'])]
#[ORM\Index(name: 'idx_transcription_job_locked_until', columns: ['locked_until'])]
#[ORM\Index(name: 'idx_transcription_job_priority', columns: ['priority'])]
#[ORM\Index(name: 'idx_transcription_job_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_transcription_job_call_recording', columns: ['call_recording_id'])]
class TranscriptionJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?CallRecording $callRecording = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallSession $callSession = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallLeg $callLeg = null;

    #[ORM\Column(length: 50, options: ['default' => 'telnyx'])]
    private string $provider = 'telnyx';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerJobId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerStatus = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerModel = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $providerConfig = null;

    #[ORM\Column(length: 50)]
    private string $status = 'pending';

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $priority = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 3])]
    private int $maxAttempts = 3;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lockedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextAttemptAt = null;

    #[ORM\Column(length: 255)]
    private string $inputS3Bucket;

    #[ORM\Column(type: Types::TEXT)]
    private string $inputS3Key;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $channelMapping = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $transcriptText = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $transcriptJson = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $rawProviderResponse = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $transcriptS3Bucket = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $transcriptS3Key = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(?CallRecording $callRecording = null)
    {
        $this->callRecording = $callRecording;
        $this->callSession = $callRecording?->getCallSession();
        $this->callLeg = $callRecording?->getCallLeg();
        $this->inputS3Bucket = $callRecording?->getS3Bucket() ?? '';
        $this->inputS3Key = $callRecording?->getS3Key() ?? '';
        $this->channelMapping = $callRecording?->getChannelMapping();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCallRecording(): ?CallRecording { return $this->callRecording; }
    public function setCallRecording(?CallRecording $callRecording): static { $this->callRecording = $callRecording; return $this; }
    public function getCallSession(): ?CallSession { return $this->callSession; }
    public function setCallSession(?CallSession $callSession): static { $this->callSession = $callSession; return $this; }
    public function getCallLeg(): ?CallLeg { return $this->callLeg; }
    public function setCallLeg(?CallLeg $callLeg): static { $this->callLeg = $callLeg; return $this; }
    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $provider): static { $this->provider = $provider; return $this; }
    public function getProviderJobId(): ?string { return $this->providerJobId; }
    public function setProviderJobId(?string $providerJobId): static { $this->providerJobId = $providerJobId; return $this; }
    public function getProviderStatus(): ?string { return $this->providerStatus; }
    public function setProviderStatus(?string $providerStatus): static { $this->providerStatus = $providerStatus; return $this; }
    public function getProviderModel(): ?string { return $this->providerModel; }
    public function setProviderModel(?string $providerModel): static { $this->providerModel = $providerModel; return $this; }
    /** @return array<string, mixed>|null */
    public function getProviderConfig(): ?array { return $this->providerConfig; }
    /** @param array<string, mixed>|null $providerConfig */
    public function setProviderConfig(?array $providerConfig): static { $this->providerConfig = $providerConfig; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getPriority(): int { return $this->priority; }
    public function setPriority(int $priority): static { $this->priority = $priority; return $this; }
    public function getAttempts(): int { return $this->attempts; }
    public function setAttempts(int $attempts): static { $this->attempts = $attempts; return $this; }
    public function incrementAttempts(): static { ++$this->attempts; return $this; }
    public function getMaxAttempts(): int { return $this->maxAttempts; }
    public function setMaxAttempts(int $maxAttempts): static { $this->maxAttempts = $maxAttempts; return $this; }
    public function getLockedBy(): ?string { return $this->lockedBy; }
    public function setLockedBy(?string $lockedBy): static { $this->lockedBy = $lockedBy; return $this; }
    public function getLockedUntil(): ?\DateTimeImmutable { return $this->lockedUntil; }
    public function setLockedUntil(?\DateTimeImmutable $lockedUntil): static { $this->lockedUntil = $lockedUntil; return $this; }
    public function getClaimedAt(): ?\DateTimeImmutable { return $this->claimedAt; }
    public function setClaimedAt(?\DateTimeImmutable $claimedAt): static { $this->claimedAt = $claimedAt; return $this; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }
    public function getSubmittedAt(): ?\DateTimeImmutable { return $this->submittedAt; }
    public function setSubmittedAt(?\DateTimeImmutable $submittedAt): static { $this->submittedAt = $submittedAt; return $this; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $completedAt): static { $this->completedAt = $completedAt; return $this; }
    public function getFailedAt(): ?\DateTimeImmutable { return $this->failedAt; }
    public function setFailedAt(?\DateTimeImmutable $failedAt): static { $this->failedAt = $failedAt; return $this; }
    public function getNextAttemptAt(): ?\DateTimeImmutable { return $this->nextAttemptAt; }
    public function setNextAttemptAt(?\DateTimeImmutable $nextAttemptAt): static { $this->nextAttemptAt = $nextAttemptAt; return $this; }
    public function getInputS3Bucket(): string { return $this->inputS3Bucket; }
    public function setInputS3Bucket(string $inputS3Bucket): static { $this->inputS3Bucket = $inputS3Bucket; return $this; }
    public function getInputS3Key(): string { return $this->inputS3Key; }
    public function setInputS3Key(string $inputS3Key): static { $this->inputS3Key = $inputS3Key; return $this; }
    /** @return array<string, mixed>|null */
    public function getChannelMapping(): ?array { return $this->channelMapping; }
    /** @param array<string, mixed>|null $channelMapping */
    public function setChannelMapping(?array $channelMapping): static { $this->channelMapping = $channelMapping; return $this; }
    public function getTranscriptText(): ?string { return $this->transcriptText; }
    public function setTranscriptText(?string $transcriptText): static { $this->transcriptText = $transcriptText; return $this; }
    /** @return array<string, mixed>|null */
    public function getTranscriptJson(): ?array { return $this->transcriptJson; }
    /** @param array<string, mixed>|null $transcriptJson */
    public function setTranscriptJson(?array $transcriptJson): static { $this->transcriptJson = $transcriptJson; return $this; }
    /** @return array<string, mixed>|null */
    public function getRawProviderResponse(): ?array { return $this->rawProviderResponse; }
    /** @param array<string, mixed>|null $rawProviderResponse */
    public function setRawProviderResponse(?array $rawProviderResponse): static { $this->rawProviderResponse = $rawProviderResponse; return $this; }
    public function getTranscriptS3Bucket(): ?string { return $this->transcriptS3Bucket; }
    public function setTranscriptS3Bucket(?string $transcriptS3Bucket): static { $this->transcriptS3Bucket = $transcriptS3Bucket; return $this; }
    public function getTranscriptS3Key(): ?string { return $this->transcriptS3Key; }
    public function setTranscriptS3Key(?string $transcriptS3Key): static { $this->transcriptS3Key = $transcriptS3Key; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): static { $this->errorMessage = $errorMessage; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(?\DateTimeImmutable $at = null): static { $this->updatedAt = $at ?? new \DateTimeImmutable(); return $this; }
}
