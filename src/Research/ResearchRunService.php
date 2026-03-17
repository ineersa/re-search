<?php

declare(strict_types=1);

namespace App\Research;

use App\Research\Orchestration\RunOrchestratorInterface;

/**
 * Application entry point for a research run.
 * Loads entities, delegates to orchestrator, commits final state.
 * Stub: does not perform real execution yet.
 */
final class ResearchRunService
{
    public function __construct(
        private readonly RunOrchestratorInterface $orchestrator,
    ) {
    }

    /**
     * Execute a research run by ID.
     */
    public function execute(string $runId): void
    {
        $this->orchestrator->execute($runId);
    }
}
