<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CallActionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CallActionRepository::class)]
#[ORM\Table(name: 'call_action')]
class CallAction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallSession $callSession = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CallLeg $callLeg = null;

    #[ORM\Column(length: 50)]
    private string $actionType;

    #[ORM\Column(length: 50)]
    private string $status;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $requestPayload = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $responsePayload = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $actionType, string $status = 'attempted')
    {
        $this->actionType = $actionType;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCallSession(): ?CallSession { return $this->callSession; }
    public function getCallLeg(): ?CallLeg { return $this->callLeg; }
    public function getActionType(): string { return $this->actionType; }
    public function getStatus(): string { return $this->status; }

    public function setCallSession(?CallSession $callSession): static
    {
        $this->callSession = $callSession;

        return $this;
    }

    public function setCallLeg(?CallLeg $callLeg): static
    {
        $this->callLeg = $callLeg;

        return $this;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    /** @param array<string, mixed>|null $requestPayload */
    public function setRequestPayload(?array $requestPayload): static
    {
        $this->requestPayload = $requestPayload;

        return $this;
    }

    /** @param array<string, mixed>|null $responsePayload */
    public function setResponsePayload(?array $responsePayload): static
    {
        $this->responsePayload = $responsePayload;

        return $this;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }
}
