<?php

declare(strict_types=1);

namespace App\Research\Message\Llm\Dto;

final readonly class LlmOperationResultPayload
{
    /**
     * @param list<LlmOperationResultToolCall> $toolCalls
     * @param array<string, mixed>             $rawMetadata
     */
    public function __construct(
        public string $assistantText,
        public array $toolCalls,
        public bool $isFinal,
        public ?int $promptTokens,
        public ?int $completionTokens,
        public ?int $totalTokens,
        public array $rawMetadata,
        public string $resultClass,
        public ?string $reasoningText = null,
    ) {
    }
}
