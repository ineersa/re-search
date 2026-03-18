<?php

declare(strict_types=1);

namespace App\Research\Mcp;

use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Implementation;
use Mcp\Schema\Notification\InitializedNotification;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Stateful MCP client for the web search server.
 * Connects via Streamable HTTP (POST to SSE endpoint), maintains session, calls tools.
 *
 * @see https://modelcontextprotocol.io/docs/concepts/transports
 * @see https://github.com/modelcontextprotocol/php-sdk
 */
final class McpWebSearchClient
{
    private const PROTOCOL_VERSION = '2025-03-26';
    private const MCP_PROTOCOL_HEADER = 'MCP-Protocol-Version';
    private const SESSION_HEADER = 'Mcp-Session-Id';

    private ?string $sessionId = null;
    private int $requestId = 0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * Ensure connection is initialized. Call before any tool call.
     */
    public function connect(): void
    {
        if (null !== $this->sessionId) {
            return;
        }

        $initRequest = new InitializeRequest(
            self::PROTOCOL_VERSION,
            new ClientCapabilities(),
            new Implementation('re-search', '1.0', 'Research web search client')
        );
        $initRequest = $initRequest->withId(++$this->requestId);

        $response = $this->post($initRequest);
        $sessionHeader = $response->getHeaders(false)[self::SESSION_HEADER] ?? null;
        if (isset($sessionHeader[0])) {
            $this->sessionId = $sessionHeader[0];
        }

        $notification = new InitializedNotification();
        $this->postNotification($notification);
    }

    /**
     * Call MCP tool by name with arguments.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{text: string, isError: bool}
     */
    public function callTool(string $name, array $arguments): array
    {
        $this->connect();

        $request = new CallToolRequest($name, $arguments);
        $request = $request->withId(++$this->requestId);

        try {
            $response = $this->post($request);
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                return [
                    'text' => \sprintf('MCP server error: HTTP %d', $statusCode),
                    'isError' => true,
                ];
            }

            $body = $response->toArray(false);
            if (isset($body['error'])) {
                $msg = $body['error']['message'] ?? 'Unknown MCP error';

                return ['text' => (string) $msg, 'isError' => true];
            }

            $result = $body['result'] ?? null;
            if (!\is_array($result)) {
                return [
                    'text' => 'Invalid MCP response: missing or invalid result',
                    'isError' => true,
                ];
            }

            $callResult = CallToolResult::fromArray($result);
            $textParts = [];
            foreach ($callResult->content as $content) {
                if ($content instanceof \Mcp\Schema\Content\TextContent) {
                    $textParts[] = (string) $content->text;
                }
            }

            return [
                'text' => implode("\n\n", $textParts),
                'isError' => $callResult->isError,
            ];
        } catch (\Throwable $e) {
            return [
                'text' => \sprintf('Web search error: %s', $e->getMessage()),
                'isError' => true,
            ];
        }
    }

    /**
     * Disconnect and clear session. Call when done with a research run.
     */
    public function disconnect(): void
    {
        if (null === $this->sessionId) {
            return;
        }

        try {
            $this->httpClient->request('DELETE', $this->baseUrl, [
                'headers' => $this->headers(),
            ]);
        } catch (\Throwable) {
            // Ignore disconnect errors
        } finally {
            $this->sessionId = null;
        }
    }

    /**
     * @param \JsonSerializable $message
     */
    private function post($message): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $body = json_encode($message, \JSON_THROW_ON_ERROR);

        return $this->httpClient->request('POST', $this->baseUrl, [
            'headers' => array_merge($this->headers(), [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
            ]),
            'body' => $body,
        ]);
    }

    /**
     * @param \JsonSerializable $notification
     */
    private function postNotification($notification): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $body = json_encode($notification, \JSON_THROW_ON_ERROR);

        return $this->httpClient->request('POST', $this->baseUrl, [
            'headers' => array_merge($this->headers(), [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
            ]),
            'body' => $body,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $h = [
            self::MCP_PROTOCOL_HEADER => self::PROTOCOL_VERSION,
        ];
        if (null !== $this->sessionId) {
            $h[self::SESSION_HEADER] = $this->sessionId;
        }

        return $h;
    }
}
