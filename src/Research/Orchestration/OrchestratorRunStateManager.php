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
    private const ANSWER_STREAM_CHUNK_CHARS = 320;

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
        if ('' === $markdown) {
            $this->eventPublisher->publishAnswer($runId, '', true);

            return;
        }

        foreach ($this->chunkAnswer($markdown) as $chunk) {
            if ('' === $chunk) {
                continue;
            }

            $this->eventPublisher->publishAnswer($runId, $chunk, false);
        }

        $this->eventPublisher->publishAnswer($runId, '', true);
    }

    /**
     * @return list<string>
     */
    private function chunkAnswer(string $markdown): array
    {
        $chars = preg_split('//u', $markdown, -1, \PREG_SPLIT_NO_EMPTY);
        if (!\is_array($chars) || [] === $chars) {
            return [$markdown];
        }

        $chunks = [];
        $totalChars = \count($chars);
        for ($offset = 0; $offset < $totalChars; $offset += self::ANSWER_STREAM_CHUNK_CHARS) {
            $chunk = implode('', \array_slice($chars, $offset, self::ANSWER_STREAM_CHUNK_CHARS));
            if ('' !== $chunk) {
                $chunks[] = $chunk;
            }
        }

        return [] === $chunks ? [$markdown] : $chunks;
    }
}
