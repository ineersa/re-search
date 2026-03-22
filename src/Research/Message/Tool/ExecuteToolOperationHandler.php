<?php

declare(strict_types=1);

namespace App\Research\Message\Tool;

use App\Entity\ResearchOperation;
use App\Entity\Enum\ResearchOperationStatus;
use App\Entity\Enum\ResearchOperationType;
use App\Repository\ResearchOperationRepository;
use App\Research\Message\Orchestrator\OrchestratorTick;
use App\Research\Message\Tool\Dto\ToolOperationErrorPayload;
use App\Research\Message\Tool\Dto\ToolOperationRequest;
use App\Research\Message\Tool\Dto\ToolOperationResultPayload;
use App\Research\Orchestration\OrchestratorOperationPayloadMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResultConverter;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(fromTransport: 'tool')]
final readonly class ExecuteToolOperationHandler
{
    public function __construct(
        private ResearchOperationRepository $operationRepository,
        private EntityManagerInterface $entityManager,
        private ToolboxInterface $toolbox,
        private MessageBusInterface $bus,
        private OrchestratorOperationPayloadMapper $payloadMapper,
        private ToolResultConverter $toolResultConverter = new ToolResultConverter(),
    ) {
    }

    public function __invoke(ExecuteToolOperation $message): void
    {
        $operation = $this->operationRepository->find($message->operationId);
        if (!$operation instanceof ResearchOperation) {
            return;
        }

        $runId = $operation->getRun()->getRunUuid();

        if ($operation->isTerminalStatus()) {
            $this->bus->dispatch(new OrchestratorTick($runId));

            return;
        }

        if (ResearchOperationType::TOOL_CALL !== $operation->getType()) {
            $operation->setStatus(ResearchOperationStatus::FAILED);
            $operation->setErrorMessage('Attempted to execute a non-tool operation on the tool queue.');
            $operation->setResultPayloadJson($this->payloadMapper->encodeJson(new ToolOperationErrorPayload(
                \UnexpectedValueException::class,
                'Operation type mismatch for tool worker.'
            )));
            $operation->setCompletedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->bus->dispatch(new OrchestratorTick($runId));

            return;
        }

        if (ResearchOperationStatus::QUEUED === $operation->getStatus()) {
            $operation->setStatus(ResearchOperationStatus::RUNNING);
        }

        if (null === $operation->getStartedAt()) {
            $operation->setStartedAt(new \DateTimeImmutable());
        }

        $operation->setCompletedAt(null);
        $operation->setErrorMessage(null);
        $this->entityManager->flush();

        try {
            $request = $this->decodeRequestPayload($operation->getRequestPayloadJson());
            $toolName = $this->extractToolName($request);
            $arguments = $request->arguments;
            $callId = $this->extractCallId($request, $operation->getId());

            $toolCall = new ToolCall($callId, $toolName, $arguments);
            $toolResult = $this->toolbox->execute($toolCall);
            $resultContent = $this->toolResultConverter->convert($toolResult) ?? '';

            $operation->setStatus(ResearchOperationStatus::SUCCEEDED);
            $operation->setErrorMessage(null);
            $operation->setResultPayloadJson($this->payloadMapper->encodeJson(new ToolOperationResultPayload(
                $callId,
                $toolName,
                $arguments,
                $resultContent
            )));
            $operation->setCompletedAt(new \DateTimeImmutable());
        } catch (\Throwable $exception) {
            $operation->setStatus(ResearchOperationStatus::FAILED);
            $operation->setErrorMessage($exception->getMessage());
            $operation->setResultPayloadJson($this->payloadMapper->encodeJson(new ToolOperationErrorPayload(
                $exception::class,
                $exception->getMessage()
            )));
            $operation->setCompletedAt(new \DateTimeImmutable());
        }

        $this->entityManager->flush();
        $this->bus->dispatch(new OrchestratorTick($runId));
    }

    private function decodeRequestPayload(string $requestPayloadJson): ToolOperationRequest
    {
        return $this->payloadMapper->decodeToolRequest($requestPayloadJson);
    }

    private function extractToolName(ToolOperationRequest $request): string
    {
        $candidate = $request->name ?? $request->toolName;
        if (null === $candidate || '' === trim($candidate)) {
            throw new \UnexpectedValueException('Tool operation payload must include a non-empty tool name.');
        }

        return $candidate;
    }

    private function extractCallId(ToolOperationRequest $request, ?int $operationId): string
    {
        $candidate = $request->callId;
        if (null !== $candidate && '' !== trim($candidate)) {
            return $candidate;
        }

        return 'op_'.($operationId ?? 0);
    }
}
