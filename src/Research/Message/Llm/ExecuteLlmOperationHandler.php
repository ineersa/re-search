<?php

declare(strict_types=1);

namespace App\Research\Message\Llm;

use App\Entity\Enum\ResearchOperationStatus;
use App\Entity\Enum\ResearchOperationType;
use App\Entity\ResearchOperation;
use App\Entity\ResearchStep;
use App\Repository\ResearchOperationRepository;
use App\Repository\ResearchStepRepository;
use App\Research\Event\EventPublisherInterface;
use App\Research\Message\Llm\Dto\LlmOperationRequest;
use App\Research\Message\Llm\Dto\LlmOperationResultPayload;
use App\Research\Message\Llm\Dto\LlmOperationResultToolCall;
use App\Research\Message\Orchestrator\OrchestratorTick;
use App\Research\Orchestration\OrchestratorOperationPayloadMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingContent;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(fromTransport: 'llm')]
final class ExecuteLlmOperationHandler
{
    private const MAX_LLM_ATTEMPTS = 2;

    public function __construct(
        private readonly ResearchOperationRepository $operationRepository,
        private readonly ResearchStepRepository $stepRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PlatformInterface $platform,
        private readonly ToolboxInterface $toolbox,
        private readonly OrchestratorOperationPayloadMapper $payloadMapper,
        private readonly EventPublisherInterface $eventPublisher,
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

            $result = $this->invokeWithRetry($operation, $model, $messages, $options);
            [$normalizedResult, $metadataSource, $reasoningText] = $this->normalizePlatformResult($result);

            $operation->setStatus(ResearchOperationStatus::SUCCEEDED);
            $operation->setErrorMessage(null);
            $operation->setResultPayloadJson($this->encodeResultPayload($normalizedResult, $metadataSource, $reasoningText));
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

        $options['stream'] = true;
        $options['stream_options'] = ['include_usage' => true];

        return $options;
    }

    private function encodeResultPayload(ResultInterface $result, ?ResultInterface $metadataSource = null, ?string $reasoningText = null): string
    {
        $assistantText = '';
        $toolCalls = [];
        $isFinal = false;

        if ($result instanceof TextResult) {
            $assistantText = $result->getContent();
            $isFinal = '' !== trim($assistantText);
        } elseif ($result instanceof ToolCallResult) {
            $isFinal = false;
            foreach ($result->getContent() as $toolCall) {
                $toolCalls[] = new LlmOperationResultToolCall($toolCall->getName(), $toolCall->getArguments());
            }
        }

        $usageSource = $metadataSource ?? $result;
        $usage = $this->extractTokenUsage($usageSource);

        $payload = new LlmOperationResultPayload(
            assistantText: $assistantText,
            toolCalls: $toolCalls,
            isFinal: $isFinal,
            promptTokens: $usage['promptTokens'],
            completionTokens: $usage['completionTokens'],
            totalTokens: $usage['totalTokens'],
            rawMetadata: $this->payloadMapper->extractRawMetadata($usageSource),
            resultClass: $result::class,
            reasoningText: $reasoningText,
        );

        return $this->payloadMapper->encodeJson($payload);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function invokeWithRetry(ResearchOperation $operation, string $model, MessageBag $messages, array $options): ResultInterface
    {
        $attempt = 0;
        while (true) {
            ++$attempt;
            try {
                return $this->platform->invoke($model, $messages, $options)->getResult();
            } catch (\Throwable $exception) {
                if ($attempt >= self::MAX_LLM_ATTEMPTS || !$this->isRetriableTimeout($exception)) {
                    throw $exception;
                }

                $this->recordRetryAttempt($operation, $attempt, $exception);
            }
        }
    }

    /**
     * @return array{0: ResultInterface, 1: ResultInterface, 2: ?string}
     */
    private function normalizePlatformResult(ResultInterface $result): array
    {
        if (!$result instanceof StreamResult) {
            return [$result, $result, null];
        }

        $assistantText = '';
        $reasoningBuffer = '';
        $toolCalls = [];

        foreach ($result->getContent() as $chunk) {
            if (\is_string($chunk)) {
                $assistantText .= $chunk;

                continue;
            }

            if ($chunk instanceof ThinkingContent) {
                $reasoningBuffer .= $chunk->thinking;

                continue;
            }

            if ($chunk instanceof ToolCallResult) {
                $toolCalls = $chunk->getContent();
            }
        }

        $reasoningText = '' !== trim($reasoningBuffer) ? trim($reasoningBuffer) : null;
        if ([] !== $toolCalls) {
            return [new ToolCallResult(...$toolCalls), $result, $reasoningText];
        }

        return [new TextResult($assistantText), $result, $reasoningText];
    }

    private function isRetriableTimeout(\Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'idle timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'timeout reached');
    }

    private function recordRetryAttempt(ResearchOperation $operation, int $attempt, \Throwable $exception): void
    {
        $run = $operation->getRun();
        $sequence = $this->stepRepository->nextSequenceForRun($run);
        $summary = sprintf('LLM timeout on attempt %d/%d, retrying', $attempt, self::MAX_LLM_ATTEMPTS);
        $payload = $this->payloadMapper->encodeJson([
            'attempt' => $attempt,
            'maxAttempts' => self::MAX_LLM_ATTEMPTS,
            'errorClass' => $exception::class,
            'errorMessage' => $exception->getMessage(),
        ]);

        $step = (new ResearchStep())
            ->setRun($run)
            ->setSequence($sequence)
            ->setType('llm_retry')
            ->setTurnNumber($operation->getTurnNumber())
            ->setSummary($summary)
            ->setPayloadJson($payload);
        $this->entityManager->persist($step);
        $this->entityManager->flush();

        $this->eventPublisher->publishActivity($run->getRunUuid(), 'llm_retry', $summary, [
            'attempt' => $attempt,
            'maxAttempts' => self::MAX_LLM_ATTEMPTS,
            'error' => $exception->getMessage(),
            'sequence' => $sequence,
            'turnNumber' => $operation->getTurnNumber(),
        ]);
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
