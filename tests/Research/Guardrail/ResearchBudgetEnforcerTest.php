<?php

declare(strict_types=1);

namespace App\Tests\Research\Guardrail;

use App\Research\Guardrail\Exception\BudgetExhaustedException;
use App\Research\Guardrail\Exception\LoopDetectedException;
use App\Research\Guardrail\ResearchBudgetEnforcer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResearchBudgetEnforcerTest extends TestCase
{
    private ResearchBudgetEnforcer $enforcer;

    protected function setUp(): void
    {
        $this->enforcer = new ResearchBudgetEnforcer();
    }

    #[Test]
    public function beforeToolCallPassesWhenUnderBudgetAndNoDuplicates(): void
    {
        $this->enforcer->beforeToolCall('run-1', 'websearch_search', ['query' => 'test']);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function beforeToolCallThrowsWhenBudgetExhausted(): void
    {
        $this->enforcer->recordTokenUsage('run-1', 75_000);

        $this->expectException(BudgetExhaustedException::class);
        $this->expectExceptionMessage('Token budget exhausted');

        $this->enforcer->beforeToolCall('run-1', 'websearch_search', ['query' => 'test']);
    }

    #[Test]
    public function beforeToolCallThrowsOnThirdIdenticalCall(): void
    {
        $args = ['query' => 'symfony'];
        $this->enforcer->beforeToolCall('run-1', 'websearch_search', $args);
        $this->enforcer->afterToolCall('run-1', 'websearch_search', $args, []);

        $this->enforcer->beforeToolCall('run-1', 'websearch_search', $args);
        $this->enforcer->afterToolCall('run-1', 'websearch_search', $args, []);

        $this->expectException(LoopDetectedException::class);
        $this->expectExceptionMessage('Duplicate tool call detected');

        $this->enforcer->beforeToolCall('run-1', 'websearch_search', $args);
    }

    #[Test]
    public function differentRunsHaveIndependentState(): void
    {
        $this->enforcer->recordTokenUsage('run-1', 75_000);

        // run-2 should still be allowed
        $this->enforcer->beforeToolCall('run-2', 'websearch_search', ['query' => 'test']);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function differentSignaturesAllowMoreThanTwoCalls(): void
    {
        $this->enforcer->beforeToolCall('run-1', 'websearch_search', ['query' => 'a']);
        $this->enforcer->afterToolCall('run-1', 'websearch_search', ['query' => 'a'], []);

        $this->enforcer->beforeToolCall('run-1', 'websearch_search', ['query' => 'a']);
        $this->enforcer->afterToolCall('run-1', 'websearch_search', ['query' => 'a'], []);

        // Different query should still be allowed
        $this->enforcer->beforeToolCall('run-1', 'websearch_search', ['query' => 'b']);
        $this->addToAssertionCount(1);
    }
}
