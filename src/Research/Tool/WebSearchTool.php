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
    private bool $recording = false;
    private bool $mocking = false;
    private string $fixtureFile = '';
    private array $fixtures = [];

    public function __construct(
        private readonly McpWebSearchClient $mcpClient,
    ) {
    }

    public function enableRecording(string $file): void
    {
        $this->recording = true;
        $this->fixtureFile = $file;
        $this->fixtures = file_exists($file) ? json_decode(file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR) : [];
    }

    public function enableMocking(string $file): void
    {
        $this->mocking = true;
        $this->fixtureFile = $file;
        $this->fixtures = file_exists($file) ? json_decode(file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR) : [];
    }

    public function search(string $query, int $topn = 5): string
    {
        $args = ['query' => $query, 'topn' => $topn];
        
        if ($this->mocking) {
            return $this->getMockResponse('search', $args);
        }

        $result = $this->mcpClient->callTool('search', $args);

        if ($this->recording) {
            $this->recordResponse('search', $args, $result['text']);
        }

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

        if ($this->mocking) {
            return $this->getMockResponse('open', $args);
        }

        $result = $this->mcpClient->callTool('open', $args);

        if ($this->recording) {
            $this->recordResponse('open', $args, $result['text']);
        }

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

        if ($this->mocking) {
            return $this->getMockResponse('find', $args);
        }

        $result = $this->mcpClient->callTool('find', $args);

        if ($this->recording) {
            $this->recordResponse('find', $args, $result['text']);
        }

        return $result['text'];
    }

    private function getMockResponse(string $tool, array $args): string
    {
        $key = $this->getFixtureKey($tool, $args);
        if (isset($this->fixtures[$key])) {
            return $this->fixtures[$key];
        }
        return sprintf('Mock response not found for tool "%s" with args: %s', $tool, json_encode($args));
    }

    private function recordResponse(string $tool, array $args, string $response): void
    {
        $key = $this->getFixtureKey($tool, $args);
        $this->fixtures[$key] = $response;
        file_put_contents($this->fixtureFile, json_encode($this->fixtures, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    private function getFixtureKey(string $tool, array $args): string
    {
        ksort($args);
        return sprintf('%s:%s', $tool, md5(json_encode($args)));
    }
}
