<?php

declare(strict_types=1);

namespace App\Research;

/**
 * Reformats the raw user query into a structured research brief.
 * Injects current date, web-research rules, citation contract, and stop conditions.
 *
 * @see /home/ineersa/.cursor/agents/web-research.md
 */
final class ResearchBriefBuilder
{
    public function __construct(
        private readonly ?\DateTimeImmutable $now = null,
    ) {
    }

    public function build(string $rawQuery): string
    {
        $today = ($this->now ?? new \DateTimeImmutable())->format('Y-m-d');

        return <<<BRIEF
Today: {$today}

Research goal:
- Determine the answer to the user's question using web search tools only.
- Preserve the original user wording; derive a clear research objective from it.

Original user query:
- "{$rawQuery}"

Required behavior (web-research rules):
- Use only MCP websearch tools (websearch_search, websearch_open, websearch_find).
- Run multiple queries, follow relevant links, and verify key claims.
- Every non-trivial factual claim must be cited with URL and line numbers from open output.
- Never invent facts, URLs, quotes, or line references.
- If evidence is missing, return exactly: Nothing found in reviewed sources.
- If verification is impossible from available sources, return exactly: Impossible to verify from available sources.
- Return markdown-formatted output.

Output contract:
- Verify claims from sources.
- Cite every non-trivial factual claim.
- Use markdown for formatting.

Budget and constraints:
- Hard token cap: 75000
- Soft reminder every 10000 tokens.
- Mid-flight budget updates and stopping rules (answer-only mode) will be injected as new User messages.
- When you receive a User message about budget updates or instructing you to stop (answer-only mode), you MUST immediately provide your best final answer based on the evidence gathered so far and STOP making tool calls. Do not explain the budget exhaustion, just answer the question.
BRIEF;
    }
}
