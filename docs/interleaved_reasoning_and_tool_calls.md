# Interleaved Reasoning and Tool Calls

## Overview

When using **Z.AI GLM models** with `tool_stream: true`, the streaming response can contain **three types of deltas interleaved in the same response stream**:

1. `reasoning_content` - Chain of thought from the model
2. `content` - Assistant's actual response text
3. `tool_calls` - Function calls to be executed

This differs from standard OpenAI behavior where these deltas are typically mutually exclusive.

## Problem Statement

### Scenario

With Z.AI's `tool_stream` option enabled, a single streaming response may look like this:

```json
// Delta 1: reasoning starts
{"choices": [{"delta": {"reasoning_content": "I need to search for..."}}}

// Delta 2: reasoning continues
{"choices": [{"delta": {"reasoning_content": "...about 16th president..."}}]

// Delta 3: content appears while reasoning may still continue
{"choices": [{"delta": {"content": "Let me"}}]}

// Delta 4: tool calls appear
{"choices": [{"delta": {"tool_calls": [{"id": "call_123", "function": {"name": "websearch_search"}}]}}

// Delta 5: content continues
{"choices": [{"delta": {"content": "check..."}}]

// Delta 6: tool call arguments stream in
{"choices": [{"delta": {"tool_calls": [{"index": 0, "function": {"arguments": "{\"query\":\"16th\""}}]}}

// Delta 7: final chunk
{"choices": [{"delta": {"content": " now."}, "finish_reason": "tool_calls"}]}
```

### Why Upstream Symfony AI Still Needs a Patch

The upstream `Symfony\AI\Platform\Bridge\Generic\Completions\CompletionsConversionTrait` (0.7 branch) still assumes that a chunk carrying reasoning should short-circuit content handling in the same event:

```php
// From vendor/symfony/ai-platform/src/Bridge/Generic/Completions/CompletionsConversionTrait.php
foreach ($result->getDataStream() as $data) {
    if ($this->streamIsToolCall($data)) {
        yield from $this->yieldToolCallDeltas($toolCalls, $data);
        $toolCalls = $this->convertStreamToToolCalls($toolCalls, $data);
    }

    if ([] !== $toolCalls && $this->isToolCallsStreamFinished($data)) {
        yield new ToolCallComplete(...array_map($this->convertToolCall(...), $toolCalls));
    }

    $reasoningContent = $data['choices'][0]['delta']['reasoning_content']
        ?? $data['choices'][0]['delta']['reasoning'] ?? null;
    if (null !== $reasoningContent && '' !== $reasoningContent) {
        yield new ThinkingDelta($reasoningContent);
        continue; // <- SKIPS content in same delta payload
    }

    if (!isset($data['choices'][0]['delta']['content'])) {
        continue;
    }

    yield new TextDelta($data['choices'][0]['delta']['content']);
}
```

**Issue:** if one event contains reasoning and content together, the `continue` after `ThinkingDelta` drops the content part.

## Our Solution

### Custom ResultConverter

We override the generic result converter in `src/Platform/Generic/Completions/ResultConverter.php`:

```php
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
            // 1. Handle token usage
            if (isset($data['usage']) && \is_array($data['usage'])) {
                yield new TokenUsage(...);
                continue;
            }

            // 2. Handle thinking/reasoning content
            $thinking = $this->extractThinkingDelta($data);
            if (null !== $thinking && '' !== $thinking) {
                $thinkingToolCallBuffer .= $thinking;
                $inlineToolCalls = $this->extractToolCallsFromThinkingBuffer($thinkingToolCallBuffer);
                if ([] !== $inlineToolCalls) {
                    yield new ToolCallComplete(...$inlineToolCalls);
                }
                yield new ThinkingDelta($thinking);
            }

            // 3. Handle tool_calls deltas (accumulate independently)
            if (isset($data['choices'][0]['delta']['tool_calls'])) {
                foreach ($data['choices'][0]['delta']['tool_calls'] as $i => $toolCall) {
                    if (isset($toolCall['id'])) {
                        $toolCalls[$i] = ['id' => $toolCall['id'], 'function' => $toolCall['function']];
                        continue;
                    }
                    $toolCalls[$i]['function']['arguments'] .= $toolCall['function']['arguments'];
                }
            }

            // 4. Handle content deltas (independent of tool_calls)
            if (isset($data['choices'][0]['delta']['content']) && \is_string($data['choices'][0]['delta']['content'])) {
                yield new TextDelta($data['choices'][0]['delta']['content']);
            }
        }

        // 5. After loop, flush any remaining inline tool calls (llama.cpp XML format)
        $inlineToolCalls = $this->extractToolCallsFromThinkingBuffer($thinkingToolCallBuffer, flush: true);
        if ([] !== $inlineToolCalls) {
            yield new ToolCallComplete(...$inlineToolCalls);
        }

        // 6. Yield accumulated tool calls at end (Z.AI format)
        if ([] !== $toolCalls) {
            yield new ToolCallComplete(...array_map([$this, 'convertToolCallFromArray'], $toolCalls));
        }
    }

    /**
     * Extract thinking from multiple possible field names
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

            return $this->normalizeThinkingChunk($delta[$key]);
        }

        return null;
    }
}
```

