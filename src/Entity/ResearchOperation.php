<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ResearchOperationStatus;
use App\Entity\Enum\ResearchOperationType;
use App\Repository\ResearchOperationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

#[ORM\Entity(repositoryClass: ResearchOperationRepository::class)]
#[ORM\Table(name: 'research_operation')]
#[ORM\Index(name: 'idx_research_operation_run_status_type_turn', columns: ['run_id', 'status', 'type', 'turn_number'])]
#[ORM\UniqueConstraint(name: 'uniq_research_operation_idempotency_key', columns: ['idempotency_key'])]
class ResearchOperation
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ResearchRun::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ResearchRun $run;

    #[ORM\Column(type: Types::STRING, enumType: ResearchOperationType::class, length: 32)]
    private ResearchOperationType $type;

    #[ORM\Column(type: Types::STRING, enumType: ResearchOperationStatus::class, length: 32)]
    private ResearchOperationStatus $status = ResearchOperationStatus::QUEUED;

    #[ORM\Column(type: Types::INTEGER)]
    private int $turnNumber = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position = 0;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $idempotencyKey;

    #[ORM\Column(type: Types::TEXT)]
    private string $requestPayloadJson;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resultPayloadJson = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRun(): ResearchRun
    {
        return $this->run;
    }

    public function setRun(ResearchRun $run): static
    {
        $this->run = $run;

        return $this;
    }

    public function getType(): ResearchOperationType
    {
        return $this->type;
    }

    public function setType(ResearchOperationType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): ResearchOperationStatus
    {
        return $this->status;
    }

    public function setStatus(ResearchOperationStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getTurnNumber(): int
    {
        return $this->turnNumber;
    }

    public function setTurnNumber(int $turnNumber): static
    {
        $this->turnNumber = $turnNumber;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(string $idempotencyKey): static
    {
        $this->idempotencyKey = $idempotencyKey;

        return $this;
    }

    public function getRequestPayloadJson(): string
    {
        return $this->requestPayloadJson;
    }

    public function setRequestPayloadJson(string $requestPayloadJson): static
    {
        $this->requestPayloadJson = $requestPayloadJson;

        return $this;
    }

    public function getResultPayloadJson(): ?string
    {
        return $this->resultPayloadJson;
    }

    public function setResultPayloadJson(?string $resultPayloadJson): static
    {
        $this->resultPayloadJson = $resultPayloadJson;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function isTerminalStatus(): bool
    {
        return $this->status->isTerminal();
    }
}
