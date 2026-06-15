<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CallTranscriptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CallTranscriptRepository::class)]
#[ORM\Table(name: 'call_transcript')]
#[ORM\Index(name: 'idx_call_transcript_recording', columns: ['call_recording_id'])]
#[ORM\Index(name: 'idx_call_transcript_session', columns: ['call_session_id'])]
#[ORM\Index(name: 'idx_call_transcript_status', columns: ['status'])]
#[ORM\Index(name: 'idx_call_transcript_created_at', columns: ['created_at'])]
class CallTranscript
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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TranscriptionJob $transcriptionJob = null;

    #[ORM\Column(length: 50, options: ['default' => 'local_worker'])]
    private string $provider = 'local_worker';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(length: 50)]
    private string $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $transcriptText = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $rawResponse = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $channelMapping = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $language = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(?CallRecording $callRecording = null, ?string $model = null, string $status = 'available')
    {
        $this->callRecording = $callRecording;
        $this->callSession = $callRecording?->getCallSession();
        $this->callLeg = $callRecording?->getCallLeg();
        $this->model = $model;
        $this->status = $status;
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
    public function getTranscriptionJob(): ?TranscriptionJob { return $this->transcriptionJob; }
    public function setTranscriptionJob(?TranscriptionJob $transcriptionJob): static { $this->transcriptionJob = $transcriptionJob; return $this; }
    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $provider): static { $this->provider = $provider; return $this; }
    public function getModel(): ?string { return $this->model; }
    public function setModel(?string $model): static { $this->model = $model; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getTranscriptText(): ?string { return $this->transcriptText; }
    public function setTranscriptText(?string $text): static { $this->transcriptText = $text; return $this; }
    /** @return array<string, mixed>|null */
    public function getRawResponse(): ?array { return $this->rawResponse; }
    /** @param array<string, mixed>|null $rawResponse */
    public function setRawResponse(?array $rawResponse): static { $this->rawResponse = $rawResponse; return $this; }
    /** @return array<string, mixed>|null */
    public function getChannelMapping(): ?array { return $this->channelMapping; }
    /** @param array<string, mixed>|null $channelMapping */
    public function setChannelMapping(?array $channelMapping): static { $this->channelMapping = $channelMapping; return $this; }
    public function getLanguage(): ?string { return $this->language; }
    public function setLanguage(?string $language): static { $this->language = $language; return $this; }
    public function getDurationSeconds(): ?int { return $this->durationSeconds; }
    public function setDurationSeconds(?int $seconds): static { $this->durationSeconds = $seconds; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $message): static { $this->errorMessage = $message; return $this; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $at): static { $this->startedAt = $at; return $this; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $at): static { $this->completedAt = $at; return $this; }
    public function getFailedAt(): ?\DateTimeImmutable { return $this->failedAt; }
    public function setFailedAt(?\DateTimeImmutable $at): static { $this->failedAt = $at; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
