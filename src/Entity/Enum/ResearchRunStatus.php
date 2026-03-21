<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ResearchRunStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case ABORTED = 'aborted';
    case TIMED_OUT = 'timed_out';
    case LOOP_STOPPED = 'loop_stopped';
    case BUDGET_EXHAUSTED = 'budget_exhausted';
    case THROTTLED = 'throttled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED,
            self::FAILED,
            self::ABORTED,
            self::TIMED_OUT,
            self::LOOP_STOPPED,
            self::BUDGET_EXHAUSTED,
            self::THROTTLED => true,
            self::QUEUED,
            self::RUNNING => false,
        };
    }
}
