<?php

declare(strict_types=1);

namespace App\Research\Mercure;

/**
 * Builds Mercure topic URIs for research runs.
 *
 * Topic format: {baseUrl}/research/runs/{uuid}
 */
final class ResearchTopicFactory
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    public function forRun(string $runId): string
    {
        return rtrim($this->baseUrl, '/').'/research/runs/'.$runId;
    }
}
