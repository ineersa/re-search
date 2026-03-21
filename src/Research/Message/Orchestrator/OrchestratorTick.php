<?php

declare(strict_types=1);

namespace App\Research\Message\Orchestrator;

final readonly class OrchestratorTick
{
    public function __construct(
        public string $runId,
    ) {
    }
}
