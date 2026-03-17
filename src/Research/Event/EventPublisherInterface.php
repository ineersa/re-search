<?php

declare(strict_types=1);

namespace App\Research\Event;

/**
 * Publishes research run events (activity, answer, budget, complete).
 * Transport-agnostic; Mercure or SSE implementations can be swapped.
 */
interface EventPublisherInterface
{
    /**
     * Publish an activity event (tool calls, reasoning summaries, warnings).
     *
     * @param array<string, mixed> $meta
     */
    public function publishActivity(string $runId, string $stepType, string $summary, array $meta = []): void;

    /**
     * Publish an answer chunk or final markdown.
     */
    public function publishAnswer(string $runId, string $markdown, bool $isFinal = false): void;

    /**
     * Publish a budget update (tokens used, remaining).
     *
     * @param array<string, mixed> $meta
     */
    public function publishBudget(string $runId, array $meta): void;

    /**
     * Publish run completion.
     *
     * @param array<string, mixed> $meta
     */
    public function publishComplete(string $runId, array $meta = []): void;
}
