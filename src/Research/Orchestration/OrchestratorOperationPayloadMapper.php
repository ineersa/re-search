<?php

declare(strict_types=1);

namespace App\Research\Orchestration;

use App\Research\Message\Llm\Dto\LlmMessageWindowEntry;
use App\Research\Message\Llm\Dto\LlmMessageWindowToolCall;
use App\Research\Message\Llm\Dto\LlmOperationRequest;
use App\Research\Message\Llm\Dto\LlmOperationResultPayload;
use App\Research\Message\Llm\Dto\LlmOperationResultToolCall;
use App\Research\Message\Tool\Dto\ToolOperationErrorPayload;
use App\Research\Message\Tool\Dto\ToolOperationRequest;
use App\Research\Message\Tool\Dto\ToolOperationResultPayload;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class OrchestratorOperationPayloadMapper
{
    private readonly Serializer $serializer;

    public function __construct()
    {
        $this->serializer = new Serializer(
            [new DateTimeNormalizer(), new ArrayDenormalizer(), new ObjectNormalizer()],
            [new JsonEncoder()]
        );
    }

    public function decodeLlmRequest(string $json): LlmOperationRequest
    {
        return $this->serializer->deserialize($json, LlmOperationRequest::class, 'json');
    }

    public function decodeLlmResult(string $json): LlmOperationResultPayload
    {
        return $this->serializer->deserialize($json, LlmOperationResultPayload::class, 'json');
    }

    public function decodeToolRequest(string $json): ToolOperationRequest
    {
        return $this->serializer->deserialize($json, ToolOperationRequest::class, 'json');
    }

    public function decodeToolResult(string $json): ToolOperationResultPayload
    {
        return $this->serializer->deserialize($json, ToolOperationResultPayload::class, 'json');
    }

    public function decodeToolError(string $json): ToolOperationErrorPayload
    {
        return $this->serializer->deserialize($json, ToolOperationErrorPayload::class, 'json');
    }

    public function toMessageBag(LlmOperationRequest $request): MessageBag
    {
        /** @var list<LlmMessageWindowEntry> $entries */
        $entries = $this->serializer->denormalize($request->messages, LlmMessageWindowEntry::class.'[]');
        $messages = [];
        foreach ($entries as $entry) {
            $message = $this->messageWindowEntryToMessage($entry);
            if ($message instanceof MessageInterface) {
                $messages[] = $message;
            }
        }

        return new MessageBag(...$messages);
    }

    /**
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    public function normalizeLlmToolCalls(LlmOperationResultPayload $payload): array
    {
        $normalized = [];
        foreach ($payload->toolCalls as $toolCall) {
            if ($toolCall instanceof LlmOperationResultToolCall) {
                $normalized[] = ['name' => $toolCall->name, 'arguments' => $toolCall->arguments];
                continue;
            }

            if (!\is_array($toolCall)) {
                continue;
            }

            $name = $toolCall['name'] ?? null;
            if (!\is_string($name) || '' === trim($name)) {
                continue;
            }

            $arguments = \is_array($toolCall['arguments'] ?? null) ? $toolCall['arguments'] : [];
            $normalized[] = ['name' => $name, 'arguments' => $arguments];
        }

        return $normalized;
    }

    public function toNullableInt(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }
        if (\is_int($value)) {
            return $value;
        }
        if (\is_float($value)) {
            return (int) $value;
        }
        if (\is_string($value) && '' !== trim($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function normalizeToolSignature(string $toolName, array $arguments): string
    {
        $normalized = [];
        foreach ($arguments as $key => $value) {
            $normalized[$key] = \is_scalar($value) ? (string) $value : $this->encodeJson(['value' => $value]);
        }
        ksort($normalized);

        return $toolName.':'.$this->encodeJson($normalized);
    }

    /**
     * @param object|array<string, mixed>|null $payload
     */
    public function encodeJson(object|array|null $payload): string
    {
        try {
            return $this->serializer->serialize($payload ?? [], 'json');
        } catch (\Throwable) {
            return '{}';
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function extractRawMetadata(ResultInterface $result): array
    {
        $normalized = [];
        foreach ($result->getMetadata()->all() as $key => $value) {
            try {
                $json = $this->serializer->serialize($value, 'json');
                $normalized[(string) $key] = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
        }

        return $normalized;
    }

    /**
     * @param mixed $rawToolCalls
     *
     * @return list<ToolCall>
     */
    private function decodeAssistantToolCalls(mixed $rawToolCalls): array
    {
        if (!\is_array($rawToolCalls)) {
            return [];
        }

        /** @var list<LlmMessageWindowToolCall> $entries */
        $entries = $this->serializer->denormalize($rawToolCalls, LlmMessageWindowToolCall::class.'[]');

        $toolCalls = [];
        foreach ($entries as $entry) {
            $toolCalls[] = new ToolCall(
                '' !== trim($entry->id) ? $entry->id : 'call_unknown',
                '' !== trim($entry->name) ? $entry->name : 'unknown_tool',
                $entry->arguments
            );
        }

        return $toolCalls;
    }

    private function messageWindowEntryToMessage(LlmMessageWindowEntry $entry): ?MessageInterface
    {
        $role = strtolower(trim($entry->role));

        return match ($role) {
            'system' => Message::forSystem($entry->content),
            'user' => Message::ofUser($entry->content),
            'assistant' => new AssistantMessage(
                $entry->content,
                $this->decodeAssistantToolCalls($entry->toolCalls),
                null !== $entry->reasoningContent && '' !== trim($entry->reasoningContent) ? $entry->reasoningContent : null,
            ),
            'tool' => Message::ofToolCall(
                new ToolCall(
                    null !== $entry->toolCallId && '' !== trim($entry->toolCallId) ? $entry->toolCallId : 'call_unknown',
                    null !== $entry->name && '' !== trim($entry->name) ? $entry->name : 'unknown_tool',
                    $entry->arguments
                ),
                $entry->content
            ),
            default => null,
        };
    }
}
