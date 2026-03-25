<?php

declare(strict_types=1);

namespace App\Research\Orchestration\Dto;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCall;

final class OrchestratorState implements \JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $messageWindow
     * @param array<string, int>         $toolSignatureCounts
     */
    public function __construct(
        public int $turnNumber = 0,
        public array $messageWindow = [],
        public bool $answerOnly = false,
        public array $toolSignatureCounts = [],
        public int $consecutiveToolFailures = 0,
        public int $emptyResponseRetries = 0,
        public int $finalFormatRetries = 0,
    ) {
    }

    public static function initialize(string $systemPrompt, string $taskPrompt): self
    {
        $state = new self();
        $state->appendSystemMessage($systemPrompt);
        $state->appendUserMessage($taskPrompt);

        return $state;
    }

    public static function fromJson(?string $json): self
    {
        if (null === $json || '' === trim($json)) {
            return new self();
        }

        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('Invalid orchestrator state payload.');
        }

        return new self(
            turnNumber: max(0, (int) ($decoded['turnNumber'] ?? 0)),
            messageWindow: self::normalizeMessageWindow($decoded['messageWindow'] ?? []),
            answerOnly: (bool) ($decoded['answerOnly'] ?? false),
            toolSignatureCounts: self::normalizeSignatureCounts($decoded['toolSignatureCounts'] ?? []),
            consecutiveToolFailures: max(0, (int) ($decoded['consecutiveToolFailures'] ?? 0)),
            emptyResponseRetries: max(0, (int) ($decoded['emptyResponseRetries'] ?? 0)),
            finalFormatRetries: max(0, (int) ($decoded['finalFormatRetries'] ?? 0)),
        );
    }

    public function toJson(): string
    {
        return json_encode($this, \JSON_THROW_ON_ERROR);
    }

    public function appendSystemMessage(string $content): void
    {
        $this->messageWindow[] = [
            'role' => 'system',
            'content' => $content,
        ];
    }

    public function appendUserMessage(string $content): void
    {
        $this->messageWindow[] = [
            'role' => 'user',
            'content' => $content,
        ];
    }

    /**
     * @param list<array{id: string, name: string, arguments: array<string, mixed>}> $toolCalls
     */
    public function appendAssistantMessage(string $content, array $toolCalls = [], ?string $reasoningContent = null): void
    {
        $entry = [
            'role' => 'assistant',
            'content' => $content,
            'toolCalls' => array_map(
                static fn (array $toolCall): array => [
                    'id' => $toolCall['id'],
                    'name' => $toolCall['name'],
                    'arguments' => $toolCall['arguments'],
                ],
                $toolCalls
            ),
        ];

        if (null !== $reasoningContent && '' !== trim($reasoningContent)) {
            $entry['reasoningContent'] = $reasoningContent;
        }

        $this->messageWindow[] = $entry;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function appendToolMessage(string $callId, string $toolName, array $arguments, string $content): void
    {
        $this->messageWindow[] = [
            'role' => 'tool',
            'toolCallId' => $callId,
            'name' => $toolName,
            'arguments' => $arguments,
            'content' => $content,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'turnNumber' => $this->turnNumber,
            'messageWindow' => $this->messageWindow,
            'answerOnly' => $this->answerOnly,
            'toolSignatureCounts' => $this->toolSignatureCounts,
            'consecutiveToolFailures' => $this->consecutiveToolFailures,
            'emptyResponseRetries' => $this->emptyResponseRetries,
            'finalFormatRetries' => $this->finalFormatRetries,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function normalizeMessageWindow(mixed $messageWindow): array
    {
        if (!\is_array($messageWindow)) {
            return [];
        }

        $normalized = [];
        foreach ($messageWindow as $messageEntry) {
            if (!\is_array($messageEntry)) {
                continue;
            }

            $normalized[] = $messageEntry;
        }

        return $normalized;
    }

    /**
     * @return array<string, int>
     */
    private static function normalizeSignatureCounts(mixed $signatureCounts): array
    {
        if (!\is_array($signatureCounts)) {
            return [];
        }

        $normalized = [];
        foreach ($signatureCounts as $signature => $count) {
            if (!\is_string($signature) || '' === trim($signature)) {
                continue;
            }

            $normalized[$signature] = max(0, (int) $count);
        }

        return $normalized;
    }
}
