<?php

declare(strict_types=1);

namespace App\Research\Message;

/**
 * Message to execute a research run asynchronously.
 */
final readonly class ExecuteResearchRun
{
    public function __construct(
        public string $runId,
    ) {
    }
}
