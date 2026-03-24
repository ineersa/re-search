<?php

declare(strict_types=1);

namespace App\Tests\Research\Message\Tool;

use App\Entity\Enum\ResearchOperationStatus;
use App\Entity\Enum\ResearchOperationType;
use App\Entity\ResearchOperation;
use App\Entity\ResearchRun;
use App\Research\Message\Tool\ExecuteToolOperation;
use App\Research\Message\Tool\ExecuteToolOperationHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(ExecuteToolOperationHandler::class)]
final class ExecuteToolOperationHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ExecuteToolOperationHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->handler = $container->get(ExecuteToolOperationHandler::class);
    }

    public function testFailsWhenOperationTypeIsNotTool(): void
    {
        $run = $this->persistRun();
        $operation = $this->persistOperation($run, ResearchOperationType::LLM_CALL, '{}');

        ($this->handler)(new ExecuteToolOperation($operation->getId() ?? 0));

        $this->entityManager->refresh($operation);

        self::assertSame(ResearchOperationStatus::FAILED, $operation->getStatus());
        self::assertSame('Attempted to execute a non-tool operation on the tool queue.', $operation->getErrorMessage());
        self::assertStringContainsString('Operation type mismatch for tool worker.', $operation->getResultPayloadJson() ?? '');
    }

    public function testFailsWhenRunWasCancelled(): void
    {
        $run = $this->persistRun();
        $run->setCancelRequestedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $operation = $this->persistOperation($run, ResearchOperationType::TOOL_CALL, '{}');

        ($this->handler)(new ExecuteToolOperation($operation->getId() ?? 0));

        $this->entityManager->refresh($operation);

        self::assertSame(ResearchOperationStatus::FAILED, $operation->getStatus());
        self::assertSame('Run cancelled by user', $operation->getErrorMessage());
        self::assertStringContainsString('Run cancelled by user', $operation->getResultPayloadJson() ?? '');
    }

    public function testFailsWhenToolNameMissingInPayload(): void
    {
        $run = $this->persistRun();
        $operation = $this->persistOperation($run, ResearchOperationType::TOOL_CALL, '{}');

        ($this->handler)(new ExecuteToolOperation($operation->getId() ?? 0));

        $this->entityManager->refresh($operation);

        self::assertSame(ResearchOperationStatus::FAILED, $operation->getStatus());
        self::assertNotNull($operation->getStartedAt());
        self::assertSame('Tool operation payload must include a non-empty tool name.', $operation->getErrorMessage());
        self::assertStringContainsString('Tool operation payload must include a non-empty tool name.', $operation->getResultPayloadJson() ?? '');
    }

    private function persistRun(): ResearchRun
    {
        $suffix = bin2hex(random_bytes(5));
        $query = sprintf('tool handler test %s', $suffix);

        $run = (new ResearchRun())
            ->setQuery($query)
            ->setQueryHash(hash('sha256', $query))
            ->setClientKey('test-client-'.$suffix)
            ->setMercureTopic('https://tests.example/research/'.$suffix);

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function persistOperation(ResearchRun $run, ResearchOperationType $type, string $requestPayloadJson): ResearchOperation
    {
        $operation = (new ResearchOperation())
            ->setRun($run)
            ->setType($type)
            ->setStatus(ResearchOperationStatus::QUEUED)
            ->setTurnNumber(0)
            ->setPosition(0)
            ->setIdempotencyKey(sprintf('%s:%s:%s', $run->getRunUuid(), $type->value, bin2hex(random_bytes(6))))
            ->setRequestPayloadJson($requestPayloadJson);

        $this->entityManager->persist($operation);
        $this->entityManager->flush();

        return $operation;
    }
}
