<?php

declare(strict_types=1);

namespace App\Research\Message\Llm\Dto;

final readonly class LlmMessageWindowToolCall
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $arguments = [],
    ) {
    }
}
