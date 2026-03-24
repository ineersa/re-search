<?php

declare(strict_types=1);

namespace App\Tests\Research\Message\Orchestrator;

use App\Entity\Enum\ResearchRunPhase;
use App\Entity\Enum\ResearchRunStatus;
use App\Entity\ResearchRun;
use App\Entity\ResearchStep;
use App\Research\Message\Orchestrator\OrchestratorTick;
use App\Research\Message\Orchestrator\OrchestratorTickHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(OrchestratorTickHandler::class)]
final class OrchestratorTickHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private OrchestratorTickHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->handler = $container->get(OrchestratorTickHandler::class);
    }

    public function testSkipsAlreadyTerminalRun(): void
    {
        $run = $this->createRun(ResearchRunStatus::FAILED, ResearchRunPhase::FAILED);
        $run->setOrchestrationVersion(12);

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        ($this->handler)(new OrchestratorTick($run->getRunUuid()));

        $this->entityManager->refresh($run);

        self::assertSame(ResearchRunStatus::FAILED, $run->getStatus());
        self::assertSame(ResearchRunPhase::FAILED, $run->getPhase());
        self::assertSame(12, $run->getOrchestrationVersion());
        self::assertCount(0, $run->getSteps());
    }

    public function testAbortsRunWhenCancelRequested(): void
    {
        $run = $this->createRun();
        $run->setCancelRequestedAt(new \DateTimeImmutable());

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        ($this->handler)(new OrchestratorTick($run->getRunUuid()));

        $this->entityManager->refresh($run);

        self::assertSame(ResearchRunStatus::ABORTED, $run->getStatus());
        self::assertSame(ResearchRunPhase::ABORTED, $run->getPhase());
        self::assertSame('Cancelled by user', $run->getFailureReason());
        self::assertNotNull($run->getCompletedAt());
        self::assertSame(1, $run->getOrchestrationVersion());
        self::assertCount(1, $run->getSteps());

        $step = $run->getSteps()->first();
        self::assertInstanceOf(ResearchStep::class, $step);
        self::assertSame('run_aborted', $step->getType());
        self::assertSame('Run cancelled by user', $step->getSummary());
    }

    public function testMarksRunFailedWhenStatePayloadIsInvalid(): void
    {
        $run = $this->createRun();
        $run->setOrchestratorStateJson('{"turnNumber":');

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        ($this->handler)(new OrchestratorTick($run->getRunUuid()));

        $this->entityManager->refresh($run);

        self::assertSame(ResearchRunStatus::FAILED, $run->getStatus());
        self::assertSame(ResearchRunPhase::FAILED, $run->getPhase());
        self::assertNotNull($run->getCompletedAt());
        self::assertNotNull($run->getFailureReason());
        self::assertStringContainsStringIgnoringCase('syntax', $run->getFailureReason());
        self::assertCount(1, $run->getSteps());

        $step = $run->getSteps()->first();
        self::assertInstanceOf(ResearchStep::class, $step);
        self::assertSame('run_failed', $step->getType());
        self::assertSame('Unhandled orchestrator exception', $step->getSummary());
    }

    private function createRun(
        ResearchRunStatus $status = ResearchRunStatus::QUEUED,
        ResearchRunPhase $phase = ResearchRunPhase::QUEUED,
    ): ResearchRun {
        $suffix = bin2hex(random_bytes(5));
        $query = sprintf('orchestrator tick test %s', $suffix);

        return (new ResearchRun())
            ->setQuery($query)
            ->setQueryHash(hash('sha256', $query))
            ->setStatus($status)
            ->setPhase($phase)
            ->setClientKey('test-client-'.$suffix)
            ->setMercureTopic('https://tests.example/research/'.$suffix);
    }
}
