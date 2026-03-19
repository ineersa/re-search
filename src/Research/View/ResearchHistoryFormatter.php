<?php

declare(strict_types=1);

namespace App\Research\View;

/**
 * Present research run rows for the history Turbo Frame (parity with former JS helpers).
 */
final class ResearchHistoryFormatter
{
    /**
     * @param array{
     *     id: string,
     *     query: string,
     *     status: string,
     *     createdAt: \DateTimeInterface|null,
     *     completedAt: \DateTimeInterface|null,
     *     tokenBudgetUsed: int|null,
     *     tokenBudgetHardCap: int|null,
     *     loopDetected: bool,
     *     answerOnlyTriggered: bool,
     * } $run
     *
     * @return array{id: string, query: string, timeAgo: string, statusLabel: string, budgetMeta: string}
     */
    public static function formatRow(array $run): array
    {
        $reference = $run['completedAt'] ?? $run['createdAt'];

        return [
            'id' => $run['id'],
            'query' => $run['query'],
            'timeAgo' => self::formatTimeAgo($reference),
            'statusLabel' => self::formatStatus($run['status']),
            'budgetMeta' => self::formatBudgetMeta($run),
        ];
    }

    public static function formatTimeAgo(?\DateTimeInterface $date): string
    {
        if (null === $date) {
            return '';
        }

        $now = new \DateTimeImmutable('now');
        $diffSec = $now->getTimestamp() - $date->getTimestamp();
        $diffMin = intdiv($diffSec, 60);
        $diffHr = intdiv($diffMin, 60);
        $diffDay = intdiv($diffHr, 24);

        if ($diffSec < 60) {
            return 'just now';
        }
        if ($diffMin < 60) {
            return sprintf('%dm ago', $diffMin);
        }
        if ($diffHr < 24) {
            return sprintf('%dh ago', $diffHr);
        }
        if ($diffDay < 7) {
            return sprintf('%dd ago', $diffDay);
        }

        return $date->format('M j, Y');
    }

    public static function formatStatus(string $status): string
    {
        return match ($status) {
            'completed' => 'Complete',
            'running' => 'Running',
            'queued' => 'Queued',
            'answer_only' => 'Answer only',
            'budget_exhausted' => 'Budget exhausted',
            'loop_stopped' => 'Loop stopped',
            'failed' => 'Failed',
            'timed_out' => 'Timed out',
            'throttled' => 'Rate limited',
            'aborted' => 'Aborted',
            default => $status,
        };
    }

    /**
     * @param array{
     *     tokenBudgetUsed: int|null,
     *     tokenBudgetHardCap: int|null,
     *     loopDetected: bool,
     *     answerOnlyTriggered: bool,
     * } $run
     */
    public static function formatBudgetMeta(array $run): string
    {
        $parts = [];
        if (isset($run['tokenBudgetUsed'], $run['tokenBudgetHardCap'])) {
            $parts[] = sprintf(
                '%.1fk / %.0fk tokens',
                $run['tokenBudgetUsed'] / 1000,
                $run['tokenBudgetHardCap'] / 1000
            );
        }
        if ($run['loopDetected']) {
            $parts[] = 'loop';
        }
        if ($run['answerOnlyTriggered']) {
            $parts[] = 'answer-only';
        }

        return implode(', ', $parts);
    }
}
