<?php

declare(strict_types=1);

namespace App\Research\Message\Orchestrator;

use App\Entity\Enum\ResearchRunPhase;
use App\Entity\Enum\ResearchRunStatus;
use App\Entity\ResearchOperation;
use App\Entity\ResearchRun;
use App\Entity\ResearchStep;
use App\Repository\ResearchOperationRepository;
use App\Repository\ResearchRunRepository;
use App\Repository\ResearchStepRepository;
use App\Research\Event\EventPublisherInterface;
use App\Research\Message\Llm\ExecuteLlmOperation;
use App\Research\Message\Orchestrator\Dto\OrchestratorUnexpectedFailurePayload;
use App\Research\Message\Tool\ExecuteToolOperation;
use App\Research\Orchestration\Dto\NextAction;
use App\Research\Orchestration\Dto\OrchestratorState;
use App\Research\Orchestration\OrchestratorTransitionService;
use App\Research\Orchestration\RunOrchestratorLock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

#[AsMessageHandler(fromTransport: 'orchestrator')]
final class OrchestratorTickHandler
{
    private readonly Serializer $serializer;

    public function __construct(
        private readonly ResearchRunRepository $runRepository,
        private readonly ResearchOperationRepository $operationRepository,
        private readonly ResearchStepRepository $stepRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly RunOrchestratorLock $runLock,
        private readonly OrchestratorTransitionService $transitionService,
        private readonly EventPublisherInterface $eventPublisher,
    ) {
        $this->serializer = new Serializer(
            [new ObjectNormalizer()],
            [new JsonEncoder()]
        );
    }

    public function __invoke(OrchestratorTick $message): void
    {
        $run = $this->runRepository->findEntity($message->runId);
        if (null === $run || $run->getStatus()->isTerminal()) {
            return;
        }

        $lock = $this->runLock->acquire($run->getRunUuid());
        if (null === $lock) {
            return;
        }

        try {
            $run = $this->runRepository->findEntity($message->runId);
            if (null === $run || $run->getStatus()->isTerminal()) {
                return;
            }

            $state = OrchestratorState::fromJson($run->getOrchestratorStateJson());

            if (null !== $run->getCancelRequestedAt()) {
                $this->abortRun($run, $state);
                $this->entityManager->flush();

                return;
            }

            $nextAction = $this->transitionService->transition($run, $state);
            $this->entityManager->flush();

            $this->dispatchNextAction($nextAction);
        } catch (\Throwable $exception) {
            $this->markUnexpectedFailure($message->runId, $exception->getMessage());
            $this->entityManager->flush();
        } finally {
            $this->runLock->release($lock);
        }
    }

    private function dispatchNextAction(NextAction $nextAction): void
    {
        if ('dispatch_llm' === $nextAction->type) {
            $this->dispatchLlmOperation($nextAction);

            return;
        }

        if ('dispatch_tools' === $nextAction->type) {
            $this->dispatchToolOperations($nextAction);
        }
    }

    private function dispatchLlmOperation(NextAction $nextAction): void
    {
        $operation = $this->findOperationByIdempotencyKey($nextAction->operationKeys[0] ?? '');
        if (null === $operation || null === $operation->getId()) {
            return;
        }

        $this->bus->dispatch(new ExecuteLlmOperation($operation->getId()));
    }

    private function dispatchToolOperations(NextAction $nextAction): void
    {
        foreach ($nextAction->operationKeys as $operationKey) {
            $operation = $this->findOperationByIdempotencyKey($operationKey);
            if (null === $operation || null === $operation->getId()) {
                continue;
            }

            $this->bus->dispatch(new ExecuteToolOperation($operation->getId()));
        }
    }

    private function findOperationByIdempotencyKey(string $operationKey): ?ResearchOperation
    {
        if ('' === trim($operationKey)) {
            return null;
        }

        return $this->operationRepository->findByIdempotencyKey($operationKey);
    }

    private function abortRun(ResearchRun $run, OrchestratorState $state): void
    {
        $this->finalizeRun(
            $run,
            $state,
            ResearchRunStatus::ABORTED,
            ResearchRunPhase::ABORTED,
            'Cancelled by user',
            'run_aborted',
            'Run cancelled by user',
            null,
            ['status' => ResearchRunStatus::ABORTED->value]
        );
    }

    private function markUnexpectedFailure(string $runId, string $reason): void
    {
        $run = $this->runRepository->findEntity($runId);
        if (null === $run || $run->getStatus()->isTerminal()) {
            return;
        }

        try {
            $state = OrchestratorState::fromJson($run->getOrchestratorStateJson());
        } catch (\Throwable) {
            $state = new OrchestratorState();
        }

        $this->finalizeRun(
            $run,
            $state,
            ResearchRunStatus::FAILED,
            ResearchRunPhase::FAILED,
            $reason,
            'run_failed',
            'Unhandled orchestrator exception',
            $this->encodeJson(new OrchestratorUnexpectedFailurePayload($reason)),
            ['status' => ResearchRunStatus::FAILED->value, 'reason' => $reason]
        );
    }

    /**
     * @param array<string, mixed> $completeMeta
     */
    private function finalizeRun(
        ResearchRun $run,
        OrchestratorState $state,
        ResearchRunStatus $status,
        ResearchRunPhase $phase,
        string $failureReason,
        string $stepType,
        string $stepSummary,
        ?string $stepPayloadJson,
        array $completeMeta,
    ): void {
        $sequence = $this->nextSequenceForRun($run);

        $run->setStatus($status);
        $run->setPhase($phase);
        $run->setFailureReason($failureReason);
        $run->setCompletedAt(new \DateTimeImmutable());
        $run->setOrchestratorStateJson($state->toJson());
        $run->setOrchestrationVersion($run->getOrchestrationVersion() + 1);

        $step = new ResearchStep();
        $step->setRun($run);
        $step->setSequence($sequence);
        $step->setType($stepType);
        $step->setTurnNumber($state->turnNumber);
        $step->setSummary($stepSummary);
        $step->setPayloadJson($stepPayloadJson);
        $run->addStep($step);

        $this->entityManager->persist($step);
        $this->eventPublisher->publishComplete($run->getRunUuid(), $completeMeta);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJson(object|array $payload): string
    {
        try {
            return $this->serializer->serialize($payload, 'json');
        } catch (\Throwable) {
            return '{}';
        }
    }

    private function nextSequenceForRun(ResearchRun $run): int
    {
        $nextSequence = $this->stepRepository->nextSequenceForRun($run);

        foreach ($run->getSteps() as $step) {
            if (!$step instanceof ResearchStep) {
                continue;
            }

            $nextSequence = max($nextSequence, $step->getSequence() + 1);
        }

        return $nextSequence;
    }
}
