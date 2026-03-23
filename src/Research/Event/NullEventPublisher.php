<?php

declare(strict_types=1);

namespace App\Research\Event;

/**
 * No-op event publisher for development or when streaming is disabled.
 */
final class NullEventPublisher implements EventPublisherInterface
{
    public function publishActivity(string $runId, string $stepType, string $summary, array $meta = []): void
    {
    }

    public function publishAnswer(string $runId, string $markdown, bool $isFinal = false): void
    {
    }

    public function publishBudget(string $runId, array $meta): void
    {
    }

    public function publishPhase(string $runId, string $phase, string $status, string $message, array $meta = []): void
    {
    }

    public function publishComplete(string $runId, array $meta = []): void
    {
    }
}
