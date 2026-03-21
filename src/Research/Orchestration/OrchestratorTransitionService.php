<?php

declare(strict_types=1);

namespace App\Research\Orchestration;

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
use App\Research\Orchestration\Dto\NextAction;
use App\Research\Orchestration\Dto\OrchestratorState;
use App\Research\Orchestration\Dto\ResearchTurnResult;
use App\Research\Orchestration\Dto\ToolCallDecision;
use App\Research\ResearchSystemPromptBuilder;
use App\Research\ResearchTaskPromptBuilder;
use App\Research\Serializer\LlmInvocationTraceSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Chat\MessageNormalizer;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Serializer;

final class OrchestratorTransitionService
{
    private const HARD_CAP_TOKENS = 75_000;
    private const ANSWER_ONLY_THRESHOLD = 5_000;
    private const MAX_TURNS = 75;
    private const MAX_EMPTY_RESPONSE_RETRIES = 5;
    private const MAX_CONSECUTIVE_TOOL_FAILURES = 3;
    private const WALL_CLOCK_TIMEOUT_SECONDS = 900;
    private const MAX_TOOL_RESULT_CHARS = 20_000;
    private const DUPLICATE_TOOL_SIGNATURE_LIMIT = 2;
    private const ANSWER_STREAM_CHUNK_CHARS = 320;
    private const ANSWER_ONLY_MESSAGE = 'Do not use any tools. Provide only your best final answer from the evidence gathered so far. Do not make further tool calls.';
    private const ANSWER_ONLY_RETRY_MESSAGE = 'You requested tools but answer-only mode is active. Provide your best final answer from the evidence gathered. No tool calls allowed.';

    private readonly Serializer $serializer;

    public function __construct(
        private readonly ResearchOperationRepository $operationRepository,
        private readonly ResearchStepRepository $stepRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventPublisherInterface $eventPublisher,
        private readonly ResearchSystemPromptBuilder $systemPromptBuilder,
        private readonly ResearchTaskPromptBuilder $taskPromptBuilder,
        private readonly LlmInvocationTraceSerializer $traceSerializer,
        private readonly ToolboxInterface $toolbox,
        #[Autowire('%research.model%')]
        private readonly string $defaultModel,
    ) {
        $this->serializer = new Serializer(
            [new ArrayDenormalizer(), new MessageNormalizer()],
            [new JsonEncoder()]
        );
    }

    public function transition(ResearchRun $run, OrchestratorState $state): NextAction
    {
        if ($this->hasTimedOut($run)) {
            $sequence = $this->stepRepository->nextSequenceForRun($run);
            $this->failRun(
                $run,
                $state,
                $sequence,
                $state->turnNumber,
                ResearchRunStatus::TIMED_OUT,
                'Research timed out after '.self::WALL_CLOCK_TIMEOUT_SECONDS.' seconds',
                'run_failed',
                'Wall-clock timeout',
                ['status' => ResearchRunStatus::TIMED_OUT->value]
            );

            return NextAction::none();
        }

        $phase = $run->getPhase();
        if (ResearchRunPhase::RUNNING === $phase) {
            $phase = ResearchRunPhase::QUEUED;
        }

        return match ($phase) {
            ResearchRunPhase::QUEUED => $this->transitionQueued($run),
            ResearchRunPhase::WAITING_LLM => $this->transitionWaitingLlm($run, $state),
            ResearchRunPhase::WAITING_TOOLS => $this->transitionWaitingTools($run, $state),
            default => NextAction::none(),
        };
    }

    private function transitionQueued(ResearchRun $run): NextAction
    {
        $sequence = $this->stepRepository->nextSequenceForRun($run);

        $systemPrompt = $this->systemPromptBuilder->build($run->getQuery());
        $taskPrompt = $this->taskPromptBuilder->build($run->getQuery());
        $state = OrchestratorState::initialize($systemPrompt, $taskPrompt);

        $run->setStatus(ResearchRunStatus::RUNNING);
        $run->setFailureReason(null);
        $run->setCompletedAt(null);
        $run->setFinalAnswerMarkdown(null);
        $run->setLoopDetected(false);
        $run->setAnswerOnlyTriggered(false);

        $runStartedSequence = $this->persistStep(
            $run,
            $sequence,
            'run_started',
            0,
            $taskPrompt,
            null
        );
        $this->eventPublisher->publishActivity($run->getRunUuid(), 'run_started', $taskPrompt, [
            'sequence' => $runStartedSequence,
            'turnNumber' => 0,
        ]);

        return $this->queueCurrentLlmTurn($run, $state, $sequence);
    }

