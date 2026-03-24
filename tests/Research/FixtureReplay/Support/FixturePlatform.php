<?php

declare(strict_types=1);

namespace App\Tests\Research\FixtureReplay\Support;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

final class FixturePlatform implements PlatformInterface
{
    private readonly ModelCatalogInterface $modelCatalog;

    public function __construct(
        private readonly TraceFixtureRuntime $runtime,
    ) {
        $this->modelCatalog = new class implements ModelCatalogInterface {
            public function getModel(string $modelName): Model
            {
                return new Model('' !== trim($modelName) ? $modelName : 'fixture-model');
            }

            /**
             * @return array<string, array{class: string, capabilities: list<\Symfony\AI\Platform\Capability>}>
             */
            public function getModels(): array
            {
                return [];
            }
        };
    }

    public function invoke(string $model, array|string|object $input, array $options = []): DeferredResult
    {
        if ($this->isTaskPromptInvocation($input, $options)) {
            return $this->deferredResult(new TextResult($this->runtime->taskPromptSummary()));
        }

        if (!$input instanceof MessageBag) {
            return $this->deferredResult(new TextResult('Unsupported fixture input type.'));
        }

        $messages = $input->getMessages();
        $messageCount = \count($messages);
        $lastRole = $this->extractLastRole($messages);
        $allowTools = array_key_exists('tools', $options);

        $llmResult = $this->runtime->consumeLlmResult($model, $messageCount, $lastRole, $allowTools);
        $toolCalls = $llmResult['toolCalls'];

        if ([] !== $toolCalls) {
            $calls = [];
            foreach ($toolCalls as $toolCall) {
                $calls[] = new ToolCall($toolCall['callId'], $toolCall['name'], $toolCall['arguments']);
            }

            $result = new ToolCallResult(...$calls);
        } else {
            $result = new TextResult($llmResult['assistantText']);
        }

        $promptTokens = $llmResult['promptTokens'];
        $completionTokens = $llmResult['completionTokens'];
        $totalTokens = $llmResult['totalTokens'];

        if (null !== $promptTokens || null !== $completionTokens || null !== $totalTokens) {
            $result->getMetadata()->add('token_usage', new TokenUsage(
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                totalTokens: $totalTokens,
            ));
        }

        return $this->deferredResult($result);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->modelCatalog;
    }

    /**
     * @param array<mixed>|string|object $input
     * @param array<string, mixed> $options
     */
    private function isTaskPromptInvocation(array|string|object $input, array $options): bool
    {
        if (!$input instanceof MessageBag) {
            return false;
        }

        if (false !== ($options['stream'] ?? null)) {
            return false;
        }

        $system = $input->getSystemMessage();
        if (null === $system) {
            return false;
        }

        $content = $system->getContent();
        if (!is_string($content)) {
            return false;
        }

        return str_contains($content, 'You rewrite raw user queries');
    }

    /**
     * @param list<MessageInterface> $messages
     */
    private function extractLastRole(array $messages): ?string
    {
        if ([] === $messages) {
            return null;
        }

        $last = $messages[array_key_last($messages)] ?? null;
        if (!$last instanceof MessageInterface) {
            return null;
        }

        return $last->getRole()->value;
    }

    private function deferredResult(ResultInterface $result): DeferredResult
    {
        $converter = new class($result) implements ResultConverterInterface {
            public function __construct(
                private readonly ResultInterface $result,
            ) {
            }

            public function supports(Model $model): bool
            {
                return true;
            }

            public function convert(RawResultInterface $result, array $options = []): ResultInterface
            {
                return $this->result;
            }

            public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
            {
                return null;
            }
        };

        $rawResult = new class implements RawResultInterface {
            /**
             * @return array<string, mixed>
             */
            public function getData(): array
            {
                return [];
            }

            /**
             * @return iterable<array<string, mixed>>
             */
            public function getDataStream(): iterable
            {
                return [];
            }

            public function getObject(): object
            {
                return new \stdClass();
            }
        };

        return new DeferredResult($converter, $rawResult);
    }
}
