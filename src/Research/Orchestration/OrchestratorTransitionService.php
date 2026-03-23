<?php

declare(strict_types=1);

namespace App\Research\Orchestration;

use App\Entity\Enum\ResearchRunPhase;
use App\Entity\Enum\ResearchRunStatus;
use App\Entity\ResearchRun;
use App\Repository\ResearchStepRepository;
use App\Research\Event\EventPublisherInterface;
use App\Research\Orchestration\Dto\NextAction;
use App\Research\Orchestration\Dto\OrchestratorState;
use App\Research\ResearchSystemPromptBuilder;
use App\Research\ResearchTaskPromptBuilder;

final class OrchestratorTransitionService
{
    private const WALL_CLOCK_TIMEOUT_SECONDS = 900;

    public function __construct(
        private readonly ResearchStepRepository $stepRepository,
        private readonly OrchestratorStepRecorder $stepRecorder,
        private readonly OrchestratorTurnProcessor $turnProcessor,
        private readonly OrchestratorRunStateManager $runStateManager,
        private readonly EventPublisherInterface $eventPublisher,
        private readonly ResearchSystemPromptBuilder $systemPromptBuilder,
        private readonly ResearchTaskPromptBuilder $taskPromptBuilder,
    ) {
    }

    public function transition(ResearchRun $run, OrchestratorState $state): NextAction
    {
        if ($this->hasTimedOut($run)) {
            $sequence = $this->stepRepository->nextSequenceForRun($run);

            $run->setStatus(ResearchRunStatus::TIMED_OUT);
            $run->setPhase(ResearchRunPhase::FAILED);
            $run->setFailureReason('Research timed out after '.self::WALL_CLOCK_TIMEOUT_SECONDS.' seconds');
            $run->setCompletedAt(new \DateTimeImmutable());
            $this->eventPublisher->publishPhase(
                $run->getRunUuid(),
                $run->getPhaseValue(),
                $run->getStatusValue(),
                'Research timed out',
                ['turnNumber' => $state->turnNumber],
            );

            $this->stepRecorder->persistStep($run, $sequence, 'run_failed', $state->turnNumber, 'Wall-clock timeout', null);
            $this->eventPublisher->publishComplete($run->getRunUuid(), ['status' => ResearchRunStatus::TIMED_OUT->value]);
            $this->runStateManager->persistState($run, $state);

            return NextAction::none();
        }

        $phase = $run->getPhase();
        if (ResearchRunPhase::RUNNING === $phase) {
            $phase = ResearchRunPhase::QUEUED;
        }

        return match ($phase) {
            ResearchRunPhase::QUEUED => $this->transitionQueued($run),
            ResearchRunPhase::WAITING_LLM => $this->turnProcessor->transitionWaitingLlm($run, $state),
            ResearchRunPhase::WAITING_TOOLS => $this->turnProcessor->transitionWaitingTools($run, $state),
            default => NextAction::none(),
        };
    }

    private function transitionQueued(ResearchRun $run): NextAction
    {
        $sequence = $this->stepRepository->nextSequenceForRun($run);

        $systemPrompt = $this->systemPromptBuilder->build($run->getQuery());
        $taskPrompt = $this->taskPromptBuilder->build($run->getQuery());
        $state = OrchestratorState::initialize($systemPrompt, $taskPrompt);

        $run->setStatus(ResearchRunStatus::RUNNING);
        $run->setFailureReason(null);
        $run->setCompletedAt(null);
        $run->setFinalAnswerMarkdown(null);
        $run->setLoopDetected(false);
        $run->setAnswerOnlyTriggered(false);

        $runStartedSequence = $this->stepRecorder->persistStep(
            $run,
            $sequence,
            'run_started',
            0,
            $taskPrompt,
            null
        );
        $this->eventPublisher->publishActivity($run->getRunUuid(), 'run_started', $taskPrompt, [
            'sequence' => $runStartedSequence,
            'turnNumber' => 0,
        ]);

        return $this->turnProcessor->queueCurrentLlmTurn($run, $state, $sequence);
    }

    private function hasTimedOut(ResearchRun $run): bool
    {
        if ($run->getStatus()->isTerminal()) {
            return false;
        }

        $elapsed = time() - $run->getCreatedAt()->getTimestamp();

        return $elapsed >= self::WALL_CLOCK_TIMEOUT_SECONDS;
    }
}
