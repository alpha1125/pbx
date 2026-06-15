<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CallTranscriptSegmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CallTranscriptSegmentRepository::class)]
#[ORM\Table(name: 'call_transcript_segment')]
#[ORM\Index(name: 'idx_call_transcript_segment_transcript', columns: ['call_transcript_id'])]
#[ORM\Index(name: 'idx_call_transcript_segment_session', columns: ['call_session_id'])]
#[ORM\Index(name: 'idx_call_transcript_segment_leg', columns: ['call_leg_id'])]
#[ORM\Index(name: 'idx_call_transcript_segment_sequence', columns: ['sequence_number'])]
class CallTranscriptSegment
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
    private ?CallSession $callSession = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallLeg $callLeg = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $sequenceNumber;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerEventId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $speakerRole = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isFinal = true;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $rawPayload = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(CallTranscript $callTranscript, int $sequenceNumber, string $text)
    {
        $this->callTranscript = $callTranscript;
        $this->callSession = $callTranscript->getCallSession();
        $this->callLeg = $callTranscript->getCallLeg();
        $this->sequenceNumber = $sequenceNumber;
        $this->text = $text;
        $this->occurredAt = new \DateTimeImmutable();
        $this->createdAt = $this->occurredAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCallTranscript(): CallTranscript { return $this->callTranscript; }
    public function getCallSession(): ?CallSession { return $this->callSession; }
    public function setCallSession(?CallSession $callSession): static { $this->callSession = $callSession; return $this; }
    public function getCallLeg(): ?CallLeg { return $this->callLeg; }
    public function setCallLeg(?CallLeg $callLeg): static { $this->callLeg = $callLeg; return $this; }
    public function getSequenceNumber(): int { return $this->sequenceNumber; }
    public function getProviderEventId(): ?string { return $this->providerEventId; }
    public function setProviderEventId(?string $providerEventId): static { $this->providerEventId = $providerEventId; return $this; }
    public function getSpeakerRole(): ?string { return $this->speakerRole; }
    public function setSpeakerRole(?string $speakerRole): static { $this->speakerRole = $speakerRole; return $this; }
    public function getText(): string { return $this->text; }
    public function setText(string $text): static { $this->text = $text; return $this; }
    public function isFinal(): bool { return $this->isFinal; }
    public function setIsFinal(bool $isFinal): static { $this->isFinal = $isFinal; return $this; }
    /** @return array<string, mixed>|null */
    public function getRawPayload(): ?array { return $this->rawPayload; }
    /** @param array<string, mixed>|null $rawPayload */
    public function setRawPayload(?array $rawPayload): static { $this->rawPayload = $rawPayload; return $this; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function setOccurredAt(\DateTimeImmutable $occurredAt): static { $this->occurredAt = $occurredAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
