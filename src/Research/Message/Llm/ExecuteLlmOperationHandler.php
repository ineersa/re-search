<?php

declare(strict_types=1);

namespace App\Research\Message\Llm;

use App\Entity\ResearchOperation;
use App\Entity\Enum\ResearchOperationStatus;
use App\Entity\Enum\ResearchOperationType;
use App\Repository\ResearchOperationRepository;
use App\Research\Message\Llm\Dto\LlmOperationRequest;
use App\Research\Message\Llm\Dto\LlmOperationResultPayload;
use App\Research\Message\Llm\Dto\LlmOperationResultToolCall;
use App\Research\Message\Orchestrator\OrchestratorTick;
use App\Research\Orchestration\OrchestratorOperationPayloadMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(fromTransport: 'llm')]
final class ExecuteLlmOperationHandler
{
    public function __construct(
        private readonly ResearchOperationRepository $operationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PlatformInterface $platform,
        private readonly ToolboxInterface $toolbox,
        private readonly OrchestratorOperationPayloadMapper $payloadMapper,
        private readonly MessageBusInterface $bus,
        #[Autowire('%research.model%')]
        private readonly string $defaultModel,
    ) {
    }

    public function __invoke(ExecuteLlmOperation $message): void
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

        if (ResearchOperationType::LLM_CALL !== $operation->getType()) {
            $operation->setStatus(ResearchOperationStatus::FAILED);
            $operation->setErrorMessage('Attempted to execute a non-LLM operation on the llm queue.');
            $operation->setResultPayloadJson($this->payloadMapper->encodeJson([
                'errorClass' => \UnexpectedValueException::class,
                'errorMessage' => 'Operation type mismatch for llm worker.',
            ]));
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
            $model = $this->resolveModel($request);
            $messages = $this->payloadMapper->toMessageBag($request);
            $options = $this->buildOptions($request);

            $result = $this->platform->invoke($model, $messages, $options)->getResult();

            $operation->setStatus(ResearchOperationStatus::SUCCEEDED);
            $operation->setErrorMessage(null);
            $operation->setResultPayloadJson($this->encodeResultPayload($result));
            $operation->setCompletedAt(new \DateTimeImmutable());
        } catch (\Throwable $exception) {
            $operation->setStatus(ResearchOperationStatus::FAILED);
            $operation->setErrorMessage($exception->getMessage());
            $operation->setResultPayloadJson($this->payloadMapper->encodeJson([
                'errorClass' => $exception::class,
                'errorMessage' => $exception->getMessage(),
            ]));
            $operation->setCompletedAt(new \DateTimeImmutable());
        }

        $this->entityManager->flush();
        $this->bus->dispatch(new OrchestratorTick($runId));
    }

    private function decodeRequestPayload(string $requestPayloadJson): LlmOperationRequest
    {
        return $this->payloadMapper->decodeLlmRequest($requestPayloadJson);
    }

    private function resolveModel(LlmOperationRequest $request): string
    {
        $model = $request->model ?? $this->defaultModel;
        if ('' === trim($model)) {
            return $this->defaultModel;
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOptions(LlmOperationRequest $request): array
    {
        $options = $request->options;

        if ($request->allowTools) {
            $options['tools'] = $this->toolbox->getTools();
        } else {
            unset($options['tools']);
        }

        $options['stream'] = false;
        unset($options['stream_options']);

        return $options;
    }

    private function encodeResultPayload(ResultInterface $result): string
    {
        $assistantText = '';
        $toolCalls = [];
        $isFinal = true;

        if ($result instanceof TextResult) {
            $assistantText = $result->getContent();
        } elseif ($result instanceof ToolCallResult) {
            $isFinal = false;
            foreach ($result->getContent() as $toolCall) {
                $toolCalls[] = new LlmOperationResultToolCall($toolCall->getName(), $toolCall->getArguments());
            }
        }

        $usage = $this->extractTokenUsage($result);

        $payload = new LlmOperationResultPayload(
            assistantText: $assistantText,
            toolCalls: $toolCalls,
            isFinal: $isFinal,
            promptTokens: $usage['promptTokens'],
            completionTokens: $usage['completionTokens'],
            totalTokens: $usage['totalTokens'],
            rawMetadata: $this->payloadMapper->extractRawMetadata($result),
            resultClass: $result::class,
        );

        return $this->payloadMapper->encodeJson($payload);
    }

    /**
     * @return array{promptTokens: int|null, completionTokens: int|null, totalTokens: int|null}
     */
    private function extractTokenUsage(ResultInterface $result): array
    {
        if (!$result->getMetadata()->has('token_usage')) {
            return ['promptTokens' => null, 'completionTokens' => null, 'totalTokens' => null];
        }

        $tokenUsage = $result->getMetadata()->get('token_usage');
        if (!$tokenUsage instanceof TokenUsageInterface) {
            return ['promptTokens' => null, 'completionTokens' => null, 'totalTokens' => null];
        }

        return [
            'promptTokens' => $tokenUsage->getPromptTokens(),
            'completionTokens' => $tokenUsage->getCompletionTokens(),
            'totalTokens' => $tokenUsage->getTotalTokens(),
        ];
    }

}
