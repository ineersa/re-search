<?php

declare(strict_types=1);

namespace App\Research\Orchestration;

use App\Entity\ResearchRun;
use App\Entity\ResearchStep;
use App\Research\Event\EventPublisherInterface;
use App\Research\Guardrail\Exception\BudgetExhaustedException;
use App\Research\Guardrail\Exception\LoopDetectedException;
use App\Research\Guardrail\ResearchBudgetEnforcerInterface;
use App\Research\Orchestration\Dto\ResearchTurnResult;
use App\Research\Orchestration\Dto\ToolCallDecision;
use App\Research\Persistence\ResearchRunRepositoryInterface;
use App\Research\ResearchBriefBuilderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Toolbox\ToolResultConverter;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * Owns the research turn loop: model turns, budget injection, tool execution,
 * answer-only mode, and duplicate-call detection.
 *
 * @see .cursor/plans/web_research_flow_5c8ddc68.plan.md
 */
final class RunOrchestrator implements RunOrchestratorInterface
{
    private const HARD_CAP_TOKENS = 75_000;
    private const BUDGET_NOTICE_THRESHOLD = 5_000;
    private const ANSWER_ONLY_THRESHOLD = 2_000;
    private const MAX_TURNS = 75;

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly string $model,
        private readonly ToolboxInterface $toolbox,
        private readonly ResearchBudgetEnforcerInterface $budgetEnforcer,
        private readonly ResearchBriefBuilderInterface $briefBuilder,
        private readonly EventPublisherInterface $eventPublisher,
        private readonly ResearchRunRepositoryInterface $runRepository,
        private readonly EntityManagerInterface $entityManager,
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
        $lastBudgetNoticeThreshold = 0;
        $sequence = 0;

        $run->setStatus('running');
        $this->persistStep($run, ++$sequence, 'run_started', 0, 'Research run started', null);
        $this->eventPublisher->publishActivity($runId, 'run_started', 'Research run started', []);

        $toolMap = $this->toolbox->getTools();
        $options = ['tools' => $toolMap];