    private function transitionWaitingLlm(ResearchRun $run, OrchestratorState $state): NextAction
    {
        $sequence = $this->stepRepository->nextSequenceForRun($run);
        $llmKey = $this->llmIdempotencyKey($run, $state->turnNumber);
        $operation = $this->operationRepository->findByIdempotencyKey($llmKey);

        if (!$operation instanceof ResearchOperation) {
            return $this->queueCurrentLlmTurn($run, $state, $sequence);
        }

        if (!$operation->isTerminalStatus()) {
            return NextAction::none();
        }

        if (ResearchOperationStatus::FAILED === $operation->getStatus()) {
            $this->failRun(
                $run,
                $state,
                $sequence,
                $state->turnNumber,
                ResearchRunStatus::FAILED,
                'LLM operation failed: '.($operation->getErrorMessage() ?? 'Unknown LLM error'),
                'run_failed',
                'LLM operation failed',
                ['status' => ResearchRunStatus::FAILED->value, 'reason' => $operation->getErrorMessage() ?? 'Unknown LLM error']
            );

            return NextAction::none();
        }

        $resultPayload = $this->decodePayloadObject($operation->getResultPayloadJson(), 'llm result payload');
        $requestPayload = $this->decodePayloadObject($operation->getRequestPayloadJson(), 'llm request payload');

        $assistantText = \is_string($resultPayload['assistantText'] ?? null) ? $resultPayload['assistantText'] : '';
        $isFinal = (bool) ($resultPayload['isFinal'] ?? false);
        $toolCalls = $this->normalizeToolCalls($resultPayload['toolCalls'] ?? []);
        $promptTokens = $this->toNullableInt($resultPayload['promptTokens'] ?? null);
        $completionTokens = $this->toNullableInt($resultPayload['completionTokens'] ?? null);
        $totalTokens = $this->toNullableInt($resultPayload['totalTokens'] ?? null);
        $rawMetadata = \is_array($resultPayload['rawMetadata'] ?? null) ? $resultPayload['rawMetadata'] : [];

        $this->persistLlmInvocation(
            $run,
            $sequence,
            $state->turnNumber,
            $requestPayload,
            $assistantText,
            $toolCalls,
            $isFinal,
            $promptTokens,
            $completionTokens,
            $totalTokens,
            $rawMetadata
        );

        $newBudgetUsed = $this->applyTokenUsage($run, $promptTokens, $completionTokens, $totalTokens);
        if (null !== $promptTokens || null !== $completionTokens || null !== $totalTokens) {
            $this->persistTokenSnapshot($run, $sequence, $state->turnNumber, $promptTokens, $completionTokens, $totalTokens, $newBudgetUsed);
        }
        $this->eventPublisher->publishBudget($run->getRunUuid(), $this->budgetMeta($newBudgetUsed));

        if (!$isFinal && [] === $toolCalls && '' === trim($assistantText)) {
            ++$state->emptyResponseRetries;

            if ($state->emptyResponseRetries > self::MAX_EMPTY_RESPONSE_RETRIES) {
                $this->failRun(
                    $run,
                    $state,
                    $sequence,
                    $state->turnNumber,
                    ResearchRunStatus::FAILED,
                    'Model returned an empty response repeatedly',
                    'run_failed',
                    'Repeated empty response',
                    ['status' => ResearchRunStatus::FAILED->value, 'reason' => 'Repeated empty response']
                );

                return NextAction::none();
            }

            $summary = \sprintf('Empty response (retry %d/%d), requesting retry', $state->emptyResponseRetries, self::MAX_EMPTY_RESPONSE_RETRIES);
            $payload = $this->encodeJson([
                'retry' => $state->emptyResponseRetries,
                'maxRetries' => self::MAX_EMPTY_RESPONSE_RETRIES,
            ]);
            $assistantEmptySequence = $this->persistStep($run, $sequence, 'assistant_empty', $state->turnNumber, $summary, $payload);
            $this->eventPublisher->publishActivity($run->getRunUuid(), 'assistant_empty', $summary, [
                'retry' => $state->emptyResponseRetries,
                'sequence' => $assistantEmptySequence,
                'turnNumber' => $state->turnNumber,
            ]);

            $state->appendUserMessage('Your previous response was empty. Respond with either tool calls or a final answer.');
            ++$state->turnNumber;

            return $this->queueCurrentLlmTurn($run, $state, $sequence);
        }

        $state->emptyResponseRetries = 0;

        if ($isFinal) {
            $state->appendAssistantMessage($assistantText);

            $run->setFinalAnswerMarkdown($assistantText);
            $run->setStatus(ResearchRunStatus::COMPLETED);
            $run->setPhase(ResearchRunPhase::COMPLETED);
            $run->setFailureReason(null);
            $run->setCompletedAt(new \DateTimeImmutable());

            $this->persistStep($run, $sequence, 'assistant_final', $state->turnNumber, $assistantText, null);
            $this->publishFinalAnswer($run->getRunUuid(), $assistantText);
            $this->eventPublisher->publishComplete($run->getRunUuid(), ['status' => ResearchRunStatus::COMPLETED->value]);

            $this->persistState($run, $state);

            return NextAction::none();
        }

        if ([] !== $toolCalls) {
            $assistantToolCalls = [];
            foreach ($toolCalls as $position => $toolCall) {
                $callId = $this->toolCallId($state->turnNumber, $position);
                $assistantToolCalls[] = [
                    'id' => $callId,
                    'name' => $toolCall['name'],
                    'arguments' => $toolCall['arguments'],
                ];
            }

            $state->appendAssistantMessage($assistantText, $assistantToolCalls);

            if ($state->answerOnly) {
                ++$state->turnNumber;
                $state->appendUserMessage(self::ANSWER_ONLY_RETRY_MESSAGE);

                return $this->queueCurrentLlmTurn($run, $state, $sequence);
            }

            $toolOperationKeys = [];
            foreach ($toolCalls as $position => $toolCall) {
                $signature = $this->normalizeToolSignature($toolCall['name'], $toolCall['arguments']);
                $existingCount = $state->toolSignatureCounts[$signature] ?? 0;

                if ($existingCount >= self::DUPLICATE_TOOL_SIGNATURE_LIMIT) {
                    $run->setStatus(ResearchRunStatus::LOOP_STOPPED);
                    $run->setPhase(ResearchRunPhase::FAILED);
                    $run->setLoopDetected(true);
                    $run->setFailureReason('Duplicate tool call detected (third identical call): '.$signature);
                    $run->setCompletedAt(new \DateTimeImmutable());

                    $loopSequence = $this->persistStep($run, $sequence, 'loop_detected', $state->turnNumber, $signature, null);
                    $this->eventPublisher->publishActivity($run->getRunUuid(), 'loop_detected', 'Stopping: duplicate call', [
                        'signature' => $signature,
                        'sequence' => $loopSequence,
                        'turnNumber' => $state->turnNumber,
                    ]);
                    $this->eventPublisher->publishComplete($run->getRunUuid(), ['status' => ResearchRunStatus::LOOP_STOPPED->value]);

                    $this->persistState($run, $state);

                    return NextAction::none();
                }

                $callId = $this->toolCallId($state->turnNumber, $position);
                $operation = $this->findOrCreateToolOperation($run, $state->turnNumber, $position, $callId, $toolCall['name'], $toolCall['arguments'], $signature);
                $toolOperationKeys[] = $operation->getIdempotencyKey();
            }

            $run->setPhase(ResearchRunPhase::WAITING_TOOLS);
            $run->setStatus(ResearchRunStatus::RUNNING);
            $this->persistState($run, $state);

            return NextAction::dispatchTools($toolOperationKeys);
        }

        $state->appendAssistantMessage($assistantText);
        ++$state->turnNumber;

        return $this->queueCurrentLlmTurn($run, $state, $sequence);
    }

