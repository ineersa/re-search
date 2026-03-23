<?php

declare(strict_types=1);

namespace App\Research\Orchestration;

use App\Entity\Enum\ResearchRunPhase;
use App\Entity\Enum\ResearchRunStatus;
use App\Entity\ResearchRun;
use App\Research\Event\EventPublisherInterface;
use App\Research\Orchestration\Dto\OrchestratorState;

final class OrchestratorRunStateManager
{
    public function __construct(
        private readonly OrchestratorStepRecorder $stepRecorder,
        private readonly EventPublisherInterface $eventPublisher,
    ) {
    }

    /**
     * @param array<string, mixed> $completeMeta
     */
    public function failRun(
        ResearchRun $run,
        OrchestratorState $state,
        int &$sequence,
        int $turnNumber,
        ResearchRunStatus $status,
        string $failureReason,
        string $stepType,
        string $stepSummary,
        array $completeMeta,
    ): void {
        $run->setStatus($status);
        $run->setPhase(ResearchRunStatus::ABORTED === $status ? ResearchRunPhase::ABORTED : ResearchRunPhase::FAILED);
        $run->setFailureReason($failureReason);
        $run->setCompletedAt(new \DateTimeImmutable());
        if (ResearchRunStatus::LOOP_STOPPED === $status) {
            $run->setLoopDetected(true);
        }

        $this->stepRecorder->persistStep($run, $sequence, $stepType, $turnNumber, $stepSummary, null);
        $this->eventPublisher->publishComplete($run->getRunUuid(), $completeMeta);

        $this->persistState($run, $state);
    }

    public function persistState(ResearchRun $run, OrchestratorState $state): void
    {
        $run->setOrchestratorStateJson($state->toJson());
        $run->setOrchestrationVersion($run->getOrchestrationVersion() + 1);
    }

    public function applyTokenUsage(ResearchRun $run, ?int $promptTokens, ?int $completionTokens, ?int $totalTokens): int
    {
        $currentUsed = max(0, $run->getTokenBudgetUsed());

        if (null !== $totalTokens) {
            $nextUsed = max($currentUsed, $totalTokens);
            $run->setTokenBudgetUsed($nextUsed);

            return $nextUsed;
        }

        $increment = max(0, ($promptTokens ?? 0) + ($completionTokens ?? 0));
        $nextUsed = $currentUsed + $increment;
        $run->setTokenBudgetUsed($nextUsed);

        return $nextUsed;
    }

    /**
     * @return array<string, int>
     */
    public function budgetMeta(ResearchRun $run, int $used): array
    {
        $hardCap = $run->getTokenBudgetHardCap();

        return [
            'used' => $used,
            'remaining' => $hardCap - $used,
            'hardCap' => $hardCap,
        ];
    }

    public function publishFinalAnswer(string $runId, string $markdown): void
    {
        $this->eventPublisher->publishAnswer($runId, $markdown, true);
    }
}
