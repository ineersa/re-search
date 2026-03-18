<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ResearchStepRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ResearchStepRepository::class)]
#[ORM\Table(name: 'research_step')]
class ResearchStep
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ResearchRun::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ResearchRun $run;

    #[ORM\Column(type: Types::INTEGER)]
    private int $sequence;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $type;

    #[ORM\Column(type: Types::INTEGER)]
    private int $turnNumber = 0;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $toolName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $toolArgumentsJson = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $toolSignature = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $summary = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $payloadJson = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $promptTokens = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $completionTokens = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $totalTokens = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $estimatedTokens = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

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

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function setSequence(int $sequence): static
    {
        $this->sequence = $sequence;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

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

    public function getToolName(): ?string
    {
        return $this->toolName;
    }

    public function setToolName(?string $toolName): static
    {
        $this->toolName = $toolName;

        return $this;
    }

    public function getToolArgumentsJson(): ?string
    {
        return $this->toolArgumentsJson;
    }

    public function setToolArgumentsJson(?string $toolArgumentsJson): static
    {
        $this->toolArgumentsJson = $toolArgumentsJson;

        return $this;
    }

    public function getToolSignature(): ?string
    {
        return $this->toolSignature;
    }

    public function setToolSignature(?string $toolSignature): static
    {
        $this->toolSignature = $toolSignature;

        return $this;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    public function getPayloadJson(): ?string
    {
        return $this->payloadJson;
    }

    public function setPayloadJson(?string $payloadJson): static
    {
        $this->payloadJson = $payloadJson;

        return $this;
    }

    public function getPromptTokens(): ?int
    {
        return $this->promptTokens;
    }

    public function setPromptTokens(?int $promptTokens): static
    {
        $this->promptTokens = $promptTokens;

        return $this;
    }

    public function getCompletionTokens(): ?int
    {
        return $this->completionTokens;
    }

    public function setCompletionTokens(?int $completionTokens): static
    {
        $this->completionTokens = $completionTokens;

        return $this;
    }

    public function getTotalTokens(): ?int
    {
        return $this->totalTokens;
    }

    public function setTotalTokens(?int $totalTokens): static
    {
        $this->totalTokens = $totalTokens;

        return $this;
    }

    public function isEstimatedTokens(): bool
    {
        return $this->estimatedTokens;
    }

    public function setEstimatedTokens(bool $estimatedTokens): static
    {
        $this->estimatedTokens = $estimatedTokens;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