    private function transitionWaitingTools(ResearchRun $run, OrchestratorState $state): NextAction
    {
        $sequence = $this->stepRepository->nextSequenceForRun($run);
        $operations = $this->operationRepository->findByRunTypeAndTurnOrderedByPosition($run, ResearchOperationType::TOOL_CALL, $state->turnNumber);
        if ([] === $operations) {
            $this->failRun(
                $run,
                $state,
                $sequence,
                $state->turnNumber,
                ResearchRunStatus::FAILED,
                'No tool operations found for waiting_tools phase',
                'run_failed',
                'Missing tool operations',
                ['status' => ResearchRunStatus::FAILED->value, 'reason' => 'Missing tool operations']
            );

            return NextAction::none();
        }

        if ($this->operationRepository->hasNonTerminalByRunTypeAndTurn($run, ResearchOperationType::TOOL_CALL, $state->turnNumber)) {
            return NextAction::none();
        }

        foreach ($operations as $operation) {
            $requestPayload = $this->decodePayloadObject($operation->getRequestPayloadJson(), 'tool request payload');
            $resultPayload = $this->decodePayloadObject($operation->getResultPayloadJson(), 'tool result payload', false);

            $toolName = \is_string($requestPayload['name'] ?? null) && '' !== trim($requestPayload['name'])
                ? $requestPayload['name']
                : (\is_string($requestPayload['toolName'] ?? null) ? $requestPayload['toolName'] : 'unknown_tool');
            $arguments = \is_array($requestPayload['arguments'] ?? null) ? $requestPayload['arguments'] : [];
            $callId = \is_string($requestPayload['callId'] ?? null) && '' !== trim($requestPayload['callId'])
                ? $requestPayload['callId']
                : 'op_'.($operation->getId() ?? 0);
            $signature = \is_string($requestPayload['normalizedSignature'] ?? null) && '' !== trim($requestPayload['normalizedSignature'])
                ? $requestPayload['normalizedSignature']
                : $this->normalizeToolSignature($toolName, $arguments);

            if (ResearchOperationStatus::SUCCEEDED === $operation->getStatus()) {
                $content = \is_string($resultPayload['result'] ?? null) ? $resultPayload['result'] : '';
                $summary = \sprintf('Executed %s', $toolName);
                $payload = $this->encodeJson([
                    'arguments' => $arguments,
                    'result_preview' => \strlen($content) > 200 ? substr($content, 0, 200).'...' : $content,
                    'result' => $content,
                ]);

                $toolSucceededSequence = $this->persistStep(
                    $run,
                    $sequence,
                    'tool_succeeded',
                    $state->turnNumber,
                    $summary,
                    $payload,
                    $toolName,
                    $this->encodeJson($arguments),
                    $signature
                );

                $this->eventPublisher->publishActivity($run->getRunUuid(), 'tool_succeeded', $summary, [
                    'tool' => $toolName,
                    'arguments' => $arguments,
                    'result' => $content,
                    'sequence' => $toolSucceededSequence,
                    'turnNumber' => $state->turnNumber,
                ]);

                $state->toolSignatureCounts[$signature] = ($state->toolSignatureCounts[$signature] ?? 0) + 1;
                $state->consecutiveToolFailures = 0;

                $contentForModel = \strlen($content) > self::MAX_TOOL_RESULT_CHARS
                    ? substr($content, 0, self::MAX_TOOL_RESULT_CHARS)."\n\n[Content was truncated. Total length: ".\strlen($content).' chars. Use websearch_find with the same URL to extract specific parts.]'
                    : $content;

                $state->appendToolMessage($callId, $toolName, $arguments, $contentForModel);

                continue;
            }

            $errorMessage = $operation->getErrorMessage();
            if (null === $errorMessage || '' === trim($errorMessage)) {
                $errorMessage = \is_string($resultPayload['errorMessage'] ?? null) ? $resultPayload['errorMessage'] : 'Unknown tool error';
            }

            $payload = $this->encodeJson([
                'arguments' => $arguments,
                'error' => $errorMessage,
            ]);

            $toolFailedSequence = $this->persistStep(
                $run,
                $sequence,
                'tool_failed',
                $state->turnNumber,
                \sprintf('%s failed: %s', $toolName, $errorMessage),
                $payload,
                $toolName,
                $this->encodeJson($arguments),
                $signature
            );
            $this->eventPublisher->publishActivity($run->getRunUuid(), 'tool_failed', \sprintf('Tool error: %s', $errorMessage), [
                'tool' => $toolName,
                'sequence' => $toolFailedSequence,
                'turnNumber' => $state->turnNumber,
            ]);

            $state->appendToolMessage($callId, $toolName, $arguments, \sprintf('Error: %s', $errorMessage));
            ++$state->consecutiveToolFailures;

            if ($state->consecutiveToolFailures >= self::MAX_CONSECUTIVE_TOOL_FAILURES) {
                $this->failRun(
                    $run,
                    $state,
                    $sequence,
                    $state->turnNumber,
                    ResearchRunStatus::FAILED,
                    self::MAX_CONSECUTIVE_TOOL_FAILURES.' tool calls in a row failed. Model or tools may be unavailable.',
                    'run_failed',
                    'Consecutive tool failures',
                    ['status' => ResearchRunStatus::FAILED->value, 'reason' => 'Consecutive tool failures']
                );

                return NextAction::none();
            }
        }

        ++$state->turnNumber;

        return $this->queueCurrentLlmTurn($run, $state, $sequence);
    }

