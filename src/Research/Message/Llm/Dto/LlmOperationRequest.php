<?php

declare(strict_types=1);

namespace App\Research\Message\Llm\Dto;

final readonly class LlmOperationRequest
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     */
    public function __construct(
        public ?string $model = null,
        public array $messages = [],
        public bool $allowTools = true,
        public array $options = [],
    ) {
    }
}
