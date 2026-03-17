<?php

declare(strict_types=1);

namespace App\Research\Orchestration;

/**
 * Orchestrates the research run loop: model turns, tool execution, budget injection,
 * and answer-only mode. Does not wire the real model loop until orchestration is implemented.
 */
interface RunOrchestratorInterface
{
    /**
     * Execute a research run for the given run ID.
     * Stub implementation does not perform real model calls.
     */
    public function execute(string $runId): void;
}