    private function queueCurrentLlmTurn(ResearchRun $run, OrchestratorState $state, int &$sequence): NextAction
    {
        if ($state->turnNumber >= self::MAX_TURNS) {
            $this->failRun(
                $run,
                $state,
                $sequence,
                $state->turnNumber,
                ResearchRunStatus::FAILED,
                'Max turns exceeded',
                'run_failed',
                'Max turns exceeded',
                ['status' => ResearchRunStatus::FAILED->value, 'reason' => 'Max turns exceeded']
            );

            return NextAction::none();
        }

        $remaining = $run->getTokenBudgetHardCap() - $run->getTokenBudgetUsed();
        if ($remaining < self::ANSWER_ONLY_THRESHOLD && !$state->answerOnly) {
            $state->answerOnly = true;
            $run->setAnswerOnlyTriggered(true);

            $answerOnlySequence = $this->persistStep(
                $run,
                $sequence,
                'answer_only_enabled',
                $state->turnNumber,
                'Switching to answer-only mode',
                null
            );
            $this->eventPublisher->publishActivity($run->getRunUuid(), 'answer_only_enabled', 'Switching to answer-only mode', [
                'sequence' => $answerOnlySequence,
                'turnNumber' => $state->turnNumber,
            ]);
        }

        if ($state->answerOnly) {
            $state->appendUserMessage(self::ANSWER_ONLY_MESSAGE);
        }

        $operation = $this->findOrCreateLlmOperation($run, $state->turnNumber, $state, !$state->answerOnly);

        $run->setStatus(ResearchRunStatus::RUNNING);
        $run->setPhase(ResearchRunPhase::WAITING_LLM);
        $this->persistState($run, $state);

        return NextAction::dispatchLlm($operation->getIdempotencyKey());
    }

