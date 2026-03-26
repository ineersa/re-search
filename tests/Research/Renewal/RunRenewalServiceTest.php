<?php

declare(strict_types=1);

namespace App\Tests\Research\Renewal;

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
use App\Research\Renewal\RunRenewalException;
use App\Research\Renewal\RunRenewalPolicy;
use App\Research\Renewal\RunRenewalService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(RunRenewalService::class)]
final class RunRenewalServiceTest extends TestCase
{
    public function testResetsLatestLlmOperationAndRunFields(): void
    {
        $run = $this->createRun(ResearchRunStatus::FAILED, ResearchRunPhase::FAILED, 'LLM operation failed: Idle timeout reached.');
        $operation = $this->createOperation($run, ResearchOperationType::LLM_CALL, 'Idle timeout reached.');
        $this->assignOperationId($operation, 101);

        $operationRepository = $this->createMock(ResearchOperationRepository::class);
        $operationRepository->expects(self::once())
            ->method('findLatestByRun')
            ->with($run)
            ->willReturn($operation);

        $stepRepository = $this->createMock(ResearchStepRepository::class);
        $stepRepository->expects(self::once())
            ->method('nextSequenceForRun')
            ->with($run)
            ->willReturn(7);

        $eventPublisher = $this->createMock(EventPublisherInterface::class);
        $eventPublisher->expects(self::once())
            ->method('publishActivity')
            ->with(
                $run->getRunUuid(),
                'run_renewed',
                self::stringContains('llm_call'),
                self::callback(static fn (array $meta): bool => ($meta['sequence'] ?? null) === 7 && ($meta['turnNumber'] ?? null) === 2)
            );
        $eventPublisher->expects(self::once())
            ->method('publishPhase')
            ->with(
                $run->getRunUuid(),
                ResearchRunPhase::WAITING_LLM->value,
                ResearchRunStatus::RUNNING->value,
                'Run renewed, retrying last operation',
                self::callback(static fn (array $meta): bool => ($meta['turnNumber'] ?? null) === 2)
            );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('refresh')->with($run);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(ResearchStep::class));
        $entityManager->expects(self::once())->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $message): bool => $message instanceof ExecuteLlmOperation && 101 === $message->operationId))
            ->willReturn(new Envelope(new \stdClass()));

        $service = new RunRenewalService(
            new RunRenewalPolicy(),
            new RunOrchestratorLock(new LockFactory(new InMemoryStore())),
            $operationRepository,
            $stepRepository,
            $eventPublisher,
            $entityManager,
            $bus,
        );

        $strategy = $service->renew($run);

        self::assertSame(RunRenewalPolicy::STRATEGY_RETRY_LAST_OPERATION, $strategy);
        self::assertSame(ResearchOperationStatus::QUEUED, $operation->getStatus());
        self::assertNull($operation->getStartedAt());
        self::assertNull($operation->getCompletedAt());
        self::assertNull($operation->getErrorMessage());
        self::assertNull($operation->getResultPayloadJson());

        self::assertSame(ResearchRunStatus::RUNNING, $run->getStatus());
        self::assertSame(ResearchRunPhase::WAITING_LLM, $run->getPhase());
        self::assertNull($run->getFailureReason());
        self::assertNull($run->getCompletedAt());
        self::assertNull($run->getCancelRequestedAt());
        self::assertFalse($run->isLoopDetected());

        self::assertCount(1, $run->getSteps());
        $step = $run->getSteps()->first();
        self::assertInstanceOf(ResearchStep::class, $step);
        self::assertSame('run_renewed', $step->getType());

        $state = OrchestratorState::fromJson($run->getOrchestratorStateJson());
        self::assertGreaterThan(0, $state->attemptStartedAtUnix);
    }

    public function testResetsLatestToolOperationAndDispatchesToolMessage(): void
    {
        $run = $this->createRun(ResearchRunStatus::LOOP_STOPPED, ResearchRunPhase::FAILED, 'Duplicate tool call detected.');
        $run->setLoopDetected(true);
        $operation = $this->createOperation($run, ResearchOperationType::TOOL_CALL, 'Temporary network failure');
        $this->assignOperationId($operation, 202);

        $operationRepository = $this->createMock(ResearchOperationRepository::class);
        $operationRepository->expects(self::once())
            ->method('findLatestByRun')
            ->with($run)
            ->willReturn($operation);

        $stepRepository = $this->createMock(ResearchStepRepository::class);
        $stepRepository->expects(self::once())
            ->method('nextSequenceForRun')
            ->with($run)
            ->willReturn(11);

        $eventPublisher = $this->createMock(EventPublisherInterface::class);
        $eventPublisher->expects(self::once())->method('publishActivity');
        $eventPublisher->expects(self::once())
            ->method('publishPhase')
            ->with(
                $run->getRunUuid(),
                ResearchRunPhase::WAITING_TOOLS->value,
                ResearchRunStatus::RUNNING->value,
                'Run renewed, retrying last operation',
                self::callback(static fn (array $meta): bool => ($meta['turnNumber'] ?? null) === 2)
            );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('refresh')->with($run);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(ResearchStep::class));
        $entityManager->expects(self::once())->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $message): bool => $message instanceof ExecuteToolOperation && 202 === $message->operationId))
            ->willReturn(new Envelope(new \stdClass()));

        $service = new RunRenewalService(
            new RunRenewalPolicy(),
            new RunOrchestratorLock(new LockFactory(new InMemoryStore())),
            $operationRepository,
            $stepRepository,
            $eventPublisher,
            $entityManager,
            $bus,
        );

        $strategy = $service->renew($run);

        self::assertSame(RunRenewalPolicy::STRATEGY_RETRY_LAST_OPERATION, $strategy);
        self::assertSame(ResearchOperationStatus::QUEUED, $operation->getStatus());
        self::assertSame(ResearchRunStatus::RUNNING, $run->getStatus());
        self::assertSame(ResearchRunPhase::WAITING_TOOLS, $run->getPhase());
        self::assertFalse($run->isLoopDetected());
        self::assertCount(1, $run->getSteps());
    }

    public function testRestartsAbortedRunFromQueueWhenNoOperationExists(): void
    {
        $run = $this->createRun(ResearchRunStatus::ABORTED, ResearchRunPhase::ABORTED, 'Cancelled by user');

        $operationRepository = $this->createMock(ResearchOperationRepository::class);
        $operationRepository->expects(self::once())
            ->method('findLatestByRun')
            ->with($run)
            ->willReturn(null);

        $stepRepository = $this->createMock(ResearchStepRepository::class);
        $stepRepository->expects(self::once())
            ->method('nextSequenceForRun')
            ->with($run)
            ->willReturn(4);

        $eventPublisher = $this->createMock(EventPublisherInterface::class);
        $eventPublisher->expects(self::once())
            ->method('publishActivity')
            ->with(
                $run->getRunUuid(),
                'run_renewed',
                'Renewed run: restarting from queued state',
                self::callback(static fn (array $meta): bool => ($meta['sequence'] ?? null) === 4 && ($meta['turnNumber'] ?? null) === 0)
            );
        $eventPublisher->expects(self::once())
            ->method('publishPhase')
            ->with(
                $run->getRunUuid(),
                ResearchRunPhase::QUEUED->value,
                ResearchRunStatus::RUNNING->value,
                'Run renewed, restarting from queued state',
                self::callback(static fn (array $meta): bool => ($meta['turnNumber'] ?? null) === 0)
            );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('refresh')->with($run);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(ResearchStep::class));
        $entityManager->expects(self::once())->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $message): bool => $message instanceof OrchestratorTick && $message->runId === $run->getRunUuid()))
            ->willReturn(new Envelope(new \stdClass()));

        $service = new RunRenewalService(
            new RunRenewalPolicy(),
            new RunOrchestratorLock(new LockFactory(new InMemoryStore())),
            $operationRepository,
            $stepRepository,
            $eventPublisher,
            $entityManager,
            $bus,
        );

        $strategy = $service->renew($run);

        self::assertSame(RunRenewalPolicy::STRATEGY_RESTART_FROM_QUEUE, $strategy);
        self::assertSame(ResearchRunStatus::RUNNING, $run->getStatus());
        self::assertSame(ResearchRunPhase::QUEUED, $run->getPhase());
        self::assertNull($run->getFailureReason());
        self::assertNull($run->getCompletedAt());
        self::assertNull($run->getCancelRequestedAt());
        self::assertFalse($run->isLoopDetected());
        self::assertCount(1, $run->getSteps());

        $state = OrchestratorState::fromJson($run->getOrchestratorStateJson());
        self::assertGreaterThan(0, $state->attemptStartedAtUnix);
    }

    public function testThrowsDomainExceptionWhenLatestOperationMissing(): void
    {
        $run = $this->createRun(ResearchRunStatus::TIMED_OUT, ResearchRunPhase::FAILED, 'Research timed out after 900 seconds');

        $operationRepository = $this->createMock(ResearchOperationRepository::class);
        $operationRepository->expects(self::once())
            ->method('findLatestByRun')
            ->with($run)
            ->willReturn(null);

        $stepRepository = $this->createMock(ResearchStepRepository::class);
        $stepRepository->expects(self::never())->method('nextSequenceForRun');

        $eventPublisher = $this->createMock(EventPublisherInterface::class);
        $eventPublisher->expects(self::never())->method('publishActivity');
        $eventPublisher->expects(self::never())->method('publishPhase');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('refresh')->with($run);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $service = new RunRenewalService(
            new RunRenewalPolicy(),
            new RunOrchestratorLock(new LockFactory(new InMemoryStore())),
            $operationRepository,
            $stepRepository,
            $eventPublisher,
            $entityManager,
            $bus,
        );

        $this->expectException(RunRenewalException::class);
        $this->expectExceptionMessage('has no operation to retry');

        $service->renew($run);
    }

    private function createRun(ResearchRunStatus $status, ResearchRunPhase $phase, ?string $failureReason): ResearchRun
    {
        $suffix = bin2hex(random_bytes(5));
        $query = sprintf('run renewal service test %s', $suffix);

        $run = (new ResearchRun())
            ->setQuery($query)
            ->setQueryHash(hash('sha256', $query))
            ->setClientKey('test-client-'.$suffix)
            ->setMercureTopic('https://tests.example/research/'.$suffix)
            ->setStatus($status)
            ->setPhase($phase)
            ->setFailureReason($failureReason)
            ->setCompletedAt($status->isTerminal() ? new \DateTimeImmutable() : null)
            ->setCancelRequestedAt(new \DateTimeImmutable('-2 minutes'));

        $run->setOrchestratorStateJson((new OrchestratorState(
            turnNumber: 2,
            attemptStartedAtUnix: 10,
        ))->toJson());

        return $run;
    }

    private function createOperation(ResearchRun $run, ResearchOperationType $type, string $errorMessage): ResearchOperation
    {
        return (new ResearchOperation())
            ->setRun($run)
            ->setType($type)
            ->setStatus(ResearchOperationStatus::FAILED)
            ->setTurnNumber(2)
            ->setPosition(0)
            ->setIdempotencyKey(sprintf('%s:%s:%s', $run->getRunUuid(), $type->value, bin2hex(random_bytes(6))))
            ->setRequestPayloadJson('{}')
            ->setResultPayloadJson(json_encode([
                'errorClass' => \RuntimeException::class,
                'errorMessage' => $errorMessage,
            ], \JSON_THROW_ON_ERROR))
            ->setErrorMessage($errorMessage)
            ->setStartedAt(new \DateTimeImmutable('-1 minute'))
            ->setCompletedAt(new \DateTimeImmutable());
    }

    private function assignOperationId(ResearchOperation $operation, int $id): void
    {
        $property = new \ReflectionProperty(ResearchOperation::class, 'id');
        $property->setValue($operation, $id);
    }
}
