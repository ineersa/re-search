<?php

declare(strict_types=1);

namespace App\Research\Message\Llm;

final readonly class ExecuteLlmOperation
{
    public function __construct(
        public int $operationId,
    ) {
    }
}
