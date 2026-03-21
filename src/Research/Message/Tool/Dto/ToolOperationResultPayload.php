<?php

declare(strict_types=1);

namespace App\Research\Message\Tool\Dto;

final readonly class ToolOperationResultPayload
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $callId,
        public string $name,
        public array $arguments,
        public string $result,
    ) {
    }
}