### Key Changes from Upstream

1. **Typed stream deltas**: The converter now emits `TextDelta`, `ThinkingDelta`, and `ToolCallComplete`
2. **Independent tool call accumulation**: Tool calls accumulated from `delta.tool_calls` are stored separately from inline XML-style calls from thinking
3. **Multi-key thinking support**: Handles `reasoning_content` (Z.AI), `reasoning` (llama.cpp), and other variants
4. **Dual tool call support**: Can return BOTH inline tool calls (from thinking) AND explicit tool calls (from delta)

## Handler Changes

### Updated Result Processing

`ExecuteLlmOperationHandler::normalizePlatformResult()` now returns 4 elements:

```php
/**
 * @return array{0: ResultInterface, 1: ResultInterface, 2: ?string, 3: array<\Symfony\AI\Platform\Result\ToolCall>}
 */
private function normalizePlatformResult(ResultInterface $result, string $runId, int $turnNumber): array
{
    if (!$result instanceof StreamResult) {
        return [$result, $result, null, []];
    }

    $assistantText = '';
    $reasoningBuffer = '';
    $toolCalls = [];

    foreach ($result->getContent() as $delta) {
        if ($delta instanceof TextDelta) {
            $assistantText .= $delta->getText();
        } elseif ($delta instanceof ThinkingComplete) {
            $reasoningBuffer = $delta->getThinking();
        } elseif ($delta instanceof ThinkingDelta) {
            $reasoningBuffer .= $delta->getThinking();
        } elseif ($delta instanceof ToolCallComplete) {
            foreach ($delta->getToolCalls() as $toolCall) {
                $toolCalls[] = $toolCall;
            }
        }
    }

    $reasoningText = '' !== trim($reasoningBuffer) ? trim($reasoningBuffer) : null;

    return [new TextResult($assistantText), $result, $reasoningText, $toolCalls];
}
```

### Updated Encoding

`ExecuteLlmOperationHandler::encodeResultPayload()` now accepts separate tool calls:

```php
private function encodeResultPayload(
    ResultInterface $result,
    ?ResultInterface $metadataSource = null,
    ?string $reasoningText = null,
    array $toolCalls = []  // Separate from result object
): string
{
    $assistantText = '';
    $isFinal = false;

    if ($result instanceof TextResult) {
        $assistantText = $result->getContent();
        $isFinal = '' !== trim($assistantText);
    }

    // Tool calls passed separately from normalizePlatformResult
    $extractedToolCalls = [];
    foreach ($toolCalls as $toolCall) {
        $extractedToolCalls[] = new LlmOperationResultToolCall($toolCall->getName(), $toolCall->getArguments());
    }

    $payload = new LlmOperationResultPayload(
        assistantText: $assistantText,
        toolCalls: $extractedToolCalls,
        isFinal: $isFinal,
        promptTokens: $usage['promptTokens'],
        completionTokens: $usage['completionTokens'],
        totalTokens: $usage['totalTokens'],
        rawMetadata: $this->payloadMapper->extractRawMetadata($usageSource),
        resultClass: $result::class,
        reasoningText: $reasoningText,
    );

    return $this->payloadMapper->encodeJson($payload);
}
```

## Preserved Thinking for Z.AI Models

### Why It's Needed

Z.AI GLM models (GLM-4.7, GLM-5 series) require **reasoning context to be maintained across conversation turns**. Without resending reasoning:

1. Each turn starts "fresh" for the model
2. The model can't build on previous thinking
3. Leads to inefficient or incoherent responses

### How It Works

#### 1. Model Catalog Flag

Z.AI models have `preserve_reasoning_history: true` in `src/Platform/Zai/ModelCatalog.php`:

```php
const MODEL_DEFAULT_OPTIONS = [
    'glm-4.7-flash' => [
        'thinking' => ['type' => 'enabled', 'clear_thinking' => false],
        'tool_stream' => true,
        'preserve_reasoning_history' => true,  // Enables this feature
    ],
    // ... other models
];
```

#### 2. Orchestrator State Storage

