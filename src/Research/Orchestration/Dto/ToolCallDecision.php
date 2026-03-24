<?php

declare(strict_types=1);

namespace App\Research\Orchestration\Dto;

final readonly class ToolCallDecision
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
