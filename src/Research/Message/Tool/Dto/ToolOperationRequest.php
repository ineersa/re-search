<?php

declare(strict_types=1);

namespace App\Research\Message\Tool\Dto;

final readonly class ToolOperationRequest
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public ?string $callId = null,
        public ?string $name = null,
        public ?string $toolName = null,
        public array $arguments = [],
        public ?string $normalizedSignature = null,
    ) {
    }
}
