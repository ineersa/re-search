<?php

declare(strict_types=1);

namespace App\Platform\OpenAi\Gpt;

use Symfony\AI\Platform\Bridge\OpenAi\Gpt;
use Symfony\AI\Platform\Bridge\OpenAi\Gpt\TokenUsageExtractor;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ContentFilterException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ChoiceResult;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingContent;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;

/**
 * OpenAI converter with streaming reasoning summary support.
 *
 * @phpstan-type OutputMessage array{content: array<Refusal|OutputText>, id: string, role: string, type: 'message'}
 * @phpstan-type OutputText array{type: 'output_text', text: string}
 * @phpstan-type Refusal array{type: 'refusal', refusal: string}
 * @phpstan-type FunctionCall array{id: string, arguments: string, call_id: string, name: string, type: 'function_call'}
 * @phpstan-type Reasoning array{summary: array{text?: string}, id: string}
 * @phpstan-type Error array{code?: string|null, type?: string|null, param?: string|null, message?: string|null}
 */
final class ResultConverter implements ResultConverterInterface
{
    private const KEY_OUTPUT = 'output';

    public function supports(Model $model): bool
    {
        return $model instanceof Gpt;
    }

    public function convert(RawResultInterface|RawHttpResult $result, array $options = []): ResultInterface
    {
        $response = $result->getObject();

        if (401 === $response->getStatusCode()) {
            $errorMessage = json_decode($response->getContent(false), true)['error']['message'];
            throw new AuthenticationException($errorMessage);
        }

        if (400 === $response->getStatusCode()) {
            $errorMessage = json_decode($response->getContent(false), true)['error']['message'] ?? 'Bad Request';
            throw new BadRequestException($errorMessage);
        }

        if (429 === $response->getStatusCode()) {
            $headers = $response->getHeaders(false);
            $resetTime = $headers['x-ratelimit-reset-requests'][0]
                ?? $headers['x-ratelimit-reset-tokens'][0]
                ?? null;

            throw new RateLimitExceededException($resetTime ? self::parseResetTime($resetTime) : null);
        }

        if ($options['stream'] ?? false) {
            return new StreamResult($this->convertStream($result));
        }

        $data = $result->getData();

        if (isset($data['error']['code']) && 'content_filter' === $data['error']['code']) {
            throw new ContentFilterException($data['error']['message']);
        }

        if (isset($data['error'])) {
            throw new RuntimeException($this->generateErrorMessage($data['error']));
        }

        if (!isset($data[self::KEY_OUTPUT])) {
            throw new RuntimeException('Response does not contain output.');
        }

        $results = $this->convertOutputArray($data[self::KEY_OUTPUT]);

        return 1 === \count($results) ? array_pop($results) : new ChoiceResult($results);
    }

    public function getTokenUsageExtractor(): TokenUsageExtractor
    {
        return new TokenUsageExtractor();
    }

    /**
     * @param array<OutputMessage|FunctionCall|Reasoning> $output
     *
     * @return ResultInterface[]
     */
    private function convertOutputArray(array $output): array
    {
        [$toolCallResult, $output] = $this->extractFunctionCalls($output);

        $results = array_filter(array_map($this->processOutputItem(...), $output));
        if ($toolCallResult) {
            $results[] = $toolCallResult;
        }

        return $results;
    }

    /**
     * @param OutputMessage|Reasoning $item
     */
    private function processOutputItem(array $item): ?ResultInterface
    {
        $type = $item['type'] ?? null;

        return match ($type) {
            'message' => $this->convertOutputMessage($item),
            'reasoning' => $this->convertReasoning($item),
            default => throw new RuntimeException(sprintf('Unsupported output type "%s".', $type)),
        };
    }

