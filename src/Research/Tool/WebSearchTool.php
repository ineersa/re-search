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
#[AsTool('websearch_search', description: 'Search the web through the MCP web search service', method: 'search')]
#[AsTool('websearch_open', description: 'Open a specific search result through the MCP web search service', method: 'open')]
#[AsTool('websearch_find', description: 'Find text within an opened page through the MCP web search service', method: 'find')]
final class WebSearchTool
{
    public function __construct(
        private readonly McpWebSearchClient $mcpClient,
    ) {
    }

    public function search(string $query, int $topn = 5): string
    {
        $result = $this->mcpClient->callTool('search', ['query' => $query, 'topn' => $topn]);

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

        $result = $this->mcpClient->callTool('find', [
            'url' => $url,
            'query' => $selector,
            'match' => $match,
            'context_lines' => $contextLines,
        ]);

        return $result['text'];
    }
}