    private function findOrCreateLlmOperation(ResearchRun $run, int $turnNumber, OrchestratorState $state, bool $allowTools): ResearchOperation
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
    private function findOrCreateToolOperation(
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
        $operation->setRequestPayloadJson($this->encodeJson([
            'callId' => $callId,
            'name' => $toolName,
            'arguments' => $arguments,
            'normalizedSignature' => $signature,
        ]));
        $operation->setResultPayloadJson(null);
        $operation->setErrorMessage(null);
        $operation->setStartedAt(null);
        $operation->setCompletedAt(null);

        $this->entityManager->persist($operation);

        return $operation;
    }

    /**
     * @param array<string, mixed> $requestPayload
     * @param list<array{name: string, arguments: array<string, mixed>}> $toolCalls
     * @param array<string, mixed> $rawMetadata
     */
    private function persistLlmInvocation(
        ResearchRun $run,
        int &$sequence,
        int $turnNumber,
        array $requestPayload,
        string $assistantText,
        array $toolCalls,
        bool $isFinal,
        ?int $promptTokens,
        ?int $completionTokens,
        ?int $totalTokens,
        array $rawMetadata,
    ): void {
        $payloadJson = null;

        try {
            $model = \is_string($requestPayload['model'] ?? null) && '' !== trim($requestPayload['model'])
                ? $requestPayload['model']
                : $this->defaultModel;

            $messages = $this->decodeMessages($requestPayload['messages'] ?? null);
            $options = \is_array($requestPayload['options'] ?? null) ? $requestPayload['options'] : [];
            if ((bool) ($requestPayload['allowTools'] ?? true)) {
                $options['tools'] = $this->toolbox->getTools();
            }

            $turnResult = new ResearchTurnResult(
                assistantText: $assistantText,
                toolCalls: array_map(
                    fn (array $toolCall): ToolCallDecision => new ToolCallDecision(
                        name: $toolCall['name'],
                        arguments: $toolCall['arguments'],
                        normalizedSignature: $this->normalizeToolSignature($toolCall['name'], $toolCall['arguments'])
                    ),
                    $toolCalls
                ),
                isFinal: $isFinal,
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                totalTokens: $totalTokens,
                rawMetadata: $rawMetadata,
            );

            $payload = $this->traceSerializer->buildPayload($model, $messages, $options, $turnResult);
            $payloadJson = $this->encodeJson($payload);
        } catch (\Throwable) {
            $payloadJson = $this->encodeJson([
                'request' => $requestPayload,
                'response' => [
                    'assistantText' => $assistantText,
                    'toolCalls' => $toolCalls,
                    'isFinal' => $isFinal,
                    'promptTokens' => $promptTokens,
                    'completionTokens' => $completionTokens,
                    'totalTokens' => $totalTokens,
                    'rawMetadata' => $rawMetadata,
                ],
            ]);
        }

        $this->persistStep(
            $run,
            $sequence,
            'llm_invocation',
            $turnNumber,
            \sprintf('LLM invocation turn %d', $turnNumber),
            $payloadJson
        );
    }

