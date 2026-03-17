<?php

declare(strict_types=1);

namespace App\Research\Persistence;

/**
 * Repository interface for research run queries.
 */
interface ResearchRunRepositoryInterface
{
    /**
     * Find recent runs for the given client key.
     *
     * @return list<array{id: string, query: string, status: string, createdAt: \DateTimeImmutable}>
     */
    public function findRecentByClientKey(string $clientKey, int $limit = 20): array;

    /**
     * Find a run with its steps for replay.
     *
     * @return array{run: array<string, mixed>, steps: list<array<string, mixed>>}|null
     */
    public function findRunWithSteps(string $runId): ?array;
}
