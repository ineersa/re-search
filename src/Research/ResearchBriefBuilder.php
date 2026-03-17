<?php

declare(strict_types=1);

namespace App\Research;

/**
 * Stub implementation of brief building.
 * Returns a minimal placeholder brief; real formatting reserved for orchestration phase.
 */
final class ResearchBriefBuilder implements ResearchBriefBuilderInterface
{
    public function build(string $rawQuery): string
    {
        return sprintf(
            "Research goal:\n- %s\n\nRequired behavior:\n- verify claims from sources\n- cite every non-trivial factual claim\n- return markdown",
            $rawQuery
        );
    }
}
