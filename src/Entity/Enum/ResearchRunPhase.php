<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ResearchRunPhase: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case WAITING_LLM = 'waiting_llm';
    case WAITING_TOOLS = 'waiting_tools';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case ABORTED = 'aborted';
}
