<?php

declare(strict_types=1);

namespace App\Research\Orchestration\Dto;

final readonly class BudgetState
{
    public function __construct(
        public int $hardCapTokens,
        public int $usedTokens,
        public int $remainingTokens,
        public int $nextNoticeAt,
        public bool $answerOnly,
    ) {
    }
}
