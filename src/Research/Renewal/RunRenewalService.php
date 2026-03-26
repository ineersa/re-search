<?php

declare(strict_types=1);

namespace App\Research\Renewal;

use App\Entity\Enum\ResearchOperationStatus;
use App\Entity\Enum\ResearchOperationType;
use App\Entity\Enum\ResearchRunPhase;
use App\Entity\Enum\ResearchRunStatus;
use App\Entity\ResearchOperation;
use App\Entity\ResearchRun;
use App\Entity\ResearchStep;
use App\Repository\ResearchOperationRepository;
use App\Repository\ResearchStepRepository;
use App\Research\Event\EventPublisherInterface;
use App\Research\Message\Llm\ExecuteLlmOperation;
use App\Research\Message\Orchestrator\OrchestratorTick;
use App\Research\Message\Tool\ExecuteToolOperation;
use App\Research\Orchestration\Dto\OrchestratorState;
use App\Research\Orchestration\RunOrchestratorLock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class RunRenewalService
{
    private const LOOP_RENEWAL_USER_MESSAGE = 'Loop protection was triggered because the same tool call pattern repeated. Continue from the existing evidence, do not repeat an identical tool call signature that has already been used twice, and if no new tool strategy is available provide your best final answer.';

    public function __construct(
        private readonly RunRenewalPolicy $renewalPolicy,
        private readonly RunOrchestratorLock $runLock,
        private readonly ResearchOperationRepository $operationRepository,
        private readonly ResearchStepRepository $stepRepository,
        private readonly EventPublisherInterface $eventPublisher,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function renew(ResearchRun $run): string
    {
        $runId = $run->getRunUuid();
        $lock = $this->runLock->acquire($runId);
        if (null === $lock) {
            throw RunRenewalException::lockUnavailable($runId);
        }

        try {
            $this->entityManager->refresh($run);

            $latestOperation = $this->operationRepository->findLatestByRun($run);
            $decision = $this->renewalPolicy->classify($run, $latestOperation);
            if (!$decision->renewable) {
                throw RunRenewalException::nonRenewable($decision->reason);
            }

            $strategy = $decision->strategy ?? RunRenewalPolicy::STRATEGY_RETRY_LAST_OPERATION;

            if (RunRenewalPolicy::STRATEGY_RETRY_LAST_OPERATION === $strategy) {
                if (!$latestOperation instanceof ResearchOperation) {
                    throw RunRenewalException::missingLatestOperation($runId);
                }

                if (ResearchRunStatus::LOOP_STOPPED === $run->getStatus()) {
                    $this->renewLoopStoppedRun($run, $latestOperation, $strategy);

                    return $strategy;
                }

                $this->resetAttemptBaseline($run, $latestOperation);
                $this->resetOperation($latestOperation);
                $this->resetRun($run, $latestOperation);
                $stepSequence = $this->appendRenewalStep($run, $strategy, $latestOperation);
                $this->publishRenewalEvents($run, $stepSequence, $strategy, $latestOperation);

                $this->entityManager->flush();

                $this->dispatchRenewedOperation($latestOperation);

                return $strategy;
            }

            if (RunRenewalPolicy::STRATEGY_RESTART_FROM_QUEUE === $strategy) {
                $this->resetAttemptBaselineForQueue($run);
                $this->resetRunForQueue($run);
                $stepSequence = $this->appendRenewalStep($run, $strategy, null);
                $this->publishRenewalEvents($run, $stepSequence, $strategy, null);

                $this->entityManager->flush();

                $this->bus->dispatch(new OrchestratorTick($runId));

                return $strategy;
            }

            throw RunRenewalException::nonRenewable(sprintf('Unsupported renewal strategy "%s".', $strategy));
        } finally {
            $this->runLock->release($lock);
        }
    }

    private function resetOperation(ResearchOperation $operation): void
    {
        $operation->setStatus(ResearchOperationStatus::QUEUED);
        $operation->setStartedAt(null);
        $operation->setCompletedAt(null);
        $operation->setErrorMessage(null);
        $operation->setResultPayloadJson(null);
    }

    private function resetRun(ResearchRun $run, ResearchOperation $latestOperation): void
    {
        $phase = ResearchOperationType::LLM_CALL === $latestOperation->getType()
            ? ResearchRunPhase::WAITING_LLM
            : ResearchRunPhase::WAITING_TOOLS;

        $run->setStatus(ResearchRunStatus::RUNNING);
        $run->setPhase($phase);
        $run->setFailureReason(null);
        $run->setCompletedAt(null);
        $run->setCancelRequestedAt(null);
        $run->setLoopDetected(false);
    }

    private function renewLoopStoppedRun(ResearchRun $run, ResearchOperation $latestOperation, string $strategy): void
    {
        $state = $this->readStateForOperationRetry($run, $latestOperation);
        $state->attemptStartedAtUnix = time();

        $this->dropOperationsForTurn($run, $state->turnNumber);
        $this->removeLastAssistantToolCallTurn($state);
        $state->appendUserMessage(self::LOOP_RENEWAL_USER_MESSAGE);

        $run->setOrchestratorStateJson($state->toJson());
        $run->setOrchestrationVersion($run->getOrchestrationVersion() + 1);

        $run->setStatus(ResearchRunStatus::RUNNING);
        $run->setPhase(ResearchRunPhase::WAITING_LLM);
        $run->setFailureReason(null);
        $run->setCompletedAt(null);
        $run->setCancelRequestedAt(null);
        $run->setLoopDetected(false);

        $stepSequence = $this->appendRenewalStep($run, $strategy, $latestOperation);
        $this->publishRenewalEvents(
            $run,
            $stepSequence,
            $strategy,
            $latestOperation,
            'Run renewed with anti-loop instruction',
            'Run renewed after loop stop, continuing with anti-loop instruction'
        );

        $this->entityManager->flush();
        $this->bus->dispatch(new OrchestratorTick($run->getRunUuid()));
    }

    private function resetRunForQueue(ResearchRun $run): void
    {
        $run->setStatus(ResearchRunStatus::RUNNING);
        $run->setPhase(ResearchRunPhase::QUEUED);
        $run->setFailureReason(null);
        $run->setCompletedAt(null);
        $run->setCancelRequestedAt(null);
        $run->setLoopDetected(false);
    }

    private function resetAttemptBaseline(ResearchRun $run, ResearchOperation $latestOperation): void
    {
        $state = $this->readStateForOperationRetry($run, $latestOperation);

        $state->attemptStartedAtUnix = time();

        $run->setOrchestratorStateJson($state->toJson());
        $run->setOrchestrationVersion($run->getOrchestrationVersion() + 1);
    }

    private function resetAttemptBaselineForQueue(ResearchRun $run): void
    {
        $state = $this->readStateLeniently($run->getOrchestratorStateJson());
        $state->attemptStartedAtUnix = time();

        $run->setOrchestratorStateJson($state->toJson());
        $run->setOrchestrationVersion($run->getOrchestrationVersion() + 1);
    }

    private function appendRenewalStep(ResearchRun $run, string $strategy, ?ResearchOperation $latestOperation): int
    {
        $summary = $this->renewalSummary($strategy, $latestOperation);
        $turnNumber = $latestOperation?->getTurnNumber() ?? 0;
        $sequence = $this->stepRepository->nextSequenceForRun($run);

        $step = (new ResearchStep())
            ->setRun($run)
            ->setSequence($sequence)
            ->setType('run_renewed')
            ->setTurnNumber($turnNumber)
            ->setSummary($summary)
            ->setPayloadJson($this->encodeJson([
                'strategy' => $strategy,
                'operationType' => $latestOperation?->getType()->value,
                'operationId' => $latestOperation?->getId(),
            ]));

        $run->addStep($step);
        $this->entityManager->persist($step);

        return $sequence;
    }

    private function publishRenewalEvents(
        ResearchRun $run,
        int $stepSequence,
        string $strategy,
        ?ResearchOperation $latestOperation,
        ?string $phaseMessageOverride = null,
        ?string $activitySummaryOverride = null,
    ): void {
        $summary = $activitySummaryOverride ?? $this->renewalSummary($strategy, $latestOperation);
        $turnNumber = $latestOperation?->getTurnNumber() ?? 0;
        $phaseMessage = $phaseMessageOverride ?? (RunRenewalPolicy::STRATEGY_RESTART_FROM_QUEUE === $strategy
            ? 'Run renewed, restarting from queued state'
            : 'Run renewed, retrying last operation');

        $this->eventPublisher->publishActivity($run->getRunUuid(), 'run_renewed', $summary, [
            'operationType' => $latestOperation?->getType()->value,
            'sequence' => $stepSequence,
            'turnNumber' => $turnNumber,
        ]);

        $this->eventPublisher->publishPhase(
            $run->getRunUuid(),
            $run->getPhaseValue(),
            $run->getStatusValue(),
            $phaseMessage,
            ['turnNumber' => $turnNumber]
        );
    }

    private function renewalSummary(string $strategy, ?ResearchOperation $latestOperation): string
    {
        if (RunRenewalPolicy::STRATEGY_RESTART_FROM_QUEUE === $strategy) {
            return 'Renewed run: restarting from queued state';
        }

        return sprintf('Renewed run: retrying last %s operation', $latestOperation?->getType()->value ?? 'unknown');
    }

    private function readStateLeniently(?string $stateJson): OrchestratorState
    {
        if (null === $stateJson || '' === trim($stateJson)) {
            return new OrchestratorState();
        }

        try {
            return OrchestratorState::fromJson($stateJson);
        } catch (\Throwable) {
            return new OrchestratorState();
        }
    }

    private function readStateForOperationRetry(ResearchRun $run, ResearchOperation $latestOperation): OrchestratorState
    {
        $stateJson = $run->getOrchestratorStateJson();
        if (null === $stateJson || '' === trim($stateJson)) {
            throw RunRenewalException::nonRenewable('Run state is missing and cannot be renewed safely.');
        }

        try {
            $state = OrchestratorState::fromJson($stateJson);
        } catch (\Throwable) {
            throw RunRenewalException::nonRenewable('Run state is invalid and cannot be renewed safely.');
        }

        if ($state->turnNumber !== $latestOperation->getTurnNumber()) {
            throw RunRenewalException::nonRenewable('Run state is out of sync with the latest operation.');
        }

        return $state;
    }

    private function dropOperationsForTurn(ResearchRun $run, int $turnNumber): void
    {
        $operations = $this->operationRepository->findByRunAndTurnOrderedByPosition($run, $turnNumber);
        foreach ($operations as $operation) {
            $this->entityManager->remove($operation);
        }
    }

    private function removeLastAssistantToolCallTurn(OrchestratorState $state): void
    {
        $messages = $state->messageWindow;
        if ([] === $messages) {
            return;
        }

        $assistantIndex = null;
        $toolCallIds = [];

        for ($index = \count($messages) - 1; $index >= 0; --$index) {
            $entry = $messages[$index] ?? null;
            if (!\is_array($entry)) {
                continue;
            }

            if ('assistant' !== ($entry['role'] ?? null)) {
                continue;
            }

            $toolCalls = $entry['toolCalls'] ?? null;
            if (!\is_array($toolCalls) || [] === $toolCalls) {
                continue;
            }

            $assistantIndex = $index;

            foreach ($toolCalls as $toolCall) {
                if (!\is_array($toolCall)) {
                    continue;
                }

                $id = $toolCall['id'] ?? null;
                if (\is_string($id) && '' !== trim($id)) {
                    $toolCallIds[] = $id;
                }
            }

            break;
        }

        if (null === $assistantIndex) {
            return;
        }

        $toolCallIds = array_values(array_unique($toolCallIds));
        $filtered = [];

        foreach ($messages as $index => $entry) {
            if ($index === $assistantIndex) {
                continue;
            }

            if (
                $index > $assistantIndex
                && \is_array($entry)
                && 'tool' === ($entry['role'] ?? null)
                && \is_string($entry['toolCallId'] ?? null)
                && \in_array($entry['toolCallId'], $toolCallIds, true)
            ) {
                continue;
            }

            $filtered[] = $entry;
        }

        $state->messageWindow = $filtered;
    }

    private function dispatchRenewedOperation(ResearchOperation $operation): void
    {
        $operationId = $operation->getId();
        if (null === $operationId) {
            throw RunRenewalException::missingLatestOperation($operation->getRun()->getRunUuid());
        }

        if (ResearchOperationType::LLM_CALL === $operation->getType()) {
            $this->bus->dispatch(new ExecuteLlmOperation($operationId));

            return;
        }

        $this->bus->dispatch(new ExecuteToolOperation($operationId));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload): string
    {
        try {
            return json_encode($payload, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return '{}';
        }
    }
}
