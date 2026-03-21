<?php

declare(strict_types=1);

namespace App\Research\Message\Llm\Dto;

final readonly class LlmMessageWindowEntry
{
    /**
     * @param list<array<string, mixed>> $toolCalls
     * @param array<string, mixed>       $arguments
     */
    public function __construct(
        public string $role = '',
        public string $content = '',
        public array $toolCalls = [],
        public ?string $toolCallId = null,
        public ?string $name = null,
        public array $arguments = [],
    ) {
    }
}