        try {
            while ($turn < self::MAX_TURNS) {
                $remaining = self::HARD_CAP_TOKENS - $tokenBudgetUsed;
                if ($remaining < self::ANSWER_ONLY_THRESHOLD) {
                    $answerOnly = true;
                    $run->setAnswerOnlyTriggered(true);
                    $this->eventPublisher->publishActivity($runId, 'answer_only_enabled', 'Switching to answer-only mode', []);
                }

                $budgetNotice = null;
                if ($tokenBudgetUsed >= $lastBudgetNoticeThreshold + self::BUDGET_NOTICE_THRESHOLD) {
                    $lastBudgetNoticeThreshold = (int) (floor($tokenBudgetUsed / self::BUDGET_NOTICE_THRESHOLD) * self::BUDGET_NOTICE_THRESHOLD);
                    $budgetNotice = \sprintf(
                        "Budget update:\n- total tokens used so far: %d\n- estimated tokens left before hard cap: %d\n- continue only if the next search materially improves the answer",
                        $tokenBudgetUsed,
                        $remaining
                    );
                    $this->persistStep($run, ++$sequence, 'budget_notice', $turn, $budgetNotice, null);
                    $this->eventPublisher->publishBudget($runId, $this->budgetMeta($tokenBudgetUsed, $remaining));
                }

                $forcedInstruction = null;
                if ($answerOnly) {
                    $forcedInstruction = 'Do not use any tools. Provide only your best final answer from the evidence gathered so far. Do not make further tool calls.';
                    $messages = $messages->with(Message::forSystem($forcedInstruction));
                } elseif (null !== $budgetNotice) {
                    $messages = $messages->with(Message::forSystem($budgetNotice));
                }

                $this->persistStep($run, ++$sequence, 'turn_started', $turn, \sprintf('Turn %d', $turn), null);

                $deferred = $this->platform->invoke($this->model, $messages, $options);
                $result = $deferred->getResult();

                $assistantTextBeforeTools = '';
                if ($result instanceof StreamResult) {
                    [$result, $assistantTextBeforeTools] = $this->consumeStream($result);
                }

                $turnResult = $this->normalizeResult($result, $turn, $assistantTextBeforeTools);
                $tokenBudgetUsed += $this->extractTokens($result);
                $this->budgetEnforcer->recordTokenUsage($runId, $this->extractTokens($result));

                $run->setTokenBudgetUsed($tokenBudgetUsed);
                $this->eventPublisher->publishBudget($runId, $this->budgetMeta($tokenBudgetUsed, self::HARD_CAP_TOKENS - $tokenBudgetUsed));

                if ($turnResult->isFinal) {
                    $run->setFinalAnswerMarkdown($turnResult->assistantText);
                    $run->setStatus('completed');
                    $run->setCompletedAt(new \DateTimeImmutable());
                    $this->persistStep($run, ++$sequence, 'assistant_final', $turn, $turnResult->assistantText, null);
                    $this->eventPublisher->publishAnswer($runId, $turnResult->assistantText, true);
                    $this->eventPublisher->publishComplete($runId, ['status' => 'completed']);
                    $this->entityManager->flush();

                    return;
                }

                if ([] !== $turnResult->toolCalls) {
                    if ($answerOnly) {
                        $messages = $messages->with(Message::ofAssistant($turnResult->assistantText));
                        $forcedInstruction = 'You requested tools but answer-only mode is active. Provide your best final answer from the evidence gathered. No tool calls allowed.';
                        $messages = $messages->with(Message::forSystem($forcedInstruction));
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
                            $this->eventPublisher->publishActivity($runId, 'loop_detected', 'Stopping: duplicate call', ['signature' => $decision->normalizedSignature]);
                            $this->eventPublisher->publishComplete($runId, ['status' => 'loop_stopped']);
                            $this->entityManager->flush();

                            return;
                        } catch (BudgetExhaustedException $e) {
                            $run->setAnswerOnlyTriggered(true);
                            $answerOnly = true;
                            $this->eventPublisher->publishActivity($runId, 'answer_only_enabled', 'Switching to answer-only mode: budget exhausted', []);

                            $forcedInstruction = 'Budget exhausted. Do not use any tools. Provide only your best final answer from the evidence gathered so far.';
                            $messages = $messages->with(Message::forSystem($forcedInstruction));
                            $this->persistStep($run, ++$sequence, 'answer_only_enabled', $turn, 'Budget exhausted, requesting final answer', null);

                            break;
                        }

                        $toolCall = $toolCallsForMessages[$i];
                        $toolResult = $this->toolbox->execute($toolCall);
                        $content = $this->toolResultConverter->convert($toolResult) ?? '';
                        $this->budgetEnforcer->afterToolCall($runId, $decision->name, $decision->arguments, $content);

                        $summary = \sprintf('Executed %s', $decision->name);
                        $payload = json_encode(['arguments' => $decision->arguments, 'result_preview' => \strlen($content) > 200 ? substr($content, 0, 200).'...' : $content], \JSON_THROW_ON_ERROR);
                        $this->persistStep($run, ++$sequence, 'tool_succeeded', $turn, $summary, $payload, $decision->name, json_encode($decision->arguments, \JSON_THROW_ON_ERROR), $decision->normalizedSignature);
                        $this->eventPublisher->publishActivity($runId, 'tool_succeeded', $summary, ['tool' => $decision->name]);

                        $messages = $messages->with(Message::ofToolCall($toolCall, $content));
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
     * @return array{0: ResultInterface, 1: string}
     */
    private function consumeStream(StreamResult $stream): array
    {
        $text = '';
        $toolCalls = [];
        foreach ($stream->getContent() as $chunk) {
            if (\is_string($chunk)) {
                $text .= $chunk;
            } elseif ($chunk instanceof ToolCallResult) {
                $toolCalls = $chunk->getContent();
            }
        }

        if ([] !== $toolCalls) {
            return [new ToolCallResult(...$toolCalls), $text];
        }

        return [new TextResult($text), $text];
    }

    private function normalizeResult(ResultInterface $result, int $turn, string $streamedText = ''): ResearchTurnResult
    {
        $promptTokens = null;
        $completionTokens = null;
        $totalTokens = null;
        $rawMetadata = [];

        if ($result->getMetadata()->has('token_usage')) {
            $usage = $result->getMetadata()->get('token_usage');
            if ($usage instanceof TokenUsageInterface) {
                $promptTokens = $usage->getPromptTokens();
                $completionTokens = $usage->getCompletionTokens();
                $totalTokens = $usage->getTotalTokens();
            }
            $rawMetadata['token_usage'] = $usage;
        }

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
        if ($usage instanceof TokenUsageInterface) {
            $total = $usage->getTotalTokens();

            return null !== $total ? $total : 0;
        }

        return 0;
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
}
