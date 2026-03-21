<?php

declare(strict_types=1);

namespace App\Research\Message\Tool\Dto;

final readonly class ToolOperationErrorPayload
{
    public function __construct(
        public string $errorClass,
        public string $errorMessage,
    ) {
    }
}
