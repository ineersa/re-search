<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\ResearchOperationType;
use App\Entity\Enum\ResearchRunStatus;
use App\Entity\ResearchOperation;
use App\Entity\ResearchRun;
use App\Entity\ResearchStep;
use App\Repository\ResearchOperationRepository;
use App\Repository\ResearchRunRepository;
use App\Research\Orchestration\OrchestratorOperationPayloadMapper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:research:fixture:export',
    description: 'Export a persisted research run into a replay fixture JSON file.',
)]
final class ExportResearchTraceFixtureCommand extends Command
{
    private const string DEFAULT_FIXTURE_DIR = 'tests/Fixtures/research_traces';

    public function __construct(
        private readonly ResearchRunRepository $runRepository,
        private readonly ResearchOperationRepository $operationRepository,
        private readonly OrchestratorOperationPayloadMapper $payloadMapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('run-id', InputArgument::REQUIRED, 'Research run UUID')
            ->addArgument('fixture-name', InputArgument::REQUIRED, 'Fixture file name without extension')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Relative output directory from project root', self::DEFAULT_FIXTURE_DIR)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite fixture file if it already exists.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $runId = $input->getArgument('run-id');
        $fixtureName = $input->getArgument('fixture-name');
        $dir = $input->getOption('dir');

        if (!\is_string($runId) || '' === trim($runId)) {
            $io->error('run-id must be a non-empty UUID string.');

            return Command::INVALID;
        }

        if (!\is_string($fixtureName) || !preg_match('/^[A-Za-z0-9_.-]+$/', $fixtureName)) {
            $io->error('fixture-name must match /^[A-Za-z0-9_.-]+$/.');

            return Command::INVALID;
        }

        if (!\is_string($dir) || '' === trim($dir)) {
            $io->error('dir must be a non-empty relative path.');

            return Command::INVALID;
        }

        $run = $this->runRepository->findEntity($runId);
        if (!$run instanceof ResearchRun) {
            $io->error(sprintf('Research run "%s" was not found.', $runId));

            return Command::FAILURE;
        }

        try {
            $outputPath = $this->resolveOutputPath($dir, $fixtureName);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $outputDir = \dirname($outputPath);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            $io->error(sprintf('Unable to create fixture directory "%s".', $outputDir));

            return Command::FAILURE;
        }

        $forceOverwrite = (bool) $input->getOption('force');
        if (is_file($outputPath) && !$forceOverwrite) {
            $io->error(sprintf('Fixture file already exists: %s (use --force to overwrite)', $outputPath));

            return Command::FAILURE;
        }

        $fixture = $this->buildFixture($fixtureName, $run);
        $encodedFixture = json_encode($fixture, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false === $encodedFixture) {
            $io->error('Failed to encode fixture payload as JSON.');

            return Command::FAILURE;
        }

        $bytes = file_put_contents($outputPath, $encodedFixture."\n", \LOCK_EX);
        if (false === $bytes) {
            $io->error(sprintf('Failed to write fixture file: %s', $outputPath));

            return Command::FAILURE;
        }

        $io->success(sprintf('Fixture exported: %s', $outputPath));
        $io->definitionList(
            ['Run UUID' => $run->getRunUuid()],
            ['Status' => $run->getStatusValue()],
            ['Phase' => $run->getPhaseValue()],
            ['Steps' => (string) \count($fixture['steps'])],
            ['Operations' => (string) \count($fixture['operations'])]
        );

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFixture(string $fixtureName, ResearchRun $run): array
    {
        $operations = $this->operationRepository->findBy(
            ['run' => $run],
            ['turnNumber' => 'ASC', 'position' => 'ASC', 'id' => 'ASC']
        );

        $exportedOperations = array_map(
            fn (ResearchOperation $operation): array => $this->exportOperation($operation),
            $operations
        );

        $exportedSteps = [];
        foreach ($run->getSteps() as $step) {
            if (!$step instanceof ResearchStep) {
                continue;
            }

            $exportedSteps[] = $this->exportStep($step);
        }

        return [
            'schemaVersion' => 1,
            'scenario' => $fixtureName,
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'run' => $this->exportRun($run),
            'operations' => $exportedOperations,
            'steps' => $exportedSteps,
            'expected' => $this->buildExpected($run, $exportedSteps),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportRun(ResearchRun $run): array
    {
        $finalAnswer = $run->getFinalAnswerMarkdown();

        return [
            'runUuid' => $run->getRunUuid(),
            'queryPreview' => $this->truncate($run->getQuery(), 180),
            'queryHash' => $run->getQueryHash(),
            'status' => $run->getStatusValue(),
            'phase' => $run->getPhaseValue(),
            'tokenBudgetUsed' => $run->getTokenBudgetUsed(),
            'tokenBudgetHardCap' => $run->getTokenBudgetHardCap(),
            'loopDetected' => $run->isLoopDetected(),
            'answerOnlyTriggered' => $run->isAnswerOnlyTriggered(),
            'failureReason' => $run->getFailureReason(),
            'finalAnswerLength' => null !== $finalAnswer ? mb_strlen($finalAnswer) : 0,
            'finalAnswerSha1' => (null !== $finalAnswer && '' !== $finalAnswer) ? sha1($finalAnswer) : null,
            'createdAt' => $run->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'completedAt' => $run->getCompletedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportOperation(ResearchOperation $operation): array
    {
        $requestPayload = $operation->getRequestPayloadJson();
        $resultPayload = $operation->getResultPayloadJson();

        return [
            'type' => $operation->getType()->value,
            'status' => $operation->getStatus()->value,
            'turnNumber' => $operation->getTurnNumber(),
            'position' => $operation->getPosition(),
            'idempotencyKey' => $operation->getIdempotencyKey(),
            'errorMessage' => $operation->getErrorMessage(),
            'requestPayloadSha1' => sha1($requestPayload),
            'resultPayloadSha1' => null !== $resultPayload ? sha1($resultPayload) : null,
            'requestSummary' => $this->summarizeOperationRequest($operation),
            'resultSummary' => $this->summarizeOperationResult($operation),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportStep(ResearchStep $step): array
    {
        $payloadJson = $step->getPayloadJson();

        return [
            'sequence' => $step->getSequence(),
            'type' => $step->getType(),
            'turnNumber' => $step->getTurnNumber(),
            'toolName' => $step->getToolName(),
            'toolSignature' => $step->getToolSignature(),
            'summary' => $step->getSummary(),
            'promptTokens' => $step->getPromptTokens(),
            'completionTokens' => $step->getCompletionTokens(),
            'totalTokens' => $step->getTotalTokens(),
            'estimatedTokens' => $step->isEstimatedTokens(),
            'payloadSha1' => null !== $payloadJson ? sha1($payloadJson) : null,
            'payloadSummary' => $this->summarizeStepPayload($step->getType(), $payloadJson),
        ];
    }

    /**
     * @param list<array<string, mixed>> $steps
     *
     * @return array<string, mixed>
     */
    private function buildExpected(ResearchRun $run, array $steps): array
    {
        $terminalStepType = null;
        if ([] !== $steps) {
            $terminalStepType = $steps[array_key_last($steps)]['type'] ?? null;
        }

        return [
            'finalStatus' => $run->getStatusValue(),
            'finalPhase' => $run->getPhaseValue(),
            'terminalStepType' => $terminalStepType,
            'mustContainStepTypes' => $this->requiredStepTypesForStatus($run->getStatus()),
            'mustNotContainStepTypes' => $this->forbiddenStepTypesForStatus($run->getStatus()),
        ];
    }

    /**
     * @return list<string>
     */
    private function requiredStepTypesForStatus(ResearchRunStatus $status): array
    {
        return match ($status) {
            ResearchRunStatus::COMPLETED => ['run_started', 'assistant_final'],
            ResearchRunStatus::FAILED,
            ResearchRunStatus::TIMED_OUT,
            ResearchRunStatus::BUDGET_EXHAUSTED => ['run_started', 'run_failed'],
            ResearchRunStatus::LOOP_STOPPED => ['run_started', 'loop_detected'],
            ResearchRunStatus::ABORTED => ['run_aborted'],
            ResearchRunStatus::THROTTLED,
            ResearchRunStatus::QUEUED,
            ResearchRunStatus::RUNNING => [],
        };
    }

    /**
     * @return list<string>
     */
    private function forbiddenStepTypesForStatus(ResearchRunStatus $status): array
    {
        return match ($status) {
            ResearchRunStatus::COMPLETED => ['run_failed', 'loop_detected', 'run_aborted'],
            ResearchRunStatus::FAILED,
            ResearchRunStatus::TIMED_OUT,
            ResearchRunStatus::BUDGET_EXHAUSTED => ['assistant_final', 'run_aborted'],
            ResearchRunStatus::LOOP_STOPPED => ['assistant_final', 'run_failed', 'run_aborted'],
            ResearchRunStatus::ABORTED => ['assistant_final', 'run_failed', 'loop_detected'],
            ResearchRunStatus::THROTTLED => ['run_started', 'assistant_final', 'run_failed', 'loop_detected', 'run_aborted'],
            ResearchRunStatus::QUEUED,
            ResearchRunStatus::RUNNING => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeOperationRequest(ResearchOperation $operation): array
    {
        return match ($operation->getType()) {
            ResearchOperationType::LLM_CALL => $this->summarizeLlmRequest($operation->getRequestPayloadJson()),
            ResearchOperationType::TOOL_CALL => $this->summarizeToolRequest($operation->getRequestPayloadJson()),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function summarizeOperationResult(ResearchOperation $operation): ?array
    {
        $resultPayload = $operation->getResultPayloadJson();
        if (null === $resultPayload || '' === trim($resultPayload)) {
            return null;
        }

        return match ($operation->getType()) {
            ResearchOperationType::LLM_CALL => $this->summarizeLlmResult($resultPayload),
            ResearchOperationType::TOOL_CALL => $this->summarizeToolResult($resultPayload),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeLlmRequest(string $json): array
    {
        try {
            $request = $this->payloadMapper->decodeLlmRequest($json);

            $optionsKeys = array_keys($request->options);
            sort($optionsKeys);

            return [
                'kind' => 'llm_request',
                'model' => $request->model,
                'allowTools' => $request->allowTools,
                'messageCount' => \count($request->messages),
                'lastMessageRole' => $this->extractLastMessageRole($request->messages),
                'optionsKeys' => $optionsKeys,
            ];
        } catch (\Throwable) {
            return ['kind' => 'llm_request_unparseable'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeLlmResult(string $json): array
    {
        try {
            $result = $this->payloadMapper->decodeLlmResult($json);

            return [
                'kind' => 'llm_result',
                'isFinal' => $result->isFinal,
                'toolCallCount' => \count($result->toolCalls),
                'assistantTextLength' => mb_strlen($result->assistantText),
                'promptTokens' => $result->promptTokens,
                'completionTokens' => $result->completionTokens,
                'totalTokens' => $result->totalTokens,
                'resultClass' => $result->resultClass,
                'hasReasoningText' => null !== $result->reasoningText && '' !== trim($result->reasoningText),
            ];
        } catch (\Throwable) {
            return ['kind' => 'llm_result_unparseable'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeToolRequest(string $json): array
    {
        try {
            $request = $this->payloadMapper->decodeToolRequest($json);

            $argumentKeys = array_keys($request->arguments);
            sort($argumentKeys);

            return [
                'kind' => 'tool_request',
                'callId' => $request->callId,
                'name' => $request->name ?? $request->toolName,
                'normalizedSignature' => $request->normalizedSignature,
                'argumentKeys' => $argumentKeys,
            ];
        } catch (\Throwable) {
            return ['kind' => 'tool_request_unparseable'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeToolResult(string $json): array
    {
        try {
            $result = $this->payloadMapper->decodeToolResult($json);
            $argumentKeys = array_keys($result->arguments);
            sort($argumentKeys);

            return [
                'kind' => 'tool_result',
                'callId' => $result->callId,
                'name' => $result->name,
                'argumentKeys' => $argumentKeys,
                'resultLength' => mb_strlen($result->result),
            ];
        } catch (\Throwable) {
            try {
                $error = $this->payloadMapper->decodeToolError($json);

                return [
                    'kind' => 'tool_error',
                    'errorClass' => $error->errorClass,
                    'errorMessage' => $error->errorMessage,
                ];
            } catch (\Throwable) {
                return ['kind' => 'tool_result_unparseable'];
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function summarizeStepPayload(string $stepType, ?string $payloadJson): ?array
    {
        $payload = $this->decodeJson($payloadJson);
        if (null === $payload) {
            return null;
        }

        return match ($stepType) {
            'token_snapshot' => [
                'kind' => 'token_snapshot',
                'promptTokens' => $this->toNullableInt($payload['promptTokens'] ?? null),
                'completionTokens' => $this->toNullableInt($payload['completionTokens'] ?? null),
                'totalTokens' => $this->toNullableInt($payload['totalTokens'] ?? null),
                'totalUsed' => $this->toNullableInt($payload['totalUsed'] ?? null),
            ],
            'llm_invocation' => $this->summarizeLlmInvocationPayload($payload),
            'tool_succeeded' => [
                'kind' => 'tool_succeeded',
                'resultLength' => \is_string($payload['result'] ?? null) ? mb_strlen($payload['result']) : null,
                'argumentKeys' => $this->normalizedKeys($payload['arguments'] ?? []),
            ],
            'tool_failed' => [
                'kind' => 'tool_failed',
                'error' => \is_string($payload['error'] ?? null) ? $payload['error'] : null,
                'argumentKeys' => $this->normalizedKeys($payload['arguments'] ?? []),
            ],
            'assistant_empty' => [
                'kind' => 'assistant_empty',
                'retry' => $this->toNullableInt($payload['retry'] ?? null),
                'maxRetries' => $this->toNullableInt($payload['maxRetries'] ?? null),
            ],
            default => [
                'kind' => 'generic',
                'keys' => $this->normalizedKeys($payload),
                'keyCount' => \count($payload),
            ],
        };
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function summarizeLlmInvocationPayload(array $payload): array
    {
        $request = \is_array($payload['request'] ?? null) ? $payload['request'] : [];
        $response = \is_array($payload['response'] ?? null) ? $payload['response'] : [];

        $messageCount = null;
        $messages = $request['messages'] ?? null;
        if (\is_string($messages)) {
            $decodedMessages = $this->decodeJson($messages);
            if (\is_array($decodedMessages)) {
                $messageCount = \count($decodedMessages);
            }
        } elseif (\is_array($messages)) {
            $messageCount = \count($messages);
        }

        $toolNames = \is_array($request['toolNames'] ?? null) ? $request['toolNames'] : [];
        $toolCalls = \is_array($response['toolCalls'] ?? null) ? $response['toolCalls'] : [];

        return [
            'kind' => 'llm_invocation',
            'requestModel' => \is_string($request['model'] ?? null) ? $request['model'] : null,
            'requestMessageCount' => $messageCount,
            'requestToolCount' => \count($toolNames),
            'responseIsFinal' => (bool) ($response['isFinal'] ?? false),
            'responseToolCallCount' => \count($toolCalls),
            'responseAssistantTextLength' => \is_string($response['assistantText'] ?? null) ? mb_strlen($response['assistantText']) : 0,
            'responseTotalTokens' => $this->toNullableInt($response['totalTokens'] ?? null),
        ];
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function extractLastMessageRole(array $messages): ?string
    {
        if ([] === $messages) {
            return null;
        }

        $last = $messages[array_key_last($messages)] ?? null;
        if (!\is_array($last)) {
            return null;
        }

        $role = $last['role'] ?? null;

        return \is_string($role) && '' !== trim($role) ? $role : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(?string $json): ?array
    {
        if (null === $json || '' === trim($json)) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @param mixed $value
     */
    private function toNullableInt(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }

        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value)) {
            return (int) $value;
        }

        if (\is_string($value) && '' !== trim($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private function normalizedKeys(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $keys = array_map(static fn (mixed $key): string => (string) $key, array_keys($value));
        sort($keys);

        return $keys;
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit).'...';
    }

    private function resolveOutputPath(string $relativeDir, string $fixtureName): string
    {
        $normalizedDir = str_replace('\\', '/', trim($relativeDir));
        $normalizedDir = trim($normalizedDir, '/');

        if ('' === $normalizedDir || str_contains($normalizedDir, '..')) {
            throw new \InvalidArgumentException('dir must be a safe relative path.');
        }

        if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $normalizedDir)) {
            throw new \InvalidArgumentException('dir contains unsupported characters.');
        }

        return dirname(__DIR__, 2).'/'.$normalizedDir.'/'.$fixtureName.'.json';
    }
}
