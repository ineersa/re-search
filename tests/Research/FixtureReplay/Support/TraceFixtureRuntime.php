<?php

declare(strict_types=1);

namespace App\Tests\Research\FixtureReplay\Support;

final class TraceFixtureRuntime
{
    /** @var array<string, mixed>|null */
    private ?array $fixture = null;

    /** @var list<array<string, mixed>> */
    private array $llmOperations = [];

    /** @var list<array<string, mixed>> */
    private array $toolOperations = [];

    private int $llmCursor = 0;
    private int $toolCursor = 0;

    public function loadFixture(string $fixtureName): void
    {
        $path = dirname(__DIR__, 3).'/Fixtures/research_traces/'.$fixtureName.'.json';
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Fixture file not found: %s', $path));
        }

        $raw = file_get_contents($path);
        if (false === $raw) {
            throw new \RuntimeException(sprintf('Unable to read fixture file: %s', $path));
        }

        /** @var array<string, mixed> $fixture */
        $fixture = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        $operations = $fixture['operations'] ?? null;
        if (!is_array($operations)) {
            throw new \RuntimeException(sprintf('Fixture has invalid operations payload: %s', $path));
        }

        $this->fixture = $fixture;
        $this->llmOperations = [];
        $this->toolOperations = [];

        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }

            if ('llm_call' === ($operation['type'] ?? null)) {
                $this->llmOperations[] = $operation;

                continue;
            }

            if ('tool_call' === ($operation['type'] ?? null)) {
                $this->toolOperations[] = $operation;
            }
        }

        $this->llmCursor = 0;
        $this->toolCursor = 0;
    }

    public function clear(): void
    {
        $this->fixture = null;
        $this->llmOperations = [];
        $this->toolOperations = [];
        $this->llmCursor = 0;
        $this->toolCursor = 0;
    }

    public function taskPromptSummary(): string
    {
        $runStartedSummary = $this->findStepSummary('run_started');
        if (null !== $runStartedSummary && '' !== trim($runStartedSummary)) {
            return $runStartedSummary;
        }

        return 'Conduct web research and provide a direct answer with references.';
    }

    /**
     * @return array{run: array<string, mixed>, expected: array<string, mixed>}
     */
    public function runExpectation(): array
    {
        if (null === $this->fixture) {
            throw new \RuntimeException('No fixture loaded in runtime.');
        }

        $run = $this->fixture['run'] ?? null;
        $expected = $this->fixture['expected'] ?? null;
        if (!is_array($run) || !is_array($expected)) {
            throw new \RuntimeException('Fixture is missing run/expected payload.');
        }

        return ['run' => $run, 'expected' => $expected];
    }

    /**
     * @return array{
     *     assistantText: string,
     *     toolCalls: list<array{callId: string, name: string, arguments: array<string, mixed>}>,
     *     promptTokens: ?int,
     *     completionTokens: ?int,
     *     totalTokens: ?int
     * }
     */
    public function consumeLlmResult(string $model, int $messageCount, ?string $lastMessageRole, bool $allowTools): array
    {
        if (null === $this->fixture) {
            return [
                'assistantText' => 'Fixture runtime inactive.',
                'toolCalls' => [],
                'promptTokens' => null,
                'completionTokens' => null,
                'totalTokens' => null,
            ];
        }

        $operation = $this->llmOperations[$this->llmCursor] ?? null;
        if (!is_array($operation)) {
            throw new \RuntimeException('No next LLM fixture operation available.');
        }

        ++$this->llmCursor;

        $requestSummary = is_array($operation['requestSummary'] ?? null) ? $operation['requestSummary'] : [];
        $resultSummary = is_array($operation['resultSummary'] ?? null) ? $operation['resultSummary'] : [];

        $this->assertLlmRequest($requestSummary, $model, $messageCount, $lastMessageRole, $allowTools);

        $resultKind = (string) ($resultSummary['kind'] ?? '');
        if ('llm_result_unparseable' === $resultKind) {
            $errorMessage = (string) ($operation['errorMessage'] ?? 'Fixture configured unparseable LLM result.');
            throw new \RuntimeException($errorMessage);
        }

        $isFinal = (bool) ($resultSummary['isFinal'] ?? false);
        $assistantTextLength = $this->toNullableInt($resultSummary['assistantTextLength'] ?? null) ?? 0;
        $turnNumber = $this->toNullableInt($operation['turnNumber'] ?? null) ?? 0;

        $toolCalls = [];
        $toolCallCount = $this->toNullableInt($resultSummary['toolCallCount'] ?? null) ?? 0;
        if ($toolCallCount > 0) {
            $toolCalls = $this->toolCallsForTurn($turnNumber);
            if ([] === $toolCalls) {
                $toolCalls = $this->fallbackToolCalls($turnNumber, $toolCallCount);
            }
        }

        $assistantText = '';
        if ($isFinal) {
            $assistantText = $this->findAssistantFinalSummaryForTurn($turnNumber) ?? $this->textOfLength($assistantTextLength);
        } elseif (0 < $assistantTextLength) {
            $assistantText = $this->textOfLength($assistantTextLength);
        }

        return [
            'assistantText' => $assistantText,
            'toolCalls' => $toolCalls,
            'promptTokens' => $this->toNullableInt($resultSummary['promptTokens'] ?? null),
            'completionTokens' => $this->toNullableInt($resultSummary['completionTokens'] ?? null),
            'totalTokens' => $this->toNullableInt($resultSummary['totalTokens'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{text: string, isError: bool}
     */
    public function consumeToolResult(string $mcpToolName, array $arguments): array
    {
        if (null === $this->fixture) {
            return ['text' => 'Fixture runtime inactive tool response.', 'isError' => false];
        }

        $actualToolName = $this->toToolNameFromMcpMethod($mcpToolName);
        $operationIndex = $this->findMatchingToolOperationIndex($actualToolName, $arguments, $this->toolCursor);
        if (null === $operationIndex) {
            return ['text' => 'fixture-missing-tool-operation', 'isError' => false];
        }

        $operation = $this->toolOperations[$operationIndex];
        $this->toolCursor = $operationIndex + 1;

        $requestSummary = is_array($operation['requestSummary'] ?? null) ? $operation['requestSummary'] : [];
        $resultSummary = is_array($operation['resultSummary'] ?? null) ? $operation['resultSummary'] : [];

        $expectedToolName = (string) ($requestSummary['name'] ?? '');
        if ('' !== $expectedToolName && $expectedToolName !== $actualToolName) {
            return ['text' => 'fixture-tool-name-mismatch', 'isError' => false];
        }

        $expectedArguments = $this->extractArgumentsFromSignature((string) ($requestSummary['normalizedSignature'] ?? ''));
        if ([] !== $expectedArguments) {
            $normalizedExpected = $this->normalizeArguments($expectedArguments);
            $normalizedActual = $this->normalizeArguments($arguments);

            if (!$this->expectedArgumentsMatchActual($normalizedExpected, $normalizedActual)) {
                return ['text' => 'fixture-tool-arguments-mismatch', 'isError' => false];
            }
        }

        $kind = (string) ($resultSummary['kind'] ?? 'tool_result');
        if ('tool_error' === $kind) {
            $errorMessage = (string) ($resultSummary['errorMessage'] ?? 'Fixture tool error');

            return ['text' => $errorMessage, 'isError' => true];
        }

        $resultLength = $this->toNullableInt($resultSummary['resultLength'] ?? null) ?? 120;

        return ['text' => $this->textOfLength($resultLength), 'isError' => false];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function findMatchingToolOperationIndex(string $toolName, array $arguments, int $startIndex): ?int
    {
        $normalizedActual = $this->normalizeArguments($arguments);

        for ($index = $startIndex; $index < \count($this->toolOperations); ++$index) {
            $candidate = $this->toolOperations[$index];
            if (!is_array($candidate)) {
                continue;
            }

            $requestSummary = is_array($candidate['requestSummary'] ?? null) ? $candidate['requestSummary'] : [];
            $candidateName = (string) ($requestSummary['name'] ?? '');
            if ('' !== $candidateName && $candidateName !== $toolName) {
                continue;
            }

            $expectedArguments = $this->extractArgumentsFromSignature((string) ($requestSummary['normalizedSignature'] ?? ''));
            if ([] === $expectedArguments) {
                return $index;
            }

            $normalizedExpected = $this->normalizeArguments($expectedArguments);
            if ($this->expectedArgumentsMatchActual($normalizedExpected, $normalizedActual)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $requestSummary
     */
    private function assertLlmRequest(array $requestSummary, string $model, int $messageCount, ?string $lastMessageRole, bool $allowTools): void
    {
        $expectedModel = (string) ($requestSummary['model'] ?? '');
        if ('' !== $expectedModel && $expectedModel !== $model) {
            throw new \RuntimeException(sprintf('LLM model mismatch. Expected %s, got %s.', $expectedModel, $model));
        }

        $expectedAllowTools = $requestSummary['allowTools'] ?? null;
        if (is_bool($expectedAllowTools) && $expectedAllowTools !== $allowTools) {
            throw new \RuntimeException(sprintf(
                'LLM allowTools mismatch. Expected %s, got %s.',
                $expectedAllowTools ? 'true' : 'false',
                $allowTools ? 'true' : 'false',
            ));
        }

        $expectedMessageCount = $this->toNullableInt($requestSummary['messageCount'] ?? null);
        if (null !== $expectedMessageCount && $expectedMessageCount !== $messageCount) {
            throw new \RuntimeException(sprintf(
                'LLM message count mismatch. Expected %d, got %d.',
                $expectedMessageCount,
                $messageCount,
            ));
        }

        $expectedLastRole = $requestSummary['lastMessageRole'] ?? null;
        if (is_string($expectedLastRole) && '' !== trim($expectedLastRole) && $expectedLastRole !== $lastMessageRole) {
            throw new \RuntimeException(sprintf(
                'LLM last message role mismatch. Expected %s, got %s.',
                $expectedLastRole,
                (string) $lastMessageRole,
            ));
        }
    }

    /**
     * @return list<array{callId: string, name: string, arguments: array<string, mixed>}>
     */
    private function toolCallsForTurn(int $turnNumber): array
    {
        $toolCalls = [];

        foreach ($this->toolOperations as $operation) {
            if (($operation['turnNumber'] ?? null) !== $turnNumber) {
                continue;
            }

            $requestSummary = is_array($operation['requestSummary'] ?? null) ? $operation['requestSummary'] : [];
            $name = (string) ($requestSummary['name'] ?? 'websearch_search');
            $callId = (string) ($requestSummary['callId'] ?? sprintf('fixture_call_%d', \count($toolCalls)));
            $arguments = $this->coerceToolArguments($this->extractArgumentsFromSignature((string) ($requestSummary['normalizedSignature'] ?? '')));

            $toolCalls[] = [
                'callId' => $callId,
                'name' => $name,
                'arguments' => $arguments,
            ];
        }

        return $toolCalls;
    }

    private function findAssistantFinalSummaryForTurn(int $turnNumber): ?string
    {
        if (null === $this->fixture) {
            return null;
        }

        $steps = $this->fixture['steps'] ?? null;
        if (!is_array($steps)) {
            return null;
        }

        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            if ('assistant_final' !== ($step['type'] ?? null)) {
                continue;
            }

            if (($step['turnNumber'] ?? null) !== $turnNumber) {
                continue;
            }

            $summary = $step['summary'] ?? null;
            if (!is_string($summary) || '' === trim($summary)) {
                continue;
            }

            return $summary;
        }

        return $this->findStepSummary('assistant_final');
    }

    private function findStepSummary(string $stepType): ?string
    {
        if (null === $this->fixture) {
            return null;
        }

        $steps = $this->fixture['steps'] ?? null;
        if (!is_array($steps)) {
            return null;
        }

        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            if ($stepType !== ($step['type'] ?? null)) {
                continue;
            }

            $summary = $step['summary'] ?? null;
            if (!is_string($summary)) {
                continue;
            }

            return $summary;
        }

        return null;
    }

    /**
     * @return list<array{callId: string, name: string, arguments: array<string, mixed>}>
     */
    private function fallbackToolCalls(int $turnNumber, int $toolCallCount): array
    {
        $loopSignature = $this->findStepSummary('loop_detected');
        if (null !== $loopSignature && str_contains($loopSignature, ':')) {
            $parsed = $this->parseToolSignature($loopSignature);
            if (null !== $parsed) {
                return [[
                    'callId' => sprintf('call_t%d_p0', $turnNumber),
                    'name' => $parsed['name'],
                    'arguments' => $this->coerceToolArguments($parsed['arguments']),
                ]];
            }
        }

        $previousTurnCalls = $this->toolCallsForTurn(max(0, $turnNumber - 1));
        if ([] !== $previousTurnCalls) {
            $template = $previousTurnCalls[0];

            return [[
                'callId' => sprintf('call_t%d_p0', $turnNumber),
                'name' => $template['name'],
                'arguments' => $template['arguments'],
            ]];
        }

        $calls = [];
        for ($i = 0; $i < max(1, $toolCallCount); ++$i) {
            $calls[] = [
                'callId' => sprintf('call_t%d_p%d', $turnNumber, $i),
                'name' => 'websearch_search',
                'arguments' => ['query' => 'fixture fallback search', 'topn' => 3],
            ];
        }

        return $calls;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractArgumentsFromSignature(string $signature): array
    {
        $parsed = $this->parseToolSignature($signature);
        if (null === $parsed) {
            return [];
        }

        return $parsed['arguments'];
    }

    /**
     * @return array{name: string, arguments: array<string, mixed>}|null
     */
    private function parseToolSignature(string $signature): ?array
    {
        if ('' === trim($signature) || !str_contains($signature, ':')) {
            return null;
        }

        [$name, $rawArgumentsJson] = explode(':', $signature, 2);
        if ('' === trim($name) || '' === trim($rawArgumentsJson)) {
            return null;
        }

        try {
            $decoded = json_decode($rawArgumentsJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        return ['name' => $name, 'arguments' => $decoded];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function normalizeArguments(array $arguments): array
    {
        ksort($arguments);

        $normalized = [];
        foreach ($arguments as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = $this->normalizeArguments($value);

                continue;
            }

            if (is_bool($value)) {
                $normalized[$key] = $value ? 'true' : 'false';

                continue;
            }

            if (is_scalar($value) || null === $value) {
                $normalized[$key] = (string) $value;

                continue;
            }

            $normalized[$key] = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function coerceToolArguments(array $arguments): array
    {
        $coerced = [];
        foreach ($arguments as $key => $value) {
            if (is_array($value)) {
                $coerced[$key] = $this->coerceToolArguments($value);

                continue;
            }

            if (is_string($value)) {
                if ('true' === $value) {
                    $coerced[$key] = true;

                    continue;
                }

                if ('false' === $value) {
                    $coerced[$key] = false;

                    continue;
                }

                if (is_numeric($value)) {
                    $coerced[$key] = str_contains($value, '.') ? (float) $value : (int) $value;

                    continue;
                }
            }

            $coerced[$key] = $value;
        }

        return $coerced;
    }

    private function toToolNameFromMcpMethod(string $method): string
    {
        return match ($method) {
            'search' => 'websearch_search',
            'open' => 'websearch_open',
            'find' => 'websearch_find',
            default => $method,
        };
    }

    private function textOfLength(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        return str_repeat('x', $length);
    }

    private function toNullableInt(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && '' !== trim($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private function expectedArgumentsMatchActual(array $expected, array $actual): bool
    {
        foreach ($expected as $key => $expectedValue) {
            if (!array_key_exists($key, $actual)) {
                return false;
            }

            if ($actual[$key] !== $expectedValue) {
                return false;
            }
        }

        return true;
    }

}
