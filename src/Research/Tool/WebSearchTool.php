<?php

declare(strict_types=1);

namespace App\Research\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Web search tool adapter for research runs.
 * Exposes websearch_search, websearch_open, websearch_find for the model.
 *
 * Stub implementation returns placeholder data; MCP-backed client to be wired later.
 *
 * @see .cursor/plans/web_research_flow_5c8ddc68.plan.md
 */
#[AsTool('websearch_search', description: 'Search the web through the MCP web search service', method: 'search')]
#[AsTool('websearch_open', description: 'Open a specific search result through the MCP web search service', method: 'open')]
#[AsTool('websearch_find', description: 'Find text within an opened page through the MCP web search service', method: 'find')]
final class WebSearchTool
{
    public function search(string $query): string
    {
        return \sprintf(
            'Search results for "%s": [Stub - MCP websearch_search to be wired]. Run multiple queries and follow links to verify claims.',
            $query
        );
    }

    /**
     * @param string $url URL of the page to open
     */
    public function open(string $url): string
    {
        return \sprintf(
            'Opened page: %s. [Stub - MCP websearch_open to be wired]. Use websearch_find to extract text.',
            $url
        );
    }

    /**
     * @param string $selector CSS selector or text pattern to find
     * @param string $url      URL of the page (from websearch_open)
     */
    public function find(string $selector, string $url = ''): string
    {
        return \sprintf(
            'Find "%s" on page. [Stub - MCP websearch_find to be wired]. Return cited content with line numbers.',
            $selector
        );
    }
}