    private function applyTokenUsage(ResearchRun $run, ?int $promptTokens, ?int $completionTokens, ?int $totalTokens): int
    {
        $currentUsed = max(0, $run->getTokenBudgetUsed());

        if (null !== $totalTokens) {
            $nextUsed = max($currentUsed, $totalTokens);
            $run->setTokenBudgetUsed($nextUsed);

            return $nextUsed;
        }

        $increment = max(0, ($promptTokens ?? 0) + ($completionTokens ?? 0));
        $nextUsed = $currentUsed + $increment;
        $run->setTokenBudgetUsed($nextUsed);

        return $nextUsed;
    }

    private function persistTokenSnapshot(
        ResearchRun $run,
        int &$sequence,
        int $turnNumber,
        ?int $promptTokens,
        ?int $completionTokens,
        ?int $totalTokens,
        int $cumulativeUsed,
    ): void {
        $payload = $this->encodeJson([
            'promptTokens' => $promptTokens,
            'completionTokens' => $completionTokens,
            'totalTokens' => $totalTokens,
            'totalUsed' => $cumulativeUsed,
        ]);

        $step = new ResearchStep();
        $step->setRun($run);
        $step->setSequence($sequence);
        ++$sequence;
        $step->setType('token_snapshot');
        $step->setTurnNumber($turnNumber);
        $step->setSummary(\sprintf('Tokens: %d total used', $cumulativeUsed));
        $step->setPayloadJson($payload);
        $step->setPromptTokens($promptTokens);
        $step->setCompletionTokens($completionTokens);
        $step->setTotalTokens($totalTokens);
        $step->setEstimatedTokens(false);
        $run->addStep($step);

        $this->entityManager->persist($step);
    }

