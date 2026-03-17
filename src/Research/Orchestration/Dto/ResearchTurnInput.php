<?php

declare(strict_types=1);

namespace App\Research\Orchestration\Dto;

final readonly class ResearchTurnInput
{
    /**
     * @param list<object> $messages
     */
    public function __construct(
        public string $runId,
        public int $turnNumber,
        public array $messages,
        public BudgetState $budget,
        public bool $answerOnly,
        public ?string $forcedInstruction,
    ) {
    }
}
