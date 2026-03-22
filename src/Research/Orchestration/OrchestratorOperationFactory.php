<?php

declare(strict_types=1);

namespace App\Research\Orchestration;

use App\Entity\Enum\ResearchOperationStatus;
use App\Entity\Enum\ResearchOperationType;
use App\Entity\ResearchOperation;
use App\Entity\ResearchRun;
use App\Repository\ResearchOperationRepository;
use App\Research\Message\Llm\Dto\LlmOperationRequest;
use App\Research\Message\Tool\Dto\ToolOperationRequest;
use App\Research\Orchestration\Dto\OrchestratorState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class OrchestratorOperationFactory
{
    public function __construct(
        private readonly ResearchOperationRepository $operationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly OrchestratorOperationPayloadMapper $payloadMapper,
        #[Autowire('%research.model%')]
        private readonly string $defaultModel,
    ) {
    }

    public function llmIdempotencyKey(ResearchRun $run, int $turnNumber): string
    {
        return \sprintf('%s:llm:%d', $run->getRunUuid(), $turnNumber);
    }

    public function getOrCreateLlmOperation(ResearchRun $run, int $turnNumber, OrchestratorState $state, bool $allowTools): ResearchOperation
    {
        $idempotencyKey = $this->llmIdempotencyKey($run, $turnNumber);
        $existing = $this->operationRepository->findByIdempotencyKey($idempotencyKey);
        if ($existing instanceof ResearchOperation) {
            return $existing;
        }

        $operation = new ResearchOperation();
        $operation->setRun($run);
        $operation->setType(ResearchOperationType::LLM_CALL);
        $operation->setStatus(ResearchOperationStatus::QUEUED);
        $operation->setTurnNumber($turnNumber);
        $operation->setPosition(0);
        $operation->setIdempotencyKey($idempotencyKey);
        $operation->setRequestPayloadJson($this->buildLlmRequestPayload($state, $allowTools));
        $operation->setResultPayloadJson(null);
        $operation->setErrorMessage(null);
        $operation->setStartedAt(null);
        $operation->setCompletedAt(null);

        $this->entityManager->persist($operation);

        return $operation;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function getOrCreateToolOperation(
        ResearchRun $run,
        int $turnNumber,
        int $position,
        string $callId,
        string $toolName,
        array $arguments,
        string $signature,
    ): ResearchOperation {
        $idempotencyKey = $this->toolIdempotencyKey($run, $turnNumber, $position);
        $existing = $this->operationRepository->findByIdempotencyKey($idempotencyKey);
        if ($existing instanceof ResearchOperation) {
            return $existing;
        }

        $operation = new ResearchOperation();
        $operation->setRun($run);
        $operation->setType(ResearchOperationType::TOOL_CALL);
        $operation->setStatus(ResearchOperationStatus::QUEUED);
        $operation->setTurnNumber($turnNumber);
        $operation->setPosition($position);
        $operation->setIdempotencyKey($idempotencyKey);
        $operation->setRequestPayloadJson($this->payloadMapper->encodeJson(new ToolOperationRequest(
            callId: $callId,
            name: $toolName,
            arguments: $arguments,
            normalizedSignature: $signature,
        )));
        $operation->setResultPayloadJson(null);
        $operation->setErrorMessage(null);
        $operation->setStartedAt(null);
        $operation->setCompletedAt(null);

        $this->entityManager->persist($operation);

        return $operation;
    }

    private function toolIdempotencyKey(ResearchRun $run, int $turnNumber, int $position): string
    {
        return \sprintf('%s:tool:%d:%d', $run->getRunUuid(), $turnNumber, $position);
    }

    private function buildLlmRequestPayload(OrchestratorState $state, bool $allowTools): string
    {
        return $this->payloadMapper->encodeJson(new LlmOperationRequest(
            model: $this->defaultModel,
            messages: $state->messageWindow,
            allowTools: $allowTools,
            options: [],
        ));
    }
}