    /**
     * @param array<string, mixed> $completeMeta
     */
    private function failRun(
        ResearchRun $run,
        OrchestratorState $state,
        int &$sequence,
        int $turnNumber,
        ResearchRunStatus $status,
        string $failureReason,
        string $stepType,
        string $stepSummary,
        array $completeMeta,
    ): void {
        $run->setStatus($status);
        $run->setPhase(ResearchRunStatus::ABORTED === $status ? ResearchRunPhase::ABORTED : ResearchRunPhase::FAILED);
        $run->setFailureReason($failureReason);
        $run->setCompletedAt(new \DateTimeImmutable());
        if (ResearchRunStatus::LOOP_STOPPED === $status) {
            $run->setLoopDetected(true);
        }

        $this->persistStep($run, $sequence, $stepType, $turnNumber, $stepSummary, null);
        $this->eventPublisher->publishComplete($run->getRunUuid(), $completeMeta);

        $this->persistState($run, $state);
    }

    private function persistState(ResearchRun $run, OrchestratorState $state): void
    {
        $run->setOrchestratorStateJson($state->toJson());
        $run->setOrchestrationVersion($run->getOrchestrationVersion() + 1);
    }

    private function publishFinalAnswer(string $runId, string $markdown): void
    {
        if ('' === $markdown) {
            $this->eventPublisher->publishAnswer($runId, '', true);

            return;
        }

        foreach ($this->chunkAnswer($markdown) as $chunk) {
            if ('' === $chunk) {
                continue;
            }

            $this->eventPublisher->publishAnswer($runId, $chunk, false);
        }

        $this->eventPublisher->publishAnswer($runId, '', true);
    }

