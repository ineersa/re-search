<?php

declare(strict_types=1);

namespace App\Platform\Generic\Completions;

use Symfony\AI\Platform\Bridge\Generic\Completions\ResultConverter as BaseResultConverter;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

final class ResultConverter extends BaseResultConverter
{
    public function convert(RawResultInterface|RawHttpResult $result, array $options = []): \Symfony\AI\Platform\Result\ResultInterface
    {
        if ($options['stream'] ?? false) {
            return new StreamResult($this->convertStreamWithUsage($result));
        }

        return parent::convert($result, $options);
    }

    private function convertStreamWithUsage(RawResultInterface|RawHttpResult $result): \Generator
    {
        $toolCalls = [];

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

            if (
                [] !== $toolCalls
                && isset($data['choices'][0]['finish_reason'])
                && 'tool_calls' === $data['choices'][0]['finish_reason']
            ) {
                yield new ToolCallResult(...array_map([$this, 'convertToolCallFromArray'], $toolCalls));
            }

            if (!isset($data['choices'][0]['delta']['content'])) {
                continue;
            }

            yield $data['choices'][0]['delta']['content'];
        }
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
}
