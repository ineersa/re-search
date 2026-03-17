<?php

declare(strict_types=1);

namespace App\Research;

/**
 * Builds the structured research brief (system message) from the raw user query.
 * Injects current date, output contract, citation rules, and stop conditions.
 */
interface ResearchBriefBuilderInterface
{
    /**
     * Build a structured research brief for the given raw user query.
     */
    public function build(string $rawQuery): string;
}