    /**
     * @return list<string>
     */
    private function chunkAnswer(string $markdown): array
    {
        $chars = preg_split('//u', $markdown, -1, \PREG_SPLIT_NO_EMPTY);
        if (!\is_array($chars) || [] === $chars) {
            return [$markdown];
        }

        $chunks = [];
        $totalChars = \count($chars);
        for ($offset = 0; $offset < $totalChars; $offset += self::ANSWER_STREAM_CHUNK_CHARS) {
            $chunk = implode('', \array_slice($chars, $offset, self::ANSWER_STREAM_CHUNK_CHARS));
            if ('' !== $chunk) {
                $chunks[] = $chunk;
            }
        }

        return [] === $chunks ? [$markdown] : $chunks;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function encodeJson(?array $payload): string
    {
        try {
            return json_encode($payload ?? [], \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '{}';
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function normalizeToolSignature(string $toolName, array $arguments): string
    {
        $normalized = [];
        foreach ($arguments as $key => $value) {
            $normalized[$key] = \is_scalar($value) ? (string) $value : $this->encodeJson(['value' => $value]);
        }
        ksort($normalized);

        return $toolName.':'.$this->encodeJson($normalized);
    }

    private function llmIdempotencyKey(ResearchRun $run, int $turnNumber): string
    {
        return \sprintf('%s:llm:%d', $run->getRunUuid(), $turnNumber);
    }

    private function toolIdempotencyKey(ResearchRun $run, int $turnNumber, int $position): string
    {
        return \sprintf('%s:tool:%d:%d', $run->getRunUuid(), $turnNumber, $position);
    }

    private function toolCallId(int $turnNumber, int $position): string
    {
        return \sprintf('call_t%d_p%d', $turnNumber, $position);
    }

    private function buildLlmRequestPayload(OrchestratorState $state, bool $allowTools): string
    {
        return $this->encodeJson([
            'model' => $this->defaultModel,
            'messages' => $state->messageWindow,
            'allowTools' => $allowTools,
            'options' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayloadObject(?string $json, string $context, bool $allowEmpty = true): array
    {
        if (null === $json || '' === trim($json)) {
            if ($allowEmpty) {
                return [];
            }

            throw new \UnexpectedValueException('Missing '.$context.'.');
        }

        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('Invalid '.$context.'.');
        }

        return $decoded;
    }

    /**
     * @param mixed $rawMessages
     */
    private function decodeMessages(mixed $rawMessages): MessageBag
    {
        $windowMessageBag = $this->decodeMessageWindowPayload($rawMessages);
        if ($windowMessageBag instanceof MessageBag) {
            return $windowMessageBag;
        }

        if (\is_string($rawMessages)) {
            $messagesJson = $rawMessages;
        } elseif (\is_array($rawMessages)) {
            $messagesJson = $this->encodeJson($rawMessages);
        } else {
            throw new \UnexpectedValueException('LLM request payload does not contain valid messages.');
        }

        $messages = $this->serializer->deserialize($messagesJson, Message::class.'[]', 'json');
        if (!\is_array($messages)) {
            throw new \UnexpectedValueException('Failed to deserialize LLM messages.');
        }

        foreach ($messages as $message) {
            if (!$message instanceof Message) {
                throw new \UnexpectedValueException('Deserialized payload contains an invalid message.');
            }
        }

        return new MessageBag(...$messages);
    }

    private function decodeMessageWindowPayload(mixed $rawMessages): ?MessageBag
    {
        if (\is_array($rawMessages) && $this->looksLikeMessageWindow($rawMessages)) {
            return $this->decodeMessageWindow($rawMessages);
        }

        if (!\is_string($rawMessages)) {
            return null;
        }

        try {
            $decoded = json_decode($rawMessages, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !$this->looksLikeMessageWindow($decoded)) {
            return null;
        }

        return $this->decodeMessageWindow($decoded);
    }

    /**
     * @param list<array<string, mixed>> $messageWindow
     */
    private function decodeMessageWindow(array $messageWindow): MessageBag
    {
        $state = OrchestratorState::fromJson(json_encode([
            'messageWindow' => $messageWindow,
        ], \JSON_THROW_ON_ERROR));

        return $state->toMessageBag();
    }

    /**
     * @param array<int|string, mixed> $messages
     */
    private function looksLikeMessageWindow(array $messages): bool
    {
        if ([] === $messages) {
            return true;
        }

        $first = reset($messages);

        return \is_array($first) && \is_string($first['role'] ?? null);
    }

    /**
     * @param mixed $rawToolCalls
     *
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    private function normalizeToolCalls(mixed $rawToolCalls): array
    {
        if (!\is_array($rawToolCalls)) {
            return [];
        }

        $normalized = [];
        foreach ($rawToolCalls as $toolCall) {
            if (!\is_array($toolCall)) {
                continue;
            }

            $name = $toolCall['name'] ?? null;
            if (!\is_string($name) || '' === trim($name)) {
                continue;
            }

            $arguments = \is_array($toolCall['arguments'] ?? null) ? $toolCall['arguments'] : [];
            $normalized[] = [
                'name' => $name,
                'arguments' => $arguments,
            ];
        }

        return $normalized;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }

        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && '' !== trim($value) && is_numeric($value)) {
            return (int) $value;
        }

        if (\is_float($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    private function budgetMeta(int $used): array
    {
        return [
            'used' => $used,
            'remaining' => self::HARD_CAP_TOKENS - $used,
            'hardCap' => self::HARD_CAP_TOKENS,
        ];
    }

    private function hasTimedOut(ResearchRun $run): bool
    {
        if ($run->getStatus()->isTerminal()) {
            return false;
        }

        $elapsed = time() - $run->getCreatedAt()->getTimestamp();

        return $elapsed >= self::WALL_CLOCK_TIMEOUT_SECONDS;
    }

    private function persistStep(
        ResearchRun $run,
        int &$sequence,
        string $type,
        int $turnNumber,
        string $summary,
        ?string $payloadJson,
        ?string $toolName = null,
        ?string $toolArgumentsJson = null,
        ?string $toolSignature = null,
    ): int {
        $step = new ResearchStep();
        $step->setRun($run);
        $step->setSequence($sequence);
        $step->setType($type);
        $step->setTurnNumber($turnNumber);
        $step->setSummary($summary);
        $step->setPayloadJson($payloadJson);
        $step->setToolName($toolName);
        $step->setToolArgumentsJson($toolArgumentsJson);
        $step->setToolSignature($toolSignature);
        $run->addStep($step);

        $this->entityManager->persist($step);

        $persistedSequence = $sequence;
        ++$sequence;

        return $persistedSequence;
    }
}
