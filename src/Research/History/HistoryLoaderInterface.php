<?php

declare(strict_types=1);

namespace App\Research\History;

/**
 * Loads research run history for sidebar and replay.
 */
interface HistoryLoaderInterface
{
    /**
     * Load recent runs for the given client key (IP + session fingerprint).
     *
     * @return list<array{id: string, query: string, status: string, createdAt: \DateTimeInterface|null, completedAt: \DateTimeInterface|null, tokenBudgetUsed: int|null, tokenBudgetHardCap: int|null, loopDetected: bool, answerOnlyTriggered: bool, failureReason: string|null}>
     */
    public function loadRecent(string $clientKey, int $limit = 20): array;

    /**
     * Load a single run by ID for replay.
     *
     * @return array{run: array<string, mixed>, steps: list<array<string, mixed>>}|null
     */
    public function loadRun(string $runId): ?array;
}
