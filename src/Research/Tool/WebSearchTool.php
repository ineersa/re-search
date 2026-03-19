<?php

declare(strict_types=1);

namespace App\Research\Tool;

use App\Research\Mcp\McpWebSearchClient;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Web search tool adapter for research runs.
 * Exposes websearch_search, websearch_open, websearch_find for the model.
 * Delegates to MCP web search server via stateful McpWebSearchClient.
 *
 * @see .cursor/plans/web_research_flow_5c8ddc68.plan.md
 */
#[AsTool('websearch_search', description: 'Runs web search for `query` and returns ranked results with canonical URLs and short summaries. Use for discovery before deep reading. Query tips: include specific nouns, version numbers, product names; avoid very long prompts. `topn` (default 5) controls recall: 3-5 for focused lookup, 8-10 when coverage matters. After choosing a URL, use websearch_open then websearch_find to verify exact phrases.', method: 'search')]
#[AsTool('websearch_open', description: 'Loads `url` and returns a window of page text with numbered lines (L{n}). Omit `startAtLine` for auto mode — anchors near search snippets for the same URL. Use `fetchAll: true` only when you need the full page; otherwise omit it to get a focused window. Auto windows are ~100 lines for scanning context.', method: 'open')]
#[AsTool('websearch_find', description: 'Find text in a page at `url` using `query`. Use after websearch_open on the same URL. `match`: contains (default, flexible) or exact (strict, for verifying literal text). `context_lines` (default 5) controls chunk size around hits. Both `url` and `query` are required.', method: 'find')]
final class WebSearchTool
{
    public function __construct(
        private readonly McpWebSearchClient $mcpClient,
    ) {
    }

    public function search(string $query, int $topn = 5): string
    {
        $args = ['query' => $query, 'topn' => $topn];
        $result = $this->mcpClient->callTool('search', $args);

        return $result['text'];
    }

    /**
     * @param string $url URL of the page to open
     */
    public function open(string $url, ?int $startAtLine = null, int $numberOfLines = 50, bool $fetchAll = false): string
    {
        $args = ['url' => $url];
        if (null !== $startAtLine) {
            $args['startAtLine'] = $startAtLine;
        }
        $args['numberOfLines'] = $numberOfLines;
        $args['fetchAll'] = $fetchAll;

        $result = $this->mcpClient->callTool('open', $args);

        return $result['text'];
    }

    /**
     * @param string $selector Text or pattern to find (maps to MCP query)
     * @param string $url      URL of the page (from websearch_open)
     */
    public function find(string $selector, string $url = '', string $match = 'contains', int $contextLines = 5): string
    {
        if ('' === $url) {
            return 'Error: URL is required for websearch_find. Use websearch_open first to get page content.';
        }

        $args = [
            'url' => $url,
            'query' => $selector,
            'match' => $match,
            'context_lines' => $contextLines,
        ];

        $result = $this->mcpClient->callTool('find', $args);

        return $result['text'];
    }
}
