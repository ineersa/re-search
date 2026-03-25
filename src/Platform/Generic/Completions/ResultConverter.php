<?php

declare(strict_types=1);

namespace App\Platform\Generic\Completions;

use Symfony\AI\Platform\Bridge\Generic\Completions\ResultConverter as BaseResultConverter;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\ThinkingContent;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

final class ResultConverter extends BaseResultConverter
{
    public function convert(RawResultInterface|RawHttpResult $result, array $options = []): \Symfony\AI\Platform\Result\ResultInterface
    {
        $stream = $options['stream'] ?? false;
        if (\is_bool($stream) && $stream) {
            return new StreamResult($this->convertStreamWithUsage($result));
        }

        return parent::convert($result, $options);
    }

    private function convertStreamWithUsage(RawResultInterface|RawHttpResult $result): \Generator
    {
        $toolCalls = [];
        $thinkingToolCallBuffer = '';

        foreach ($result->getDataStream() as $data) {
            if (isset($data['usage']) && \is_array($data['usage'])) {
                yield new TokenUsage(
                    promptTokens: $data['usage']['prompt_tokens'] ?? null,
                    completionTokens: $data['usage']['completion_tokens'] ?? null,
                    cachedTokens: $data['usage']['num_cached_tokens'] ?? null,
                    totalTokens: $data['usage']['total_tokens'] ?? null,
                );

                continue;
            }

            $thinking = $this->extractThinkingDelta($data);
            if (null !== $thinking && '' !== $thinking) {
                $thinkingToolCallBuffer .= $thinking;
                $inlineToolCalls = $this->extractToolCallsFromThinkingBuffer($thinkingToolCallBuffer);
                if ([] !== $inlineToolCalls) {
                    yield new ToolCallResult(...$inlineToolCalls);
                }

                yield new ThinkingContent($thinking);
            }

            if (isset($data['choices'][0]['delta']['tool_calls'])) {
                foreach ($data['choices'][0]['delta']['tool_calls'] as $i => $toolCall) {
                    if (isset($toolCall['id'])) {
                        $toolCalls[$i] = [
                            'id' => $toolCall['id'],
                            'function' => $toolCall['function'],
                        ];

                        continue;
                    }

                    $toolCalls[$i]['function']['arguments'] .= $toolCall['function']['arguments'];
                }
            }

            if (isset($data['choices'][0]['delta']['content'])) {
                yield $data['choices'][0]['delta']['content'];
            }
        }

        $inlineToolCalls = $this->extractToolCallsFromThinkingBuffer($thinkingToolCallBuffer, flush: true);
        if ([] !== $inlineToolCalls) {
            yield new ToolCallResult(...$inlineToolCalls);
        }

        if ([] !== $toolCalls) {
            yield new ToolCallResult(...array_map([$this, 'convertToolCallFromArray'], $toolCalls));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractThinkingDelta(array $data): ?string
    {
        $delta = $data['choices'][0]['delta'] ?? null;
        if (!\is_array($delta)) {
            return null;
        }

        foreach (['reasoning_content', 'reasoning', 'thinking_content', 'thinking'] as $key) {
            if (!array_key_exists($key, $delta)) {
                continue;
            }

            $normalized = $this->normalizeThinkingChunk($delta[$key]);
            if (null !== $normalized && '' !== $normalized) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeThinkingChunk(mixed $value): ?string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (!\is_array($value)) {
            return null;
        }

        if (isset($value['content']) && \is_string($value['content'])) {
            return $value['content'];
        }

        $parts = [];
        foreach ($value as $item) {
            if (\is_string($item)) {
                $parts[] = $item;

                continue;
            }

            if (!\is_array($item)) {
                continue;
            }

            if (isset($item['text']) && \is_string($item['text'])) {
                $parts[] = $item['text'];

                continue;
            }

            if (isset($item['content']) && \is_string($item['content'])) {
                $parts[] = $item['content'];
            }
        }

        return [] === $parts ? null : implode('', $parts);
    }

    /**
     * @param array{
     *     id: string,
     *     type?: 'function',
     *     function: array{
     *         name: string,
     *         arguments: string
     *     }
     * } $toolCall
     */
    private function convertToolCallFromArray(array $toolCall): \Symfony\AI\Platform\Result\ToolCall
    {
        $arguments = json_decode($toolCall['function']['arguments'], true, flags: \JSON_THROW_ON_ERROR);

        return new \Symfony\AI\Platform\Result\ToolCall($toolCall['id'], $toolCall['function']['name'], $arguments);
    }

    /**
     * Parse llama.cpp-style XML-ish tool calls emitted in reasoning text.
     *
     * @return list<ToolCall>
     */
    private function extractToolCallsFromThinkingBuffer(string &$buffer, bool $flush = false): array
    {
        $pattern = '/<tool_call>\s*<function=(?<name>[a-zA-Z0-9_.:-]+)>\s*(?<params>.*?)\s*<\/function>\s*<\/tool_call>/s';
        if (!preg_match_all($pattern, $buffer, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)) {
            if ($flush) {
                $buffer = '';
            } elseif (\strlen($buffer) > 8192) {
                $buffer = substr($buffer, -4096);
            }

            return [];
        }

        $toolCalls = [];
        $lastConsumedOffset = 0;
        foreach ($matches as $match) {
            $fullMatch = $match[0][0];
            $startOffset = $match[0][1];
            $name = $match['name'][0];
            $paramsBody = $match['params'][0];

            $arguments = [];
            if (preg_match_all('/<parameter=(?<key>[a-zA-Z0-9_.:-]+)>\s*(?<value>.*?)\s*<\/parameter>/s', $paramsBody, $paramMatches, \PREG_SET_ORDER)) {
                foreach ($paramMatches as $paramMatch) {
                    $key = $paramMatch['key'];
                    $value = $paramMatch['value'];
                    $decoded = html_entity_decode(trim($value), \ENT_QUOTES | \ENT_HTML5);

                    $arguments[$key] = $this->coerceXmlToolParameterValue($decoded);
                }
            }

            $toolCalls[] = new ToolCall('reasoning_'.sha1($name.json_encode($arguments)), $name, $arguments);
            $lastConsumedOffset = max($lastConsumedOffset, $startOffset + \strlen($fullMatch));
        }

        if ($lastConsumedOffset > 0) {
            $buffer = substr($buffer, $lastConsumedOffset);
        } elseif ($flush) {
            $buffer = '';
        }

        if (!$flush && \strlen($buffer) > 8192) {
            $buffer = substr($buffer, -4096);
        }

        return $toolCalls;
    }

    /**
     * Qwen / llama.cpp Jinja templates stringify tool arguments; Symfony tools expect typed values.
     *
     * @see https://huggingface.co/Qwen/Qwen3-Coder-30B-A3B-Instruct/blob/main/qwen3coder_tool_parser.py (_convert_param_value)
     */
    private function coerceXmlToolParameterValue(string $value): mixed
    {
        if ('' === $value) {
            return $value;
        }

        $lower = strtolower($value);
        if ('true' === $lower) {
            return true;
        }
        if ('false' === $lower) {
            return false;
        }
        if ('null' === $lower || 'none' === $lower) {
            return null;
        }

        if (1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        $first = $value[0];
        if ('{' === $first || '[' === $first) {
            try {
                $decoded = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
                if (\is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
                // keep string
            }
        }

        return $value;
    }
}
