<?php

declare(strict_types=1);

namespace App\Research;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\TextResult;

final class ResearchTaskPromptBuilder
{
    public function __construct(
        private readonly ?PlatformInterface $platform = null,
        private readonly ?string $model = null,
    ) {
    }

    public function build(string $rawQuery): string
    {
        if (null === $this->platform || null === $this->model || '' === trim($this->model)) {
            return $this->fallback($rawQuery);
        }

        $messages = new MessageBag(
            Message::forSystem(<<<SYSTEM
You rewrite raw user queries into high-signal web-research task briefs for a subagent.
Return markdown only.
SYSTEM),
            Message::ofUser(<<<INPUT
Rewrite the user query into a concrete, execution-ready web-research task for another assistant.

Output format:
- `# Web Research Task`
- `## Original User Query` (copy the user query verbatim)
- `## Research Checklist` (numbered, length based on complexity)
- `## Expected Deliverables` (bullets)

Requirements:
1. Preserve all technical details from the user query (frameworks, APIs, URLs, env vars, current vs new platform/provider names, constraints).
2. In `## Original User Query`, copy the user query exactly (no paraphrasing, no omissions).
3. Checklist length must match complexity:
   - simple lookup/fact tasks: 1-3 items
   - medium tasks: 4-6 items
   - complex technical/comparative/debugging/integration tasks: 6-10 items
4. Checklist items must be query-specific and include any important sub-questions needed to produce a complete answer.
5. Add deliverables that define what the final answer must include (findings, references, implementation notes, risks/unknowns).
6. Require direct official documentation URLs and citation line numbers for non-trivial factual claims.
7. Keep it concise but specific (avoid generic filler language).
8. Do not answer the research question itself; only produce the research task brief.

User query:
{$rawQuery}
INPUT)
        );

        try {
            $result = $this->platform->invoke($this->model, $messages, ['stream' => false])->getResult();
            if ($result instanceof TextResult && '' !== trim($result->getContent())) {
                return trim($result->getContent());
            }
        } catch (\Throwable) {
        }

        return $this->fallback($rawQuery);
    }

    private function fallback(string $rawQuery): string
    {
        return <<<PROMPT
User asks:
{$rawQuery}

PROMPT;
    }
}
