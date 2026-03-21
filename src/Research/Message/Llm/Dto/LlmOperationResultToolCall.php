<?php

declare(strict_types=1);

namespace App\Research\Message\Llm\Dto;

final readonly class LlmOperationResultToolCall
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments,
    ) {
    }
}
