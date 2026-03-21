<?php

declare(strict_types=1);

namespace App\Research\Message\Tool;

final readonly class ExecuteToolOperation
{
    public function __construct(
        public int $operationId,
    ) {
    }
}
