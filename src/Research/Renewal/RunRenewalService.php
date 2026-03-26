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
    ): void {
        $summary = $this->renewalSummary($strategy, $latestOperation);
        $turnNumber = $latestOperation?->getTurnNumber() ?? 0;
        $phaseMessage = RunRenewalPolicy::STRATEGY_RESTART_FROM_QUEUE === $strategy
            ? 'Run renewed, restarting from queued state'
            : 'Run renewed, retrying last operation';

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
