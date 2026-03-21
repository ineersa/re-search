<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ResearchRunPhase;
use App\Entity\Enum\ResearchRunStatus;
use App\Repository\ResearchRunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ResearchRunRepository::class)]
#[ORM\Table(name: 'research_run')]
class ResearchRun
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 36, unique: true)]
    private string $runUuid;

    #[ORM\Column(type: Types::TEXT)]
    private string $query;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $queryHash;

    #[ORM\Column(type: Types::STRING, enumType: ResearchRunStatus::class, length: 32)]
    private ResearchRunStatus $status = ResearchRunStatus::QUEUED;

    #[ORM\Column(type: Types::STRING, enumType: ResearchRunPhase::class, length: 32, options: ['default' => 'queued'])]
    private ResearchRunPhase $phase = ResearchRunPhase::QUEUED;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelRequestedAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $orchestrationVersion = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $orchestratorStateJson = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $finalAnswerMarkdown = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $tokenBudgetHardCap = 75_000;

    #[ORM\Column(type: Types::INTEGER)]
    private int $tokenBudgetUsed = 0;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $tokenBudgetEstimated = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $loopDetected = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $answerOnlyTriggered = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $mercureTopic = '';

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $clientKey;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /**
     * @var Collection<int, ResearchStep>
     */
    #[ORM\OneToMany(targetEntity: ResearchStep::class, mappedBy: 'run', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sequence' => 'ASC'])]
    private Collection $steps;

    public function __construct()
    {
        $this->runUuid = Uuid::v4()->toRfc4122();
        $this->steps = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRunUuid(): string
    {
        return $this->runUuid;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function setQuery(string $query): static
    {
        $this->query = $query;

        return $this;
    }

    public function getQueryHash(): string
    {
        return $this->queryHash;
    }

    public function setQueryHash(string $queryHash): static
    {
        $this->queryHash = $queryHash;

        return $this;
    }

    public function getStatus(): ResearchRunStatus
    {
        return $this->status;
    }

    public function getStatusValue(): string
    {
        return $this->status->value;
    }

    public function setStatus(ResearchRunStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPhase(): ResearchRunPhase
    {
        return $this->phase;
    }

    public function getPhaseValue(): string
    {
        return $this->phase->value;
    }

    public function setPhase(ResearchRunPhase $phase): static
    {
        $this->phase = $phase;

        return $this;
    }

    public function getCancelRequestedAt(): ?\DateTimeImmutable
    {
        return $this->cancelRequestedAt;
    }

    public function setCancelRequestedAt(?\DateTimeImmutable $cancelRequestedAt): static
    {
        $this->cancelRequestedAt = $cancelRequestedAt;

        return $this;
    }

    public function getOrchestrationVersion(): int
    {
        return $this->orchestrationVersion;
    }

    public function setOrchestrationVersion(int $orchestrationVersion): static
    {
        $this->orchestrationVersion = $orchestrationVersion;

        return $this;
    }

    public function getOrchestratorStateJson(): ?string
    {
        return $this->orchestratorStateJson;
    }

    public function setOrchestratorStateJson(?string $orchestratorStateJson): static
    {
        $this->orchestratorStateJson = $orchestratorStateJson;

        return $this;
    }

    public function getFinalAnswerMarkdown(): ?string
    {
        return $this->finalAnswerMarkdown;
    }

    public function setFinalAnswerMarkdown(?string $finalAnswerMarkdown): static
    {
        $this->finalAnswerMarkdown = $finalAnswerMarkdown;

        return $this;
    }

    public function getTokenBudgetHardCap(): int
    {
        return $this->tokenBudgetHardCap;
    }

    public function setTokenBudgetHardCap(int $tokenBudgetHardCap): static
    {
        $this->tokenBudgetHardCap = $tokenBudgetHardCap;

        return $this;
    }

    public function getTokenBudgetUsed(): int
    {
        return $this->tokenBudgetUsed;
    }

    public function setTokenBudgetUsed(int $tokenBudgetUsed): static
    {
        $this->tokenBudgetUsed = $tokenBudgetUsed;

        return $this;
    }

    public function isTokenBudgetEstimated(): bool
    {
        return $this->tokenBudgetEstimated;
    }

    public function setTokenBudgetEstimated(bool $tokenBudgetEstimated): static
    {
        $this->tokenBudgetEstimated = $tokenBudgetEstimated;

        return $this;
    }

    public function isLoopDetected(): bool
    {
        return $this->loopDetected;
    }

    public function setLoopDetected(bool $loopDetected): static
    {
        $this->loopDetected = $loopDetected;

        return $this;
    }

    public function isAnswerOnlyTriggered(): bool
    {
        return $this->answerOnlyTriggered;
    }

    public function setAnswerOnlyTriggered(bool $answerOnlyTriggered): static
    {
        $this->answerOnlyTriggered = $answerOnlyTriggered;

        return $this;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function setFailureReason(?string $failureReason): static
    {
        $this->failureReason = $failureReason;

        return $this;
    }

    public function getMercureTopic(): string
    {
        return $this->mercureTopic;
    }

    public function setMercureTopic(string $mercureTopic): static
    {
        $this->mercureTopic = $mercureTopic;

        return $this;
    }

    public function getClientKey(): string
    {
        return $this->clientKey;
    }

    public function setClientKey(string $clientKey): static
    {
        $this->clientKey = $clientKey;

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

    /**
     * @return Collection<int, ResearchStep>
     */
    public function getSteps(): Collection
    {
        return $this->steps;
    }

    public function addStep(ResearchStep $step): static
    {
        if (!$this->steps->contains($step)) {
            $this->steps->add($step);
            $step->setRun($this);
        }

        return $this;
    }
}
