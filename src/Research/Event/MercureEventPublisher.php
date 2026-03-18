<?php

declare(strict_types=1);

namespace App\Research\Event;

use App\Research\Mercure\ResearchTopicFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Mercure-backed implementation of EventPublisherInterface.
 * Publishes activity, answer, budget, and complete events to run-scoped private topics.
 */
final class MercureEventPublisher implements EventPublisherInterface
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly ResearchTopicFactory $topicFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function publishActivity(string $runId, string $stepType, string $summary, array $meta = []): void
    {
        $payload = [
            'type' => 'activity',
            'stepType' => $stepType,
            'summary' => $summary,
            'meta' => $meta,
        ];

        $this->publish($runId, $payload);
    }

    public function publishAnswer(string $runId, string $markdown, bool $isFinal = false): void
    {
        $payload = [
            'type' => 'answer',
            'markdown' => $markdown,
            'isFinal' => $isFinal,
        ];

        $this->publish($runId, $payload);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function publishBudget(string $runId, array $meta): void
    {
        $payload = [
            'type' => 'budget',
            'meta' => $meta,
        ];

        $this->publish($runId, $payload);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function publishComplete(string $runId, array $meta = []): void
    {
        $payload = [
            'type' => 'complete',
            'meta' => $meta,
        ];

        $this->publish($runId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publish(string $runId, array $payload): void
    {
        $this->logger->info('Publishing event to Mercure', ['runId' => $runId, 'payload' => $payload]);

        $topic = $this->topicFactory->forRun($runId);
        $update = new Update(
            $topic,
            json_encode($payload, \JSON_THROW_ON_ERROR),
            true
        );

        $this->hub->publish($update);
    }
}
