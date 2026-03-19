<?php

declare(strict_types=1);

namespace App\Research\Persistence;

use App\Research\Orchestration\Dto\ResearchTurnResult;
use Symfony\AI\Chat\MessageNormalizer;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Serializes LLM invocation request/response for trace persistence and replay.
 */
final class LlmInvocationTraceSerializer
{
    private readonly SerializerInterface $serializer;

    public function __construct(
        ?SerializerInterface $serializer = null,
    ) {
        $this->serializer = $serializer ?? new Serializer(
            [new ArrayDenormalizer(), new MessageNormalizer()],
            [new JsonEncoder()]
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{request: array{model: string, messages: string, toolNames: list<string>, tools: list<array{name: string, description: string, parameters: array<string, mixed>|null}>}, response: array{assistantText: string, toolCalls: list<array{name: string, arguments: array<string, mixed>}>, isFinal: bool, promptTokens: int|null, completionTokens: int|null, totalTokens: int|null}}
     */
    public function buildPayload(
        string $model,
        MessageBag $messages,
        array $options,
        ResearchTurnResult $turnResult,
    ): array {
        $toolMap = $options['tools'] ?? [];
        $toolDefinitions = $this->extractToolDefinitions($toolMap);
        $toolNames = array_column($toolDefinitions, 'name');

        $messagesJson = $this->serializer->serialize($messages->getMessages(), 'json');

        return [
            'request' => [
                'model' => $model,
                'messages' => $messagesJson,
                'toolNames' => $toolNames,
                'tools' => $toolDefinitions,
            ],
            'response' => [
                'assistantText' => $turnResult->assistantText,
                'toolCalls' => array_map(
                    static fn ($d) => [
                        'name' => $d->name,
                        'arguments' => $d->arguments,
                    ],
                    $turnResult->toolCalls
                ),
                'isFinal' => $turnResult->isFinal,
                'promptTokens' => $turnResult->promptTokens,
                'completionTokens' => $turnResult->completionTokens,
                'totalTokens' => $turnResult->totalTokens,
            ],
        ];
    }

    /**
     * @param mixed $toolMap Tool[] from toolbox or API-ready array
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>|null}>
     */
    private function extractToolDefinitions(mixed $toolMap): array
    {
        if (!\is_array($toolMap)) {
            return [];
        }

        $defs = [];
        foreach ($toolMap as $tool) {
            if ($tool instanceof Tool) {
                $defs[] = [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParameters(),
                ];
            } elseif (\is_array($tool) && isset($tool['function'])) {
                $fn = $tool['function'];
                $defs[] = [
                    'name' => $fn['name'] ?? '',
                    'description' => $fn['description'] ?? '',
                    'parameters' => $fn['parameters'] ?? null,
                ];
            }
        }

        return $defs;
    }
}
