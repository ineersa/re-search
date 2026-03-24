<?php

declare(strict_types=1);

namespace App\Tests\Research\FixtureReplay;

use App\Entity\ResearchOperation;
use App\Entity\ResearchRun;
use App\Repository\ResearchOperationRepository;
use App\Repository\ResearchRunRepository;
use App\Research\Message\Llm\ExecuteLlmOperation;
use App\Research\Message\Llm\ExecuteLlmOperationHandler;
use App\Research\Message\Tool\ExecuteToolOperation;
use App\Research\Message\Tool\ExecuteToolOperationHandler;
use App\Research\Orchestration\Dto\NextAction;
use App\Research\Orchestration\Dto\OrchestratorState;
use App\Research\Orchestration\OrchestratorTransitionService;
use App\Tests\Research\FixtureReplay\Support\TraceFixtureRuntime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ResearchTraceWorkflowCoverageTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ResearchRunRepository $runRepository;
    private ResearchOperationRepository $operationRepository;
    private OrchestratorTransitionService $transitionService;
    private ExecuteLlmOperationHandler $llmHandler;
    private ExecuteToolOperationHandler $toolHandler;
    private TraceFixtureRuntime $fixtureRuntime;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->runRepository = $container->get(ResearchRunRepository::class);
        $this->operationRepository = $container->get(ResearchOperationRepository::class);
        $this->transitionService = $container->get(OrchestratorTransitionService::class);
        $this->llmHandler = $container->get(ExecuteLlmOperationHandler::class);
        $this->toolHandler = $container->get(ExecuteToolOperationHandler::class);
        $this->fixtureRuntime = $container->get(TraceFixtureRuntime::class);
    }

    protected function tearDown(): void
    {
        $this->fixtureRuntime->clear();
        parent::tearDown();
    }

    #[DataProvider('workflowFixtureProvider')]
    public function testWorkflowUsesFixtureBackedMocksAndReachesExpectedTerminalState(string $fixtureName): void
    {
        $this->fixtureRuntime->loadFixture($fixtureName);
        $run = $this->createQueuedRun($fixtureName);

        $terminalRun = $this->driveRunToTerminal($run->getRunUuid());
        $expectation = $this->fixtureRuntime->runExpectation();
        $expectedRun = $expectation['run'];
        $expected = $expectation['expected'];

        $failureContext = sprintf(
            'failureReason=%s; operationDebug=%s',
            (string) $terminalRun->getFailureReason(),
            $this->operationDebugSummary($terminalRun),
        );

        self::assertSame($expectedRun['status'] ?? null, $terminalRun->getStatusValue(), $failureContext);
        self::assertSame($expectedRun['phase'] ?? null, $terminalRun->getPhaseValue(), $failureContext);
        self::assertSame($expected['finalStatus'] ?? null, $terminalRun->getStatusValue(), $failureContext);
        self::assertSame($expected['finalPhase'] ?? null, $terminalRun->getPhaseValue(), $failureContext);

        $stepTypes = array_map(
            static fn ($step): string => $step->getType(),
            $terminalRun->getSteps()->toArray(),
        );

        $mustContain = is_array($expected['mustContainStepTypes'] ?? null) ? $expected['mustContainStepTypes'] : [];
        foreach ($mustContain as $type) {
            self::assertContains((string) $type, $stepTypes);
        }

        $mustNotContain = is_array($expected['mustNotContainStepTypes'] ?? null) ? $expected['mustNotContainStepTypes'] : [];
        foreach ($mustNotContain as $type) {
            self::assertNotContains((string) $type, $stepTypes);
        }

    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function workflowFixtureProvider(): iterable
    {
        yield 'completed happy path' => ['completed_happy_path'];
        yield 'failed llm operation' => ['failed_llm_operation'];
        yield 'loop stopped' => ['loop_stopped_duplicate_signature'];
        yield 'empty response retries' => ['failed_empty_response_retries'];
        yield 'answer only' => ['answer_only_enabled_then_final'];
    }

    private function createQueuedRun(string $fixtureName): ResearchRun
    {
        $query = sprintf('Fixture workflow replay: %s', $fixtureName);
        $run = (new ResearchRun())
            ->setQuery($query)
            ->setQueryHash(hash('sha256', $query))
            ->setClientKey('fixture-coverage-'.substr(sha1($fixtureName.microtime(true)), 0, 16))
            ->setMercureTopic('https://fixture.test/research/runs/'.bin2hex(random_bytes(12)));

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function driveRunToTerminal(string $runUuid): ResearchRun
    {
        $maxIterations = 180;
        $iteration = 0;

        while (++$iteration <= $maxIterations) {
            $run = $this->runRepository->findEntity($runUuid);
            self::assertInstanceOf(ResearchRun::class, $run);

            if ($run->getStatus()->isTerminal()) {
                return $run;
            }

            $state = OrchestratorState::fromJson($run->getOrchestratorStateJson());
            $nextAction = $this->transitionService->transition($run, $state);
            $this->entityManager->flush();

            $this->dispatchNextAction($nextAction);
        }

        self::fail(sprintf('Run %s did not reach terminal status after %d iterations.', $runUuid, $maxIterations));
    }

    private function dispatchNextAction(NextAction $nextAction): void
    {
        if ('none' === $nextAction->type) {
            return;
        }

        if ('dispatch_llm' === $nextAction->type) {
            $operationKey = $nextAction->operationKeys[0] ?? '';
            $operation = $this->operationRepository->findByIdempotencyKey($operationKey);
            self::assertInstanceOf(ResearchOperation::class, $operation);
            self::assertNotNull($operation->getId());

            ($this->llmHandler)(new ExecuteLlmOperation($operation->getId()));

            return;
        }

        if ('dispatch_tools' === $nextAction->type) {
            foreach ($nextAction->operationKeys as $operationKey) {
                $operation = $this->operationRepository->findByIdempotencyKey($operationKey);
                self::assertInstanceOf(ResearchOperation::class, $operation);
                self::assertNotNull($operation->getId());
                ($this->toolHandler)(new ExecuteToolOperation($operation->getId()));
            }

            return;
        }

        self::fail(sprintf('Unknown next action type: %s', $nextAction->type));
    }

    private function operationDebugSummary(ResearchRun $run): string
    {
        $operations = $this->operationRepository->findBy(['run' => $run], ['turnNumber' => 'ASC', 'position' => 'ASC', 'id' => 'ASC']);

        $parts = [];
        foreach ($operations as $operation) {
            if (!$operation instanceof ResearchOperation) {
                continue;
            }

            $parts[] = sprintf(
                '%s:t%d:p%d:%s:%s',
                $operation->getType()->value,
                $operation->getTurnNumber(),
                $operation->getPosition(),
                $operation->getStatus()->value,
                (string) $operation->getErrorMessage(),
            );
        }

        return implode('|', $parts);
    }
}
