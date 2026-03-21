<?php

declare(strict_types=1);

namespace App\Research\Message\Orchestrator\Dto;

final readonly class OrchestratorUnexpectedFailurePayload
{
    public function __construct(
        public string $error,
    ) {
    }
}
