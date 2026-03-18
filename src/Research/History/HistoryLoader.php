<?php

declare(strict_types=1);

namespace App\Research\History;

use App\Research\Persistence\ResearchRunRepository;

/**
 * Loads research history from persistence.
 * Returns empty data until repository is wired to real entities.
 */
final class HistoryLoader implements HistoryLoaderInterface
{
    public function __construct(
        private readonly ResearchRunRepository $repository,
    ) {
    }

    public function loadRecent(string $clientKey, int $limit = 20): array
    {
        return $this->repository->findRecentByClientKey($clientKey, $limit);
    }

    public function loadRun(string $runId): ?array
    {
        return $this->repository->findRunWithSteps($runId);
    }
}
