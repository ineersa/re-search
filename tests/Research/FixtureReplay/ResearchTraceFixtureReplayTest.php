<?php

declare(strict_types=1);

namespace App\Tests\Research\FixtureReplay;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResearchTraceFixtureReplayTest extends TestCase
{
    #[DataProvider('fixtureProvider')]
    public function testTraceFixtureRespectsOrchestrationContracts(string $fixturePath): void
    {
        if ('' === $fixturePath) {
            self::markTestSkipped('No trace fixtures found in tests/Fixtures/research_traces.');
        }

        $fixture = $this->loadFixture($fixturePath);

        $run = $fixture['run'];
        $operations = $fixture['operations'];
        $steps = $fixture['steps'];
        $expected = $fixture['expected'];

        self::assertSame($expected['finalStatus'], $run['status']);
        self::assertSame($expected['finalPhase'], $run['phase']);

        $this->assertSequentialStepNumbers($steps, $fixturePath);
        $this->assertStepTypeExpectations($steps, $expected, $fixturePath);
        $this->assertOperationKeys($run['runUuid'], $operations, $fixturePath);
        $this->assertTokenSnapshots($steps, $run['tokenBudgetUsed'], $fixturePath);
        $this->assertTerminalContracts($run, $steps, $fixturePath);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function fixtureProvider(): iterable
    {
        $pattern = dirname(__DIR__, 2).'/Fixtures/research_traces/*.json';
        $paths = glob($pattern);
        if (false === $paths || [] === $paths) {
            yield 'no-fixtures' => [''];

            return;
        }

        sort($paths);
        foreach ($paths as $path) {
            $name = basename($path, '.json');
            yield $name => [$path];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(string $fixturePath): array
    {
        $rawFixture = file_get_contents($fixturePath);
        self::assertNotFalse($rawFixture, sprintf('Unable to read fixture file: %s', $fixturePath));

        /** @var array<string, mixed> $fixture */
        $fixture = json_decode($rawFixture, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(1, $fixture['schemaVersion'] ?? null, sprintf('Fixture schema mismatch: %s', $fixturePath));
        self::assertArrayHasKey('run', $fixture);
        self::assertArrayHasKey('operations', $fixture);
        self::assertArrayHasKey('steps', $fixture);
        self::assertArrayHasKey('expected', $fixture);

        return $fixture;
    }

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function assertSequentialStepNumbers(array $steps, string $fixturePath): void
    {
        $expectedSequence = 1;

        foreach ($steps as $step) {
            self::assertSame(
                $expectedSequence,
                $step['sequence'] ?? null,
                sprintf('Non-contiguous step sequence in fixture %s', $fixturePath)
            );

            ++$expectedSequence;
        }
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @param array<string, mixed>       $expected
     */
    private function assertStepTypeExpectations(array $steps, array $expected, string $fixturePath): void
    {
        $stepTypes = array_map(
            static fn (array $step): string => (string) ($step['type'] ?? ''),
            $steps
        );

        $mustContain = $expected['mustContainStepTypes'] ?? [];
        self::assertIsArray($mustContain);
        foreach ($mustContain as $type) {
            self::assertContains(
                $type,
                $stepTypes,
                sprintf('Expected step type "%s" missing in fixture %s', (string) $type, $fixturePath)
            );
        }

        $mustNotContain = $expected['mustNotContainStepTypes'] ?? [];
        self::assertIsArray($mustNotContain);
        foreach ($mustNotContain as $type) {
            self::assertNotContains(
                $type,
                $stepTypes,
                sprintf('Unexpected step type "%s" present in fixture %s', (string) $type, $fixturePath)
            );
        }

        if ([] === $steps) {
            self::assertNull($expected['terminalStepType'] ?? null);

            return;
        }

        $lastStepType = $steps[array_key_last($steps)]['type'] ?? null;
        self::assertSame(
            $expected['terminalStepType'] ?? null,
            $lastStepType,
            sprintf('Terminal step mismatch in fixture %s', $fixturePath)
        );
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    private function assertOperationKeys(string $runUuid, array $operations, string $fixturePath): void
    {
        $seenKeys = [];
        $runUuidPattern = preg_quote($runUuid, '/');

        foreach ($operations as $operation) {
            $idempotencyKey = (string) ($operation['idempotencyKey'] ?? '');
            $type = (string) ($operation['type'] ?? '');
            $status = (string) ($operation['status'] ?? '');

            self::assertNotSame('', $idempotencyKey, sprintf('Missing idempotency key in fixture %s', $fixturePath));
            self::assertArrayNotHasKey($idempotencyKey, $seenKeys, sprintf('Duplicate idempotency key "%s" in fixture %s', $idempotencyKey, $fixturePath));
            $seenKeys[$idempotencyKey] = true;

            self::assertContains($status, ['queued', 'running', 'succeeded', 'failed']);

            if ('llm_call' === $type) {
                self::assertMatchesRegularExpression('/^'.$runUuidPattern.':llm:\d+$/', $idempotencyKey);

                continue;
            }

            if ('tool_call' === $type) {
                self::assertMatchesRegularExpression('/^'.$runUuidPattern.':tool:\d+:\d+$/', $idempotencyKey);

                continue;
            }

            self::fail(sprintf('Unknown operation type "%s" in fixture %s', $type, $fixturePath));
        }
    }

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function assertTokenSnapshots(array $steps, int $runTokenBudgetUsed, string $fixturePath): void
    {
        $lastTotalUsed = null;

        foreach ($steps as $step) {
            if ('token_snapshot' !== ($step['type'] ?? null)) {
                continue;
            }

            $payloadSummary = $step['payloadSummary'] ?? null;
            self::assertIsArray($payloadSummary, sprintf('Missing payload summary for token snapshot in fixture %s', $fixturePath));

            $totalUsed = $payloadSummary['totalUsed'] ?? null;
            self::assertIsInt($totalUsed, sprintf('Token snapshot totalUsed must be int in fixture %s', $fixturePath));

            if (null !== $lastTotalUsed) {
                self::assertGreaterThanOrEqual(
                    $lastTotalUsed,
                    $totalUsed,
                    sprintf('Token usage must be monotonic in fixture %s', $fixturePath)
                );
            }

            $lastTotalUsed = $totalUsed;
        }

        if (null !== $lastTotalUsed) {
            self::assertSame(
                $runTokenBudgetUsed,
                $lastTotalUsed,
                sprintf('Run token budget used does not match last snapshot in fixture %s', $fixturePath)
            );
        }
    }

    /**
     * @param array<string, mixed>       $run
     * @param list<array<string, mixed>> $steps
     */
    private function assertTerminalContracts(array $run, array $steps, string $fixturePath): void
    {
        $status = (string) ($run['status'] ?? '');
        $finalAnswerLength = (int) ($run['finalAnswerLength'] ?? 0);

        $stepTypes = array_map(
            static fn (array $step): string => (string) ($step['type'] ?? ''),
            $steps
        );

        if ('completed' === $status) {
            self::assertGreaterThan(0, $finalAnswerLength, sprintf('Completed run must have a final answer in fixture %s', $fixturePath));
            self::assertContains('assistant_final', $stepTypes, sprintf('Completed run must contain assistant_final in fixture %s', $fixturePath));

            return;
        }

        self::assertSame(0, $finalAnswerLength, sprintf('Non-completed run should not contain final answer markdown in fixture %s', $fixturePath));

        if ('loop_stopped' === $status) {
            self::assertTrue((bool) ($run['loopDetected'] ?? false), sprintf('loop_stopped run must set loopDetected in fixture %s', $fixturePath));
        }

        if ((bool) ($run['answerOnlyTriggered'] ?? false)) {
            self::assertContains('answer_only_enabled', $stepTypes, sprintf('answerOnlyTriggered run must contain answer_only_enabled step in fixture %s', $fixturePath));
        }
    }
}