`OrchestratorState::appendAssistantMessage()` stores reasoning in message window entries:

```php
public function appendAssistantMessage(string $content, array $toolCalls = [], ?string $reasoningContent = null): void
{
    $entry = [
        'role' => 'assistant',
        'content' => $content,
        'toolCalls' => $toolCalls,
    ];

    if (null !== $reasoningContent && '' !== trim($reasoningContent)) {
        $entry['reasoningContent'] = $reasoningContent;
    }

    $this->messageWindow[] = $entry;
}
```

This reasoning persists to `research_run.orchestrator_state_json` in the database.

#### 3. OrchestratorTurnProcessor Logic

`transitionWaitingLlm()` decides when to preserve reasoning:

```php
// OrchestratorTurnProcessor.php:549-567
$reasoningText = null;
if (\is_string($result->reasoningText) && '' !== trim($result->reasoningText)) {
    $reasoningText = trim($result->reasoningText);
}

$preserveReasoningHistory = $this->shouldPreserveReasoningHistory($request);
$assistantReasoningForHistory = $preserveReasoningHistory ? $reasoningText : null;

// Only add to message window if preserving
if (null !== $assistantReasoningForHistory) {
    $state->appendAssistantMessage($assistantText, $assistantToolCalls, $assistantReasoningForHistory);
}
```

#### 4. Sending to Z.AI API

`AssistantMessageNormalizer` serializes reasoning to JSON:

```json
{
  "role": "assistant",
  "content": "Let me search for that information.",
  "reasoning_content": "The user wants to know who was the 16th president...",
  "tool_calls": [...]
}
```

When `reasoning_content` is present in assistant messages, Z.AI uses it to maintain context across conversation turns.

### Verification

Check if reasoning is being sent back:

```bash
# View message window from database
sqlite3 data/research \
  "SELECT orchestrator_state_json FROM research_run \
   WHERE run_uuid = 'd15482d2-5bbd-4d8f-88f5-ce293246f52c' LIMIT 1;" \
  | python3 -c "import sys, json; mw = json.load(sys.stdin.read()); msgs = mw.get('messageWindow', []); print('Reasoning entries:', [m.get('reasoningContent') for m in msgs if 'assistant' == m.get('role')])"
```

Expected: Non-null `reasoningContent` values for assistant messages when using Z.AI models.

## Impact on Other Platforms

### llama.cpp

**Positive:** This change IMPROVES llama.cpp support, doesn't break it.

Why:
1. Both `content` AND tool calls can now coexist in output
2. XML-style inline tool calls from reasoning (Qwen 3.5) are still extracted
3. If llama.cpp ever implements interleaved deltas (future), this handles it correctly

**No breaking change:** If llama.cpp only outputs text or only outputs tool calls (current behavior), the code path is unchanged.

### OpenAI / Other Providers

**No impact:** These providers don't interleave deltas, so they take the non-streaming path through `parent::convert()`.

## Example Output

### Before Fix

With Z.AI `glm-4.7-flash` with `tool_stream: true`:

```json
{
  "assistantText": "",
  "toolCalls": [],
  "reasoningText": null,
  "isFinal": false
}
```

**Result:** Empty response → retry loop → eventual failure.

### After Fix

Same Z.AI request:

```json
{
  "assistantText": "Let me check that information.",
  "toolCalls": [
    {
      "name": "websearch_search",
      "arguments": {"query": "16th president United States", "topn": 5}
    }
  ],
  "reasoningText": "The user wants to know who was the 16th president... I'll start with a web search.",
  "isFinal": false
}
```

**Result:** All three components captured → tool execution proceeds normally.

## Testing

### Existing Tests

All existing tests pass:

```bash
make test # Runs PHPUnit
# 3 tests in ExecuteLlmOperationHandlerTest - all passing
```

### Manual Verification

To verify the fix works with Z.AI:

```bash
# Run a research query
make console cmd="research:query 'Who was the 16th president?'"

# Check the database for non-empty tool_calls
sqlite3 data/research \
  "SELECT tool_name FROM research_step WHERE type='tool_succeeded' LIMIT 1"
```

Expected: `websearch_search` (not empty/null).

## References

- [Z.AI Deep Thinking Documentation](https://docs.z.ai/guides/capabilities/thinking.md)
- [Z.AI Tool Streaming Documentation](https://docs.z.ai/guides/capabilities/stream-tool.md)
- [Symfony AI Platform Source](vendor/symfony/ai-platform/src/Bridge/Generic/Completions/CompletionsConversionTrait.php)
- [Issue: GLM-4.7 tool calls not working](AGENTS.md - failed run d15482d2-5bbd-4d8f-88f5-ce293246f52c)
