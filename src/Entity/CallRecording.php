<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CallRecordingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CallRecordingRepository::class)]
#[ORM\Table(name: 'call_recording')]
#[ORM\Index(name: 'idx_call_recording_status', columns: ['status'])]
#[ORM\Index(name: 'idx_call_recording_storage', columns: ['s3_bucket', 's3_key'])]
#[ORM\Index(name: 'idx_call_recording_created_at', columns: ['created_at'])]
class CallRecording
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CallSession $callSession;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallLeg $callLeg = null;

    #[ORM\Column(length: 50, options: ['default' => 'telnyx'])]
    private string $provider = 'telnyx';

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $providerRecordingId = null;

    #[ORM\Column(length: 50)]
    private string $status;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $s3Bucket = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $s3Key = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contentType = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $format = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $sizeBytes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $recordingStartedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $recordingEndedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $importedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $importError = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $providerDownloadUrl = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $rawPayload = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $channelMapping = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(CallSession $callSession, string $status = 'requested')
    {
        $this->callSession = $callSession;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCallSession(): CallSession { return $this->callSession; }
    public function getCallLeg(): ?CallLeg { return $this->callLeg; }
    public function setCallLeg(?CallLeg $callLeg): static { $this->callLeg = $callLeg; return $this; }
    public function getProviderRecordingId(): ?string { return $this->providerRecordingId; }
    public function setProviderRecordingId(?string $id): static { $this->providerRecordingId = $id; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getS3Bucket(): ?string { return $this->s3Bucket; }
    public function setS3Bucket(?string $bucket): static { $this->s3Bucket = $bucket; return $this; }
    public function getS3Key(): ?string { return $this->s3Key; }
    public function setS3Key(?string $key): static { $this->s3Key = $key; return $this; }
    public function getContentType(): ?string { return $this->contentType; }
    public function setContentType(?string $type): static { $this->contentType = $type; return $this; }
    public function getFormat(): ?string { return $this->format; }
    public function setFormat(?string $format): static { $this->format = $format; return $this; }
    public function getDurationSeconds(): ?int { return $this->durationSeconds; }
    public function setDurationSeconds(?int $seconds): static { $this->durationSeconds = $seconds; return $this; }
    public function getSizeBytes(): ?int { return $this->sizeBytes; }
    public function setSizeBytes(?int $bytes): static { $this->sizeBytes = $bytes; return $this; }
    public function getRecordingStartedAt(): ?\DateTimeImmutable { return $this->recordingStartedAt; }
    public function setRecordingStartedAt(?\DateTimeImmutable $at): static { $this->recordingStartedAt = $at; return $this; }
    public function getRecordingEndedAt(): ?\DateTimeImmutable { return $this->recordingEndedAt; }
    public function setRecordingEndedAt(?\DateTimeImmutable $at): static { $this->recordingEndedAt = $at; return $this; }
    public function getImportedAt(): ?\DateTimeImmutable { return $this->importedAt; }
    public function setImportedAt(?\DateTimeImmutable $at): static { $this->importedAt = $at; return $this; }
    public function getImportError(): ?string { return $this->importError; }
    public function setImportError(?string $error): static { $this->importError = $error; return $this; }
    public function getProviderDownloadUrl(): ?string { return $this->providerDownloadUrl; }
    public function setProviderDownloadUrl(?string $url): static { $this->providerDownloadUrl = $url; return $this; }
    /** @return array<string, mixed>|null */
    public function getRawPayload(): ?array { return $this->rawPayload; }
    /** @param array<string, mixed>|null $payload */
    public function setRawPayload(?array $payload): static { $this->rawPayload = $payload; return $this; }
    /** @return array<string, mixed>|null */
    public function getChannelMapping(): ?array { return $this->channelMapping; }
    /** @param array<string, mixed>|null $channelMapping */
    public function setChannelMapping(?array $channelMapping): static { $this->channelMapping = $channelMapping; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
