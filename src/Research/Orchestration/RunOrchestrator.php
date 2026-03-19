<?php

declare(strict_types=1);

namespace App\Research\Orchestration;

use App\Entity\ResearchRun;
use App\Entity\ResearchStep;
use App\Research\Event\EventPublisherInterface;
use App\Research\Guardrail\Exception\BudgetExhaustedException;
use App\Research\Guardrail\Exception\LoopDetectedException;
use App\Research\Guardrail\ResearchBudgetEnforcerInterface;
use App\Repository\ResearchRunRepository;
use App\Research\Orchestration\Dto\ResearchTurnResult;
use App\Research\Orchestration\Dto\ToolCallDecision;
use App\Research\ResearchBriefBuilder;
use App\Research\Serializer\LlmInvocationTraceSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResultConverter;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingContent;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Psr\Log\LoggerInterface;

/**
 * Owns the research turn loop: model turns, budget injection, tool execution,
 * answer-only mode, and duplicate-call detection.
 *
 * @see .cursor/plans/web_research_flow_5c8ddc68.plan.md
 */
final class RunOrchestrator
{
    private const HARD_CAP_TOKENS = 75_000;
    private const ANSWER_ONLY_THRESHOLD = 5_000;
    private const STRATEGIC_NOTICE_START = 20_000;
    private const STRATEGIC_NOTICE_END = 30_000;
    /** @var list<int> */
    private const LATE_BUDGET_NOTICE_THRESHOLDS = [60_000, 68_000];
    private const MAX_TURNS = 75;
    private const MAX_EMPTY_RESPONSE_RETRIES = 5;
    private const WALL_CLOCK_TIMEOUT_SECONDS = 900;
    private const MAX_CONSECUTIVE_TOOL_FAILURES = 3;
    /** Max chars per tool result sent to model (~5K tokens) to avoid context overflow */
    private const MAX_TOOL_RESULT_CHARS = 20_000;

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly string $model,
        private readonly ToolboxInterface $toolbox,
        private readonly ResearchBudgetEnforcerInterface $budgetEnforcer,
        private readonly ResearchBriefBuilder $briefBuilder,
        private readonly EventPublisherInterface $eventPublisher,
        private readonly ResearchRunRepository $runRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly LlmInvocationTraceSerializer $traceSerializer,
        private readonly ToolResultConverter $toolResultConverter = new ToolResultConverter(),
    ) {
    }

    public function execute(string $runId): void
    {
        $run = $this->runRepository->findEntity($runId);
        if (!$run instanceof ResearchRun) {
            return;
        }

        $rawQuery = $run->getQuery();
        $brief = $this->briefBuilder->build($rawQuery);

        $messages = new MessageBag(
            Message::forSystem($brief),
            Message::ofUser($rawQuery)
        );

        $turn = 0;
        $answerOnly = false;
        $tokenBudgetUsed = 0;
        $strategicNoticeSent = false;
        /** @var array<int, bool> */
        $lateNoticeSentByThreshold = [];
        $sequence = 0;
        $startedAt = time();
        $consecutiveToolFailures = 0;
        $emptyResponseRetries = 0;

        $run->setStatus('running');
        $this->persistStep($run, ++$sequence, 'run_started', 0, 'Research run started', null);
        $this->eventPublisher->publishActivity($runId, 'run_started', 'Research run started', ['sequence' => $sequence, 'turnNumber' => 0]);

        $toolMap = $this->toolbox->getTools();
        $options = [
            'tools' => $toolMap,
            'stream' => true,
            'stream_options' => [
                'include_usage' => true,
            ],
        ];

        try {
            while ($turn < self::MAX_TURNS) {
                if (time() - $startedAt >= self::WALL_CLOCK_TIMEOUT_SECONDS) {
                    $run->setStatus('timed_out');
                    $run->setFailureReason('Research timed out after '.self::WALL_CLOCK_TIMEOUT_SECONDS.' seconds');
                    $run->setCompletedAt(new \DateTimeImmutable());
                    $this->persistStep($run, ++$sequence, 'run_failed', $turn, 'Wall-clock timeout', null);
                    $this->eventPublisher->publishComplete($runId, ['status' => 'timed_out']);
                    $this->entityManager->flush();

                    return;
                }

                $remaining = self::HARD_CAP_TOKENS - $tokenBudgetUsed;
                if ($remaining < self::ANSWER_ONLY_THRESHOLD) {
                    $answerOnly = true;
                    $run->setAnswerOnlyTriggered(true);
                    $this->eventPublisher->publishActivity($runId, 'answer_only_enabled', 'Switching to answer-only mode', ['sequence' => $sequence, 'turnNumber' => $turn]);
                }

                $budgetNotice = null;
                if (
                    !$strategicNoticeSent
                    && $tokenBudgetUsed >= self::STRATEGIC_NOTICE_START
                    && $tokenBudgetUsed <= self::STRATEGIC_NOTICE_END
                ) {
                    $budgetNotice = \sprintf(
                        "Budget strategy reminder:\n- total tokens used so far: %d\n- estimated tokens left before hard cap: %d\n- prioritize high-signal sources and avoid broad exploratory searches",
                        $tokenBudgetUsed,
                        $remaining
                    );
                    $strategicNoticeSent = true;
                    $this->persistStep($run, ++$sequence, 'budget_notice', $turn, $budgetNotice, null);
                    $this->eventPublisher->publishBudget($runId, $this->budgetMeta($tokenBudgetUsed, $remaining));
                }

                if (
                    null === $budgetNotice
                    && $tokenBudgetUsed > self::STRATEGIC_NOTICE_END
                ) {
                    foreach (self::LATE_BUDGET_NOTICE_THRESHOLDS as $threshold) {
                        if ($tokenBudgetUsed >= $threshold && !($lateNoticeSentByThreshold[$threshold] ?? false)) {
                            $budgetNotice = \sprintf(
                                "Late budget warning:\n- total tokens used so far: %d\n- estimated tokens left before hard cap: %d\n- only continue if one targeted check materially changes the final answer",
                                $tokenBudgetUsed,
                                $remaining
                            );
                            $lateNoticeSentByThreshold[$threshold] = true;
                            $this->persistStep($run, ++$sequence, 'budget_notice', $turn, $budgetNotice, null);
                            $this->eventPublisher->publishBudget($runId, $this->budgetMeta($tokenBudgetUsed, $remaining));

                            break;
                        }
                    }
                }

                $forcedInstruction = null;
                if ($answerOnly) {
                    $forcedInstruction = 'Do not use any tools. Provide only your best final answer from the evidence gathered so far. Do not make further tool calls.';
                    $messages = $messages->with(Message::ofUser($forcedInstruction));
                } elseif (null !== $budgetNotice) {
                    $messages = $messages->with(Message::ofUser($budgetNotice));
                }

                $this->persistStep($run, ++$sequence, 'turn_started', $turn, \sprintf('Turn %d', $turn), null);

                $this->logger->info('Invoking AI platform', ['turn' => $turn, 'model' => $this->model, 'messagesCount' => \count($messages->getMessages())]);
                $deferred = $this->platform->invoke($this->model, $messages, $options);
                $result = $deferred->getResult();

                $assistantTextBeforeTools = '';
                $answerStreamedDuringTurn = false;
                $reasoningText = null;
                $resultForTokenExtraction = $result;
                if ($result instanceof StreamResult) {
                    $this->logger->info('Consuming stream from AI platform', ['turn' => $turn]);
                    [$result, $assistantTextBeforeTools, $answerStreamedDuringTurn, $reasoningText] = $this->consumeStream($runId, $result);
                    // Token usage is added to StreamResult metadata during iteration; use original for extraction
                }

                if (null !== $reasoningText && '' !== trim($reasoningText)) {
                    $summary = \strlen($reasoningText) > 480
                        ? substr($reasoningText, 0, 480).'...'
                        : $reasoningText;
                    $payload = json_encode(['reasoning' => $reasoningText], \JSON_THROW_ON_ERROR);
                    $this->persistStep($run, ++$sequence, 'assistant_reasoning', $turn, $summary, $payload);
                    $this->eventPublisher->publishActivity($runId, 'assistant_reasoning', $summary, ['reasoning' => $reasoningText, 'sequence' => $sequence, 'turnNumber' => $turn]);
                }

                $turnResult = $this->normalizeResult($result, $turn, $assistantTextBeforeTools, $resultForTokenExtraction);
                $this->logger->info('Received AI platform result', [
                    'turn' => $turn,
                    'isFinal' => $turnResult->isFinal,
                    'toolCallsCount' => \count($turnResult->toolCalls),
                    'assistantTextLength' => \strlen($turnResult->assistantText),
                ]);

                $this->persistLlmInvocation($run, ++$sequence, $turn, $messages, $options, $turnResult);

                $previousTokenBudgetUsed = $tokenBudgetUsed;
                $tokensThisTurn = $this->extractTokens($resultForTokenExtraction);
                if (null !== $turnResult->totalTokens) {
                    $tokenBudgetUsed = $turnResult->totalTokens;
                } else {
                    $tokenBudgetUsed += $tokensThisTurn;
                }

                $tokenDelta = max(0, $tokenBudgetUsed - $previousTokenBudgetUsed);
                if ($tokenDelta > 0) {
                    $this->budgetEnforcer->recordTokenUsage($runId, $tokenDelta);
                }

                $run->setTokenBudgetUsed($tokenBudgetUsed);
                $this->persistTokenSnapshot($run, ++$sequence, $turn, $turnResult, $tokenBudgetUsed);
                $this->eventPublisher->publishBudget($runId, $this->budgetMeta($tokenBudgetUsed, self::HARD_CAP_TOKENS - $tokenBudgetUsed));

                if ('' === trim($turnResult->assistantText) && [] === $turnResult->toolCalls) {
                    ++$emptyResponseRetries;

                    if ($emptyResponseRetries > self::MAX_EMPTY_RESPONSE_RETRIES) {
                        $run->setStatus('failed');
                        $run->setFailureReason('Model returned an empty response repeatedly');
                        $run->setCompletedAt(new \DateTimeImmutable());
                        $this->persistStep($run, ++$sequence, 'run_failed', $turn, 'Repeated empty response', null);
                        $this->eventPublisher->publishComplete($runId, ['status' => 'failed', 'reason' => 'Repeated empty response']);
                        $this->entityManager->flush();

                        return;
                    }

                    $summary = \sprintf('Empty response (retry %d/%d), resending same turn', $emptyResponseRetries, self::MAX_EMPTY_RESPONSE_RETRIES);
                    $emptyPayload = json_encode([
                        'retry' => $emptyResponseRetries,
                        'maxRetries' => self::MAX_EMPTY_RESPONSE_RETRIES,
                        'normalizedResult' => [
                            'assistantText' => $turnResult->assistantText,
                            'toolCalls' => array_map(
                                static fn (ToolCallDecision $decision) => ['name' => $decision->name, 'arguments' => $decision->arguments],
                                $turnResult->toolCalls
                            ),
                            'isFinal' => $turnResult->isFinal,
                            'promptTokens' => $turnResult->promptTokens,
                            'completionTokens' => $turnResult->completionTokens,
                            'totalTokens' => $turnResult->totalTokens,
                        ],
                        'rawMetadata' => $turnResult->rawMetadata,
                    ], \JSON_THROW_ON_ERROR);
                    $this->persistStep($run, ++$sequence, 'assistant_empty', $turn, $summary, $emptyPayload);
                    $this->eventPublisher->publishActivity($runId, 'assistant_empty', $summary, ['retry' => $emptyResponseRetries, 'sequence' => $sequence, 'turnNumber' => $turn]);
                    $this->entityManager->flush();

                    continue;
                }

                $emptyResponseRetries = 0;

                if ($turnResult->isFinal) {
                    $run->setFinalAnswerMarkdown($turnResult->assistantText);
                    $run->setStatus('completed');
                    $run->setCompletedAt(new \DateTimeImmutable());
                    $this->persistStep($run, ++$sequence, 'assistant_final', $turn, $turnResult->assistantText, null);
                    if ($answerStreamedDuringTurn) {
                        $this->eventPublisher->publishAnswer($runId, '', true);
                    } else {
                        $this->eventPublisher->publishAnswer($runId, $turnResult->assistantText, true);
                    }
                    $this->eventPublisher->publishComplete($runId, ['status' => 'completed']);
                    $this->entityManager->flush();

                    return;
                }

                if ([] !== $turnResult->toolCalls) {
                    if ($answerOnly) {
                        $messages = $messages->with(Message::ofAssistant($turnResult->assistantText));
                        $forcedInstruction = 'You requested tools but answer-only mode is active. Provide your best final answer from the evidence gathered. No tool calls allowed.';
                        $messages = $messages->with(Message::ofUser($forcedInstruction));
                        continue;
                    }

                    $toolCallsForMessages = [];
                    foreach ($turnResult->toolCalls as $decision) {
                        $toolCall = new ToolCall(
                            'call_'.$decision->name.'_'.$turn,
                            $decision->name,
                            $decision->arguments
                        );
                        $toolCallsForMessages[] = $toolCall;
                    }

                    $messages = $messages->with(Message::ofAssistant($turnResult->assistantText, $toolCallsForMessages));

                    foreach ($turnResult->toolCalls as $i => $decision) {
                        try {
                            $this->budgetEnforcer->beforeToolCall($runId, $decision->name, $decision->arguments);
                        } catch (LoopDetectedException $e) {
                            $run->setStatus('loop_stopped');
                            $run->setLoopDetected(true);
                            $run->setFailureReason($e->getMessage());
                            $run->setCompletedAt(new \DateTimeImmutable());
                            $this->persistStep($run, ++$sequence, 'loop_detected', $turn, $decision->normalizedSignature, null);
                            $this->eventPublisher->publishActivity($runId, 'loop_detected', 'Stopping: duplicate call', ['signature' => $decision->normalizedSignature, 'sequence' => $sequence, 'turnNumber' => $turn]);
                            $this->eventPublisher->publishComplete($runId, ['status' => 'loop_stopped']);
                            $this->entityManager->flush();

                            return;
                        } catch (BudgetExhaustedException $e) {
                            $run->setAnswerOnlyTriggered(true);
                            $answerOnly = true;
                            $this->eventPublisher->publishActivity($runId, 'answer_only_enabled', 'Switching to answer-only mode: budget exhausted', ['sequence' => $sequence, 'turnNumber' => $turn]);

                            $forcedInstruction = 'Budget exhausted. Do not use any tools. Provide only your best final answer from the evidence gathered so far.';
                            $messages = $messages->with(Message::ofUser($forcedInstruction));
                            $this->persistStep($run, ++$sequence, 'answer_only_enabled', $turn, 'Budget exhausted, requesting final answer', null);

                            break;
                        }

                        $toolCall = $toolCallsForMessages[$i];
                        try {
                            $toolResult = $this->toolbox->execute($toolCall);
                            $content = $this->toolResultConverter->convert($toolResult) ?? '';
                            $this->budgetEnforcer->afterToolCall($runId, $decision->name, $decision->arguments, $content);

                            $consecutiveToolFailures = 0;
                            $summary = \sprintf('Executed %s', $decision->name);
                            $payload = json_encode(['arguments' => $decision->arguments, 'result_preview' => \strlen($content) > 200 ? substr($content, 0, 200).'...' : $content, 'result' => $content], \JSON_THROW_ON_ERROR);
                            $this->persistStep($run, ++$sequence, 'tool_succeeded', $turn, $summary, $payload, $decision->name, json_encode($decision->arguments, \JSON_THROW_ON_ERROR), $decision->normalizedSignature);
                            $this->eventPublisher->publishActivity($runId, 'tool_succeeded', $summary, ['tool' => $decision->name, 'arguments' => $decision->arguments, 'result' => $content, 'sequence' => $sequence, 'turnNumber' => $turn]);

                            $contentForModel = \strlen($content) > self::MAX_TOOL_RESULT_CHARS
                                ? substr($content, 0, self::MAX_TOOL_RESULT_CHARS)."\n\n[Content was truncated. Total length: ".\strlen($content)." chars. Use websearch_find with the same URL to extract specific parts.]"
                                : $content;
                            $messages = $messages->with(Message::ofToolCall($toolCall, $contentForModel));
                        } catch (\Throwable $toolError) {
                            $errorMsg = $toolError->getMessage();
                            $payload = json_encode(['arguments' => $decision->arguments, 'error' => $errorMsg], \JSON_THROW_ON_ERROR);
                            $this->persistStep($run, ++$sequence, 'tool_failed', $turn, \sprintf('%s failed: %s', $decision->name, $errorMsg), $payload, $decision->name, json_encode($decision->arguments, \JSON_THROW_ON_ERROR), $decision->normalizedSignature);
                            $this->eventPublisher->publishActivity($runId, 'tool_failed', \sprintf('Tool error: %s', $errorMsg), ['tool' => $decision->name, 'sequence' => $sequence, 'turnNumber' => $turn]);
                            $messages = $messages->with(Message::ofToolCall($toolCall, \sprintf('Error: %s', $errorMsg)));

                            $consecutiveToolFailures++;
                            if ($consecutiveToolFailures >= self::MAX_CONSECUTIVE_TOOL_FAILURES) {
                                $run->setStatus('failed');
                                $run->setFailureReason($consecutiveToolFailures.' tool calls in a row failed. Model or tools may be unavailable.');
                                $run->setCompletedAt(new \DateTimeImmutable());
                                $this->persistStep($run, ++$sequence, 'run_failed', $turn, 'Consecutive tool failures', null);
                                $this->eventPublisher->publishComplete($runId, ['status' => 'failed', 'reason' => 'Consecutive tool failures']);
                                $this->entityManager->flush();

                                return;
                            }
                        }
                    }
                } else {
                    $messages = $messages->with(Message::ofAssistant($turnResult->assistantText));
                }

                ++$turn;
                $this->entityManager->flush();
            }

            $run->setStatus('failed');
            $run->setFailureReason('Max turns exceeded');
            $run->setCompletedAt(new \DateTimeImmutable());
            $this->eventPublisher->publishComplete($runId, ['status' => 'failed', 'reason' => 'Max turns exceeded']);
            $this->entityManager->flush();
        } catch (BudgetExhaustedException|LoopDetectedException $e) {
            $run->setStatus($e instanceof BudgetExhaustedException ? 'budget_exhausted' : 'loop_stopped');
            $run->setFailureReason($e->getMessage());
            $run->setCompletedAt(new \DateTimeImmutable());
            $this->eventPublisher->publishComplete($runId, ['status' => $run->getStatus()]);
            $this->entityManager->flush();

            throw $e;
        } catch (\Throwable $e) {
            $run->setStatus('failed');
            $run->setFailureReason($e->getMessage());
            $run->setCompletedAt(new \DateTimeImmutable());
            $this->eventPublisher->publishComplete($runId, ['status' => 'failed', 'reason' => $e->getMessage()]);
            $this->entityManager->flush();

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetMeta(int $used, int $remaining): array
    {
        return [
            'used' => $used,
            'remaining' => $remaining,
            'hardCap' => self::HARD_CAP_TOKENS,
        ];
    }

    /**
     * @return array{0: ResultInterface, 1: string, 2: bool, 3: ?string}
     */
    private function consumeStream(string $runId, StreamResult $stream): array
    {
        $text = '';
        $toolCalls = [];
        $chunkBuffer = '';
        $streamedAnswer = false;
        $reasoningBuffer = '';

        foreach ($stream->getContent() as $chunk) {
            if (\is_string($chunk)) {
                $text .= $chunk;
                $chunkBuffer .= $chunk;

                if (\strlen($chunkBuffer) >= 320 || str_contains($chunkBuffer, "\n\n")) {
                    $this->eventPublisher->publishAnswer($runId, $chunkBuffer, false);
                    $chunkBuffer = '';
                    $streamedAnswer = true;
                }
            } elseif ($chunk instanceof ThinkingContent) {
                $reasoningBuffer .= $chunk->thinking;
            } elseif ($chunk instanceof ToolCallResult) {
                $toolCalls = $chunk->getContent();
            }
        }

        $reasoningText = '' !== trim($reasoningBuffer) ? trim($reasoningBuffer) : null;

        if ('' !== $chunkBuffer) {
            $this->eventPublisher->publishAnswer($runId, $chunkBuffer, false);
            $streamedAnswer = true;
        }

        if ([] !== $toolCalls) {
            return [new ToolCallResult(...$toolCalls), $text, $streamedAnswer, $reasoningText];
        }

        return [new TextResult($text), $text, $streamedAnswer, $reasoningText];
    }

    private function normalizeResult(ResultInterface $result, int $turn, string $streamedText = '', ?ResultInterface $metadataSource = null): ResearchTurnResult
    {
        $promptTokens = null;
        $completionTokens = null;
        $totalTokens = null;
        $rawMetadata = [];

        $source = $metadataSource ?? $result;
        foreach ($source->getMetadata()->all() as $key => $value) {
            $rawMetadata[$key] = $value;
        }

        if ($source->getMetadata()->has('token_usage')) {
            $usage = $source->getMetadata()->get('token_usage');
            if ($usage instanceof TokenUsageInterface) {
                $promptTokens = $usage->getPromptTokens();
                $completionTokens = $usage->getCompletionTokens();
                $totalTokens = $usage->getTotalTokens();
            }
        }

        $rawMetadata = $this->normalizeMetadata($rawMetadata);

        if ($result instanceof TextResult) {
            return new ResearchTurnResult(
                $result->getContent(),
                [],
                true,
                $promptTokens,
                $completionTokens,
                $totalTokens,
                $rawMetadata
            );
        }

        if ($result instanceof ToolCallResult) {
            $calls = $result->getContent();
            $decisions = [];
            foreach ($calls as $call) {
                $args = $call->getArguments();
                $sig = $this->normalizeSignature($call->getName(), $args);
                $decisions[] = new ToolCallDecision($call->getName(), $args, $sig);
            }

            return new ResearchTurnResult(
                $streamedText,
                $decisions,
                false,
                $promptTokens,
                $completionTokens,
                $totalTokens,
                $rawMetadata
            );
        }

        return new ResearchTurnResult('', [], true, $promptTokens, $completionTokens, $totalTokens, $rawMetadata);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function normalizeSignature(string $toolName, array $arguments): string
    {
        $normalized = [];
        foreach ($arguments as $key => $value) {
            $normalized[$key] = \is_scalar($value) ? (string) $value : json_encode($value, \JSON_THROW_ON_ERROR);
        }
        ksort($normalized);

        return $toolName.':'.json_encode($normalized, \JSON_THROW_ON_ERROR);
    }

    private function extractTokens(ResultInterface $result): int
    {
        if (!$result->getMetadata()->has('token_usage')) {
            return 0;
        }

        $usage = $result->getMetadata()->get('token_usage');
        if (!$usage instanceof TokenUsageInterface) {
            return 0;
        }

        $total = $usage->getTotalTokens();
        if (null !== $total) {
            return $total;
        }

        $prompt = $usage->getPromptTokens();
        $completion = $usage->getCompletionTokens();
        if (null !== $prompt && null !== $completion) {
            return $prompt + $completion;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function persistLlmInvocation(
        ResearchRun $run,
        int $sequence,
        int $turnNumber,
        MessageBag $messages,
        array $options,
        ResearchTurnResult $turnResult,
    ): void {
        $payload = $this->traceSerializer->buildPayload($this->model, $messages, $options, $turnResult);
        $payloadJson = json_encode($payload, \JSON_THROW_ON_ERROR);
        $summary = \sprintf('LLM invocation turn %d', $turnNumber);

        $step = new ResearchStep();
        $step->setRun($run);
        $step->setSequence($sequence);
        $step->setType('llm_invocation');
        $step->setTurnNumber($turnNumber);
        $step->setSummary($summary);
        $step->setPayloadJson($payloadJson);
        $run->addStep($step);
        $this->entityManager->persist($step);
    }

    private function persistTokenSnapshot(
        ResearchRun $run,
        int $sequence,
        int $turnNumber,
        ResearchTurnResult $turnResult,
        int $cumulativeUsed,
    ): void {
        if (null === $turnResult->totalTokens && null === $turnResult->promptTokens && null === $turnResult->completionTokens) {
            return;
        }

        $summary = \sprintf('Tokens: %d total used', $cumulativeUsed);
        $payload = json_encode([
            'promptTokens' => $turnResult->promptTokens,
            'completionTokens' => $turnResult->completionTokens,
            'totalTokens' => $turnResult->totalTokens,
            'totalUsed' => $cumulativeUsed,
        ], \JSON_THROW_ON_ERROR);

        $step = new ResearchStep();
        $step->setRun($run);
        $step->setSequence($sequence);
        $step->setType('token_snapshot');
        $step->setTurnNumber($turnNumber);
        $step->setSummary($summary);
        $step->setPayloadJson($payload);
        $step->setPromptTokens($turnResult->promptTokens);
        $step->setCompletionTokens($turnResult->completionTokens);
        $step->setTotalTokens($turnResult->totalTokens);
        $step->setEstimatedTokens(false);
        $run->addStep($step);
        $this->entityManager->persist($step);
    }

    private function persistStep(
        ResearchRun $run,
        int $sequence,
        string $type,
        int $turnNumber,
        string $summary,
        ?string $payloadJson,
        ?string $toolName = null,
        ?string $toolArgumentsJson = null,
        ?string $toolSignature = null,
    ): void {
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
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function normalizeMetadata(array $metadata): array
    {
        $normalized = [];
        foreach ($metadata as $key => $value) {
            $normalized[$key] = $this->normalizeForJson($value, 0);
        }

        return $normalized;
    }

    private function normalizeForJson(mixed $value, int $depth): mixed
    {
        if ($depth >= 5) {
            return '[max depth reached]';
        }

        if (null === $value || \is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }

        if ($value instanceof TokenUsageInterface) {
            return [
                'promptTokens' => $value->getPromptTokens(),
                'completionTokens' => $value->getCompletionTokens(),
                'totalTokens' => $value->getTotalTokens(),
            ];
        }

        if (\is_array($value)) {
            $normalized = [];
            foreach ($value as $k => $v) {
                $normalized[(string) $k] = $this->normalizeForJson($v, $depth + 1);
            }

            return $normalized;
        }

        if ($value instanceof \JsonSerializable) {
            return $this->normalizeForJson($value->jsonSerialize(), $depth + 1);
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if ($value instanceof \Traversable) {
            $normalized = [];
            foreach ($value as $k => $v) {
                $normalized[(string) $k] = $this->normalizeForJson($v, $depth + 1);
            }

            return $normalized;
        }

        return [
            '_class' => $value::class,
            'properties' => $this->normalizeForJson(get_object_vars($value), $depth + 1),
        ];
    }
}
