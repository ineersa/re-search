<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ResearchRunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ResearchRunRepository::class)]
#[ORM\Table(name: 'research_run')]
class ResearchRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::TEXT)]
    private string $query;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $queryHash;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $status = 'queued';

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

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /**
     * @var Collection<int, ResearchStep>
     */
    #[ORM\OneToMany(targetEntity: ResearchStep::class, mappedBy: 'run', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sequence' => 'ASC'])]
    private Collection $steps;

    /**
     * @var Collection<int, ResearchMessage>
     */
    #[ORM\OneToMany(targetEntity: ResearchMessage::class, mappedBy: 'run', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sequence' => 'ASC'])]
    private Collection $messages;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->steps = new ArrayCollection();
        $this->messages = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
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

    /**
     * @return Collection<int, ResearchMessage>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(ResearchMessage $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setRun($this);
        }

        return $this;
    }
}
