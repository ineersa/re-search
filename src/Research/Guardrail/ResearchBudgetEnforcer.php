<?php

declare(strict_types=1);

namespace App\Research\Guardrail;

/**
 * Stub implementation of budget enforcement.
 * Performs no checks; real enforcement reserved for orchestration phase.
 */
final class ResearchBudgetEnforcer implements ResearchBudgetEnforcerInterface
{
    public function beforeToolCall(string $runId, string $toolName, array $arguments): void
    {
        // Stub: no enforcement yet
    }

    public function afterToolCall(string $runId, string $toolName, array $arguments, mixed $result): void
    {
        // Stub: no recording yet
    }
}
