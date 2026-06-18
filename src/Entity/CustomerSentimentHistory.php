<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CustomerSentimentHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CustomerSentimentHistoryRepository::class)]
#[ORM\Table(name: 'customer_sentiment_history')]
#[ORM\Index(name: 'idx_customer_sentiment_history_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_customer_sentiment_history_property', columns: ['property_id'])]
#[ORM\Index(name: 'idx_customer_sentiment_history_contact', columns: ['contact_id'])]
#[ORM\Index(name: 'idx_customer_sentiment_history_call_session', columns: ['call_session_id'])]
#[ORM\Index(name: 'idx_customer_sentiment_history_recorded_by', columns: ['recorded_by_id'])]
#[ORM\Index(name: 'idx_customer_sentiment_history_recorded_at', columns: ['recorded_at'])]
#[ORM\Index(name: 'idx_customer_sentiment_history_lookup', columns: ['tenant_id', 'property_id', 'recorded_at'])]
class CustomerSentimentHistory
{
    public const SENTIMENT_POSITIVE = 'positive';
    public const SENTIMENT_NEUTRAL = 'neutral';
    public const SENTIMENT_NEGATIVE = 'negative';
    public const SENTIMENT_FRUSTRATED = 'frustrated';
    public const SENTIMENT_PRICE_SENSITIVE = 'price_sensitive';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Property $property;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contact $contact = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallSession $callSession = null;

    #[Assert\Choice(choices: [
        self::SENTIMENT_POSITIVE,
        self::SENTIMENT_NEUTRAL,
        self::SENTIMENT_NEGATIVE,
        self::SENTIMENT_FRUSTRATED,
        self::SENTIMENT_PRICE_SENSITIVE,
    ])]
    #[ORM\Column(length: 50)]
    private string $sentiment;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recordedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    public function __construct(
        Tenant $tenant,
        Property $property,
        User $recordedBy,
        string $sentiment,
        ?string $note = null,
        ?\DateTimeImmutable $recordedAt = null,
    ) {
        $this->tenant = $tenant;
        $this->property = $property;
        $this->recordedBy = $recordedBy;
        $this->sentiment = trim($sentiment);
        $this->note = null !== $note ? trim($note) : null;
        $this->recordedAt = $recordedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getProperty(): Property
    {
        return $this->property;
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

    public function getCallSession(): ?CallSession
    {
        return $this->callSession;
    }

    public function setCallSession(?CallSession $callSession): static
    {
        $this->callSession = $callSession;

        return $this;
    }

    public function getSentiment(): string
    {
        return $this->sentiment;
    }

    public function setSentiment(string $sentiment): static
    {
        $this->sentiment = trim($sentiment);

        return $this;
    }

    public function getSentimentLabel(): string
    {
        return match ($this->sentiment) {
            self::SENTIMENT_POSITIVE => 'Positive',
            self::SENTIMENT_NEUTRAL => 'Neutral',
            self::SENTIMENT_NEGATIVE => 'Negative',
            self::SENTIMENT_FRUSTRATED => 'Frustrated',
            self::SENTIMENT_PRICE_SENSITIVE => 'Price Sensitive',
            default => ucfirst(str_replace('_', ' ', $this->sentiment)),
        };
    }

    /**
     * @return list<string>
     */
    public static function getSentimentKeys(): array
    {
        return [
            self::SENTIMENT_POSITIVE,
            self::SENTIMENT_NEUTRAL,
            self::SENTIMENT_NEGATIVE,
            self::SENTIMENT_FRUSTRATED,
            self::SENTIMENT_PRICE_SENSITIVE,
        ];
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = null !== $note ? trim($note) : null;

        return $this;
    }

    public function getRecordedBy(): User
    {
        return $this->recordedBy;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(\DateTimeImmutable $recordedAt): static
    {
        $this->recordedAt = $recordedAt;

        return $this;
    }
}
