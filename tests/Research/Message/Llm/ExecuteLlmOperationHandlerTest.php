<?php

declare(strict_types=1);

namespace App\Tests\Research\Message\Llm;

use App\Entity\Enum\ResearchOperationStatus;
use App\Entity\Enum\ResearchOperationType;
use App\Entity\ResearchOperation;
use App\Entity\ResearchRun;
use App\Research\Message\Llm\ExecuteLlmOperation;
use App\Research\Message\Llm\ExecuteLlmOperationHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(ExecuteLlmOperationHandler::class)]
final class ExecuteLlmOperationHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ExecuteLlmOperationHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->handler = $container->get(ExecuteLlmOperationHandler::class);
    }

    public function testFailsWhenOperationTypeIsNotLlm(): void
    {
        $run = $this->persistRun();
        $operation = $this->persistOperation($run, ResearchOperationType::TOOL_CALL, '{}');

        ($this->handler)(new ExecuteLlmOperation($operation->getId() ?? 0));

        $this->entityManager->refresh($operation);

        self::assertSame(ResearchOperationStatus::FAILED, $operation->getStatus());
        self::assertSame('Attempted to execute a non-LLM operation on the llm queue.', $operation->getErrorMessage());
        self::assertStringContainsString('Operation type mismatch for llm worker.', $operation->getResultPayloadJson() ?? '');
    }

    public function testFailsWhenRunWasCancelled(): void
    {
        $run = $this->persistRun();
        $run->setCancelRequestedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $operation = $this->persistOperation($run, ResearchOperationType::LLM_CALL, '{}');

        ($this->handler)(new ExecuteLlmOperation($operation->getId() ?? 0));

        $this->entityManager->refresh($operation);

        self::assertSame(ResearchOperationStatus::FAILED, $operation->getStatus());
        self::assertSame('Run cancelled by user', $operation->getErrorMessage());
        self::assertStringContainsString('Run cancelled by user', $operation->getResultPayloadJson() ?? '');
    }

    public function testFailsWhenRequestPayloadIsMalformedJson(): void
    {
        $run = $this->persistRun();
        $operation = $this->persistOperation($run, ResearchOperationType::LLM_CALL, '{bad json');

        ($this->handler)(new ExecuteLlmOperation($operation->getId() ?? 0));

        $this->entityManager->refresh($operation);

        self::assertSame(ResearchOperationStatus::FAILED, $operation->getStatus());
        self::assertNotNull($operation->getStartedAt());
        self::assertNotNull($operation->getErrorMessage());
        self::assertStringContainsString('errorClass', $operation->getResultPayloadJson() ?? '');
    }

    private function persistRun(): ResearchRun
    {
        $suffix = bin2hex(random_bytes(5));
        $query = sprintf('llm handler test %s', $suffix);

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
