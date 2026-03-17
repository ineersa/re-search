<?php

declare(strict_types=1);

namespace App\Research\Guardrail;

/**
 * Enforces token budget and duplicate-call loop detection before/after tool invocations.
 */
interface ResearchBudgetEnforcerInterface
{
    /**
     * Called before each tool call. Throws if over budget or loop detected.
     *
     * @param array<string, mixed> $arguments
     */
    public function beforeToolCall(string $runId, string $toolName, array $arguments): void;

    /**
     * Called after each tool call to record usage.
     *
     * @param array<string, mixed> $arguments
     * @param mixed                $result
     */
    public function afterToolCall(string $runId, string $toolName, array $arguments, mixed $result): void;
}
