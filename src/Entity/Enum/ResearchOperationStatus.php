<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ResearchOperationStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';

    public function isTerminal(): bool
    {
        return self::SUCCEEDED === $this || self::FAILED === $this;
    }
}
