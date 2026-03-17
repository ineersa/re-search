<?php

declare(strict_types=1);

namespace App\Research\Orchestration\Dto;

final readonly class ResearchTurnResult
{
    /**
     * @param list<ToolCallDecision> $toolCalls
     * @param array<string, mixed>   $rawMetadata
     */
    public function __construct(
        public string $assistantText,
        public array $toolCalls,
        public bool $isFinal,
        public ?int $promptTokens,
        public ?int $completionTokens,
        public ?int $totalTokens,
        public array $rawMetadata,
    ) {
    }
}
