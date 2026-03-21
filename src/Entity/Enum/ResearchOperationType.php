<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ResearchOperationType: string
{
    case LLM_CALL = 'llm_call';
    case TOOL_CALL = 'tool_call';
}