    private function convertStream(RawResultInterface|RawHttpResult $result): \Generator
    {
        $yieldedReasoningDelta = false;

        foreach ($result->getDataStream() as $event) {
            $type = $event['type'] ?? '';

            if ('error' === $type && isset($event['error'])) {
                throw new RuntimeException($this->generateErrorMessage($event['error']));
            }

            if (isset($event['response']['usage'])) {
                yield $this->getTokenUsageExtractor()->fromDataArray($event['response']);
            }

            if (str_contains($type, 'output_text') && isset($event['delta']) && \is_string($event['delta'])) {
                yield $event['delta'];
            }

            $reasoningDelta = $this->extractReasoningDelta($event, $type);
            if (null !== $reasoningDelta) {
                $yieldedReasoningDelta = true;
                yield new ThinkingContent($reasoningDelta);
            }

            if (!str_contains($type, 'completed')) {
                continue;
            }

            $output = $event['response'][self::KEY_OUTPUT] ?? [];

            if (!$yieldedReasoningDelta) {
                foreach ($this->extractReasoningFromOutput($output) as $reasoningText) {
                    yield new ThinkingContent($reasoningText);
                }
            }

            [$toolCallResult] = $this->extractFunctionCalls($output);

            if ($toolCallResult && 'response.completed' === $type) {
                yield $toolCallResult;
            }
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function extractReasoningDelta(array $event, string $type): ?string
    {
        if (!str_contains($type, 'reasoning')) {
            return null;
        }

        if (isset($event['delta']) && \is_string($event['delta']) && '' !== trim($event['delta'])) {
            return $event['delta'];
        }

        if (isset($event['text']) && \is_string($event['text']) && '' !== trim($event['text'])) {
            return $event['text'];
        }

        if (isset($event['summary']['text']) && \is_string($event['summary']['text']) && '' !== trim($event['summary']['text'])) {
            return $event['summary']['text'];
        }

        if (isset($event['item']['summary']['text']) && \is_string($event['item']['summary']['text']) && '' !== trim($event['item']['summary']['text'])) {
            return $event['item']['summary']['text'];
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $output
     *
     * @return list<string>
     */
    private function extractReasoningFromOutput(array $output): array
    {
        $reasoningTexts = [];

        foreach ($output as $item) {
            if ('reasoning' !== ($item['type'] ?? null)) {
                continue;
            }

            $summary = $item['summary']['text'] ?? null;
            if (\is_string($summary) && '' !== trim($summary)) {
                $reasoningTexts[] = $summary;
            }
        }

        return $reasoningTexts;
    }

    /**
     * @param array<OutputMessage|FunctionCall|Reasoning> $output
     *
     * @return list<ToolCallResult|array<OutputMessage|Reasoning>|null>
     */
    private function extractFunctionCalls(array $output): array
    {
        $functionCalls = [];
        foreach ($output as $key => $item) {
            if ('function_call' === ($item['type'] ?? null)) {
                $functionCalls[] = $item;
                unset($output[$key]);
            }
        }

        $toolCallResult = $functionCalls ? new ToolCallResult(
            ...array_map($this->convertFunctionCall(...), $functionCalls)
        ) : null;

        return [$toolCallResult, $output];
    }

    /**
     * @param OutputMessage $output
     */
    private function convertOutputMessage(array $output): ?TextResult
    {
        $content = $output['content'] ?? [];
        if ([] === $content) {
            return null;
        }

        $content = array_pop($content);
        if ('refusal' === $content['type']) {
            return new TextResult(sprintf('Model refused to generate output: %s', $content['refusal']));
        }

        return new TextResult($content['text']);
    }

    /**
     * @param FunctionCall $toolCall
     *
     * @throws \JsonException
     */
    private function convertFunctionCall(array $toolCall): ToolCall
    {
        $arguments = json_decode($toolCall['arguments'], true, flags: \JSON_THROW_ON_ERROR);

        return new ToolCall($toolCall['id'], $toolCall['name'], $arguments);
    }

    private static function parseResetTime(string $resetTime): ?int
    {
        if (preg_match('/^(?:(\d+)m)?(?:(\d+)s)?$/', $resetTime, $matches)) {
            $minutes = isset($matches[1]) ? (int) $matches[1] : 0;
            $secs = isset($matches[2]) ? (int) $matches[2] : 0;

            return ($minutes * 60) + $secs;
        }

        return null;
    }

    /**
     * @param Reasoning $item
     */
    private function convertReasoning(array $item): ?ResultInterface
    {
        $summary = $item['summary']['text'] ?? null;

        return $summary ? new TextResult($summary) : null;
    }

    /**
     * @param Error $error
     */
    private function generateErrorMessage(array $error): string
    {
        return sprintf('Error "%s"-%s (%s): "%s".', $error['code'] ?? '-', $error['type'] ?? '-', $error['param'] ?? '-', $error['message'] ?? '-');
    }
}
