<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CallSummaryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CallSummaryRepository::class)]
#[ORM\Table(name: 'call_summary')]
#[ORM\Index(name: 'idx_call_summary_status', columns: ['status'])]
#[ORM\Index(name: 'idx_call_summary_created_at', columns: ['created_at'])]
class CallSummary
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CallTranscript $callTranscript;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallRecording $callRecording = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallSession $callSession = null;

    #[ORM\Column(length: 50, options: ['default' => 'ollama'])]
    private string $provider = 'ollama';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(length: 50)]
    private string $status = 'pending';

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $summaryJson = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summaryText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(CallTranscript $callTranscript)
    {
        $this->callTranscript = $callTranscript;
        $this->callRecording = $callTranscript->getCallRecording();
        $this->callSession = $callTranscript->getCallSession();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCallTranscript(): CallTranscript { return $this->callTranscript; }
    public function getCallRecording(): ?CallRecording { return $this->callRecording; }
    public function setCallRecording(?CallRecording $callRecording): static { $this->callRecording = $callRecording; return $this; }
    public function getCallSession(): ?CallSession { return $this->callSession; }
    public function setCallSession(?CallSession $callSession): static { $this->callSession = $callSession; return $this; }
    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $provider): static { $this->provider = $provider; return $this; }
    public function getModel(): ?string { return $this->model; }
    public function setModel(?string $model): static { $this->model = $model; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    /** @return array<string, mixed>|null */
    public function getSummaryJson(): ?array { return $this->summaryJson; }
    /** @param array<string, mixed>|null $summaryJson */
    public function setSummaryJson(?array $summaryJson): static { $this->summaryJson = $summaryJson; return $this; }
    public function getSummaryText(): ?string { return $this->summaryText; }
    public function setSummaryText(?string $summaryText): static { $this->summaryText = $summaryText; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): static { $this->errorMessage = $errorMessage; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(?\DateTimeImmutable $at = null): static { $this->updatedAt = $at ?? new \DateTimeImmutable(); return $this; }
}
