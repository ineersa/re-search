<?php

declare(strict_types=1);

namespace App\Research\Orchestration;

use App\Entity\Enum\ResearchOperationStatus;
use App\Entity\Enum\ResearchOperationType;
use App\Entity\Enum\ResearchRunPhase;
use App\Entity\Enum\ResearchRunStatus;
use App\Entity\ResearchOperation;
use App\Entity\ResearchRun;
use App\Repository\ResearchOperationRepository;
use App\Repository\ResearchStepRepository;
use App\Research\Message\Llm\Dto\LlmOperationRequest;
use App\Research\Message\Llm\Dto\LlmOperationResultPayload;
use App\Research\Message\Tool\Dto\ToolOperationErrorPayload;
use App\Research\Message\Tool\Dto\ToolOperationRequest;
use App\Research\Message\Tool\Dto\ToolOperationResultPayload;
use App\Research\Event\EventPublisherInterface;
use App\Research\Orchestration\Dto\NextAction;
use App\Research\Orchestration\Dto\OrchestratorState;
use App\Research\Throttle\ResearchThrottle;
use Symfony\AI\Platform\PlatformInterface;

final readonly class OrchestratorTurnProcessor
{
    private const ANSWER_ONLY_THRESHOLD = 5_000;
    private const MAX_TURNS = 75;
    private const MAX_EMPTY_RESPONSE_RETRIES = 5;
    private const MAX_CONSECUTIVE_TOOL_FAILURES = 3;
    private const MAX_TOOL_RESULT_CHARS = 20_000;
    private const DUPLICATE_TOOL_SIGNATURE_LIMIT = 2;
    private const ANSWER_ONLY_MESSAGE = 'Do not use any tools. Provide only your best final answer from the evidence gathered so far. Do not make further tool calls.';
    private const ANSWER_ONLY_RETRY_MESSAGE = 'You requested tools but answer-only mode is active. Provide your best final answer from the evidence gathered. No tool calls allowed.';

    public function __construct(
        private ResearchOperationRepository $operationRepository,
        private ResearchStepRepository $stepRepository,
        private OrchestratorOperationFactory $operationFactory,
        private OrchestratorStepRecorder $stepRecorder,
        private OrchestratorOperationPayloadMapper $payloadMapper,
        private OrchestratorLlmInvocationRecorder $llmInvocationRecorder,
        private OrchestratorRunStateManager $runStateManager,
        private EventPublisherInterface $eventPublisher,
        private ResearchThrottle $researchThrottle,
        private PlatformInterface $platform,
    ) {
    }

    public function transitionWaitingLlm(ResearchRun $run, OrchestratorState $state): NextAction
    {
        $sequence = $this->stepRepository->nextSequenceForRun($run);
        $llmKey = $this->operationFactory->llmIdempotencyKey($run, $state->turnNumber);
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

        $request = $this->decodeLlmRequest($operation->getRequestPayloadJson());
        $result = $this->decodeLlmResult($operation->getResultPayloadJson());

        $assistantText = $result->assistantText;
        $isFinal = $result->isFinal;
        $toolCalls = $this->payloadMapper->normalizeLlmToolCalls($result);
        $promptTokens = $this->payloadMapper->toNullableInt($result->promptTokens);
        $completionTokens = $this->payloadMapper->toNullableInt($result->completionTokens);
        $totalTokens = $this->payloadMapper->toNullableInt($result->totalTokens);
        $rawMetadata = $result->rawMetadata;
        $reasoningText = null;
        if (\is_string($result->reasoningText) && '' !== trim($result->reasoningText)) {
            $reasoningText = trim($result->reasoningText);
        }
        $preserveReasoningHistory = $this->shouldPreserveReasoningHistory($request);
        $assistantReasoningForHistory = $preserveReasoningHistory ? $reasoningText : null;

        $this->llmInvocationRecorder->record(
            $run,
            $sequence,
            $state->turnNumber,
            $request,
            $assistantText,
            $toolCalls,
            $isFinal,
            $promptTokens,
            $completionTokens,
            $totalTokens,
            $rawMetadata
        );

        $newBudgetUsed = $this->runStateManager->applyTokenUsage($run, $promptTokens, $completionTokens, $totalTokens);
        if (null !== $promptTokens || null !== $completionTokens || null !== $totalTokens) {
            $this->stepRecorder->persistTokenSnapshot($run, $sequence, $state->turnNumber, $promptTokens, $completionTokens, $totalTokens, $newBudgetUsed);
        }
        $this->eventPublisher->publishBudget($run->getRunUuid(), $this->runStateManager->budgetMeta($run, $newBudgetUsed));

        if (null !== $reasoningText) {
            $summary = \strlen($reasoningText) > 480 ? substr($reasoningText, 0, 480).'...' : $reasoningText;
            $payload = $this->encodeJson(['reasoning' => $reasoningText]);
            $reasoningSequence = $this->stepRecorder->persistStep($run, $sequence, 'assistant_reasoning', $state->turnNumber, $summary, $payload);
            $this->eventPublisher->publishActivity($run->getRunUuid(), 'assistant_reasoning', $summary, [
                'reasoning' => $reasoningText,
                'sequence' => $reasoningSequence,
                'turnNumber' => $state->turnNumber,
            ]);
        }

        if (!$isFinal && [] === $toolCalls && '' === trim($assistantText)) {
            if (null !== $assistantReasoningForHistory) {
                $state->appendAssistantMessage('', [], $assistantReasoningForHistory);
            }

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
            $assistantEmptySequence = $this->stepRecorder->persistStep($run, $sequence, 'assistant_empty', $state->turnNumber, $summary, $payload);
            $this->eventPublisher->publishActivity($run->getRunUuid(), 'assistant_empty', $summary, [
                'retry' => $state->emptyResponseRetries,
                'sequence' => $assistantEmptySequence,
                'turnNumber' => $state->turnNumber,
            ]);

            $state->appendUserMessage('Your previous response was empty. Respond with either tool calls or a final answer.');
            ++$state->turnNumber;

            return $this->queueCurrentLlmTurn($run, $state, $sequence);
        }

        if ($isFinal) {
            $state->emptyResponseRetries = 0;
            $state->appendAssistantMessage($assistantText, [], $assistantReasoningForHistory);

            $run->setFinalAnswerMarkdown($assistantText);
            $run->setStatus(ResearchRunStatus::COMPLETED);
            $run->setPhase(ResearchRunPhase::COMPLETED);
            $run->setFailureReason(null);
            $run->setCompletedAt(new \DateTimeImmutable());
            $this->publishPhase($run, 'Research complete');

            $this->researchThrottle->consumeByClientKey($run->getClientKey());

            $this->stepRecorder->persistStep($run, $sequence, 'assistant_final', $state->turnNumber, $assistantText, null);
            $this->runStateManager->publishFinalAnswer($run->getRunUuid(), $assistantText);
            $this->eventPublisher->publishComplete($run->getRunUuid(), ['status' => ResearchRunStatus::COMPLETED->value]);

            $this->runStateManager->persistState($run, $state);

            return NextAction::none();
        }

        $state->emptyResponseRetries = 0;

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

            $state->appendAssistantMessage($assistantText, $assistantToolCalls, $assistantReasoningForHistory);

            if ($state->answerOnly) {
                ++$state->turnNumber;
                $state->appendUserMessage(self::ANSWER_ONLY_RETRY_MESSAGE);

                return $this->queueCurrentLlmTurn($run, $state, $sequence);
            }

            $toolOperationKeys = [];
            foreach ($toolCalls as $position => $toolCall) {
                $signature = $this->payloadMapper->normalizeToolSignature($toolCall['name'], $toolCall['arguments']);
                $existingCount = $state->toolSignatureCounts[$signature] ?? 0;

                if ($existingCount >= self::DUPLICATE_TOOL_SIGNATURE_LIMIT) {
                    $run->setStatus(ResearchRunStatus::LOOP_STOPPED);
                    $run->setPhase(ResearchRunPhase::FAILED);
                    $run->setLoopDetected(true);
                    $run->setFailureReason('Duplicate tool call detected (third identical call): '.$signature);
                    $run->setCompletedAt(new \DateTimeImmutable());
                    $this->publishPhase($run, 'Stopped: duplicate tool call detected');

                    $loopSequence = $this->stepRecorder->persistStep($run, $sequence, 'loop_detected', $state->turnNumber, $signature, null);
                    $this->eventPublisher->publishActivity($run->getRunUuid(), 'loop_detected', 'Stopping: duplicate call', [
                        'signature' => $signature,
                        'sequence' => $loopSequence,
                        'turnNumber' => $state->turnNumber,
                    ]);
                    $this->eventPublisher->publishComplete($run->getRunUuid(), ['status' => ResearchRunStatus::LOOP_STOPPED->value]);

                    $this->runStateManager->persistState($run, $state);

                    return NextAction::none();
                }

                $callId = $this->toolCallId($state->turnNumber, $position);
                $operation = $this->operationFactory->getOrCreateToolOperation($run, $state->turnNumber, $position, $callId, $toolCall['name'], $toolCall['arguments'], $signature);
                $toolOperationKeys[] = $operation->getIdempotencyKey();
            }

            $run->setPhase(ResearchRunPhase::WAITING_TOOLS);
            $run->setStatus(ResearchRunStatus::RUNNING);
            $this->publishPhase($run, 'Waiting for tool call results', ['turnNumber' => $state->turnNumber]);
            $this->runStateManager->persistState($run, $state);

            return NextAction::dispatchTools($toolOperationKeys);
        }

        $state->appendAssistantMessage($assistantText, [], $assistantReasoningForHistory);
        ++$state->turnNumber;

        return $this->queueCurrentLlmTurn($run, $state, $sequence);
    }

    public function transitionWaitingTools(ResearchRun $run, OrchestratorState $state): NextAction
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
            $request = $this->decodeToolRequest($operation->getRequestPayloadJson());

            $toolName = null !== $request->name && '' !== trim($request->name)
                ? $request->name
                : ((null !== $request->toolName && '' !== trim($request->toolName)) ? $request->toolName : 'unknown_tool');
            $arguments = $request->arguments;
            $callId = null !== $request->callId && '' !== trim($request->callId)
                ? $request->callId
                : 'op_'.($operation->getId() ?? 0);
            $signature = null !== $request->normalizedSignature && '' !== trim($request->normalizedSignature)
                ? $request->normalizedSignature
                : $this->payloadMapper->normalizeToolSignature($toolName, $arguments);

            if (ResearchOperationStatus::SUCCEEDED === $operation->getStatus()) {
                $result = $this->decodeToolResult($operation->getResultPayloadJson());
                $content = $result->result;
                $summary = \sprintf('Executed %s', $toolName);
                $payload = $this->encodeJson([
                    'arguments' => $arguments,
                    'result_preview' => \strlen($content) > 200 ? substr($content, 0, 200).'...' : $content,
                    'result' => $content,
                ]);

                $toolSucceededSequence = $this->stepRecorder->persistStep(
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
                $toolError = $this->decodeToolError($operation->getResultPayloadJson());
                $errorMessage = $toolError?->errorMessage;
            }
            if (null === $errorMessage || '' === trim($errorMessage)) {
                $errorMessage = 'Unknown tool error';
            }

            $payload = $this->encodeJson([
                'arguments' => $arguments,
                'error' => $errorMessage,
            ]);

            $toolFailedSequence = $this->stepRecorder->persistStep(
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

    public function queueCurrentLlmTurn(ResearchRun $run, OrchestratorState $state, int &$sequence): NextAction
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

            $answerOnlySequence = $this->stepRecorder->persistStep(
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

        $operation = $this->operationFactory->getOrCreateLlmOperation($run, $state->turnNumber, $state, !$state->answerOnly);

        $run->setStatus(ResearchRunStatus::RUNNING);
        $run->setPhase(ResearchRunPhase::WAITING_LLM);
        $this->publishPhase($run, 'Waiting for LLM response', ['turnNumber' => $state->turnNumber]);
        $this->runStateManager->persistState($run, $state);

        return NextAction::dispatchLlm($operation->getIdempotencyKey());
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
        $this->runStateManager->failRun(
            $run,
            $state,
            $sequence,
            $turnNumber,
            $status,
            $failureReason,
            $stepType,
            $stepSummary,
            $completeMeta,
        );
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function encodeJson(?array $payload): string
    {
        return $this->payloadMapper->encodeJson($payload);
    }

    private function toolCallId(int $turnNumber, int $position): string
    {
        return \sprintf('call_t%d_p%d', $turnNumber, $position);
    }

    private function decodeLlmRequest(?string $payloadJson): LlmOperationRequest
    {
        if (null === $payloadJson || '' === trim($payloadJson)) {
            throw new \UnexpectedValueException('Missing llm request payload.');
        }

        return $this->payloadMapper->decodeLlmRequest($payloadJson);
    }

    private function decodeLlmResult(?string $payloadJson): LlmOperationResultPayload
    {
        if (null === $payloadJson || '' === trim($payloadJson)) {
            throw new \UnexpectedValueException('Missing llm result payload.');
        }

        return $this->payloadMapper->decodeLlmResult($payloadJson);
    }

    private function decodeToolRequest(?string $payloadJson): ToolOperationRequest
    {
        if (null === $payloadJson || '' === trim($payloadJson)) {
            throw new \UnexpectedValueException('Missing tool request payload.');
        }

        return $this->payloadMapper->decodeToolRequest($payloadJson);
    }

    private function decodeToolResult(?string $payloadJson): ToolOperationResultPayload
    {
        if (null === $payloadJson || '' === trim($payloadJson)) {
            throw new \UnexpectedValueException('Missing tool result payload.');
        }

        return $this->payloadMapper->decodeToolResult($payloadJson);
    }

    private function decodeToolError(?string $payloadJson): ?ToolOperationErrorPayload
    {
        if (null === $payloadJson || '' === trim($payloadJson)) {
            return null;
        }

        try {
            return $this->payloadMapper->decodeToolError($payloadJson);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function publishPhase(ResearchRun $run, string $message, array $meta = []): void
    {
        $this->eventPublisher->publishPhase(
            $run->getRunUuid(),
            $run->getPhaseValue(),
            $run->getStatusValue(),
            $message,
            $meta,
        );
    }

    private function shouldPreserveReasoningHistory(LlmOperationRequest $request): bool
    {
        if (array_key_exists('preserve_reasoning_history', $request->options)) {
            return $this->toBool($request->options['preserve_reasoning_history']);
        }

        $modelName = null !== $request->model ? trim($request->model) : '';
        if ('' === $modelName) {
            return false;
        }

        try {
            $modelOptions = $this->platform->getModelCatalog()->getModel($modelName)->getOptions();
        } catch (\Throwable) {
            return false;
        }

        return $this->toBool($modelOptions['preserve_reasoning_history'] ?? false);
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return 1 === $value;
        }

        if (\is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

}
