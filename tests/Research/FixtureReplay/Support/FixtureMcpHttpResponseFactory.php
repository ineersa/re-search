<?php

declare(strict_types=1);

namespace App\Tests\Research\FixtureReplay\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class FixtureMcpHttpResponseFactory
{
    public function __construct(
        private readonly TraceFixtureRuntime $runtime,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        if ('DELETE' === strtoupper($method)) {
            return new MockResponse('', ['http_code' => 204]);
        }

        $body = $options['body'] ?? null;
        $payload = [];
        if (is_string($body) && '' !== trim($body)) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $rpcMethod = $payload['method'] ?? null;

        if ('initialize' === $rpcMethod) {
            return $this->initializeResponse($payload);
        }

        if ('notifications/initialized' === $rpcMethod) {
            return new MockResponse('{"jsonrpc":"2.0","result":{}}', ['http_code' => 202]);
        }

        if ('tools/call' === $rpcMethod) {
            return $this->toolCallResponse($payload);
        }

        return new MockResponse('{"jsonrpc":"2.0","result":{}}', ['http_code' => 200]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function initializeResponse(array $payload): ResponseInterface
    {
        $id = $payload['id'] ?? 1;

        $responseBody = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => new \stdClass(),
                'serverInfo' => ['name' => 'fixture-mcp', 'version' => '1.0.0'],
            ],
        ];

        return new MockResponse(
            json_encode($responseBody, \JSON_THROW_ON_ERROR),
            [
                'http_code' => 200,
                'response_headers' => ['Mcp-Session-Id: fixture-session'],
            ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function toolCallResponse(array $payload): ResponseInterface
    {
        $params = $payload['params'] ?? null;
        $toolName = is_array($params) && is_string($params['name'] ?? null) ? $params['name'] : 'search';
        $arguments = is_array($params) && is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $toolResult = $this->runtime->consumeToolResult($toolName, $arguments);

        $responseBody = [
            'jsonrpc' => '2.0',
            'id' => $payload['id'] ?? 1,
            'result' => [
                'content' => [
                    ['type' => 'text', 'text' => $toolResult['text']],
                ],
                'isError' => $toolResult['isError'],
            ],
        ];

        return new MockResponse(json_encode($responseBody, \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }
}
