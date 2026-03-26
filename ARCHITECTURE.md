# Architecture

This document describes the current **re-search** architecture.

The implementation is event-driven and DB-backed: the orchestrator advances a run in short ticks, while dedicated workers perform LLM and tool IO. The orchestration logic is split into focused services (transition routing, turn processing, operation/payload mapping, state management, and step recording) to keep each handler small and deterministic.

## Research Workflow Overview

The web research flow uses Symfony Messenger queues plus Mercure event streaming.

```mermaid
sequenceDiagram
    participant B as Browser (Stimulus)
    participant C as ResearchController
    participant T as ResearchThrottle
    participant DB as SQLite/Doctrine
    participant OQ as orchestrator queue
    participant OTH as OrchestratorTickHandler
    participant OTS as OrchestratorTransitionService
    participant OTP as OrchestratorTurnProcessor
    participant LQ as llm queue
    participant LH as ExecuteLlmOperationHandler
    participant TQ as tool queue
    participant TH as ExecuteToolOperationHandler
    participant AI as Symfony AI Platform
    participant MCP as MCP Websearch
    participant M as Mercure

    B->>C: POST /research/runs
    C->>T: consumeOnSubmit() [anonymous only]
    alt rate limited
        T-->>C: limit exceeded
        C-->>B: 429 + {status=throttled,retryAfter} (no runId)
    else accepted
        T-->>C: accepted
        C->>DB: create ResearchRun (queued)
        C->>OQ: OrchestratorTick(runId)
        C-->>B: 202 + runId + topic
    end

    loop Until terminal status
        OQ->>OTH: OrchestratorTick(runId)
        OTH->>OTS: transition(run, state)
        OTS->>OTP: route waiting phases

        alt dispatch_llm
            OTP->>DB: upsert llm operation
            OTP->>LQ: ExecuteLlmOperation(opId)
            LQ->>LH: message
            LH->>AI: invoke(model, messages)
            LH->>DB: persist operation result
            LH->>OQ: OrchestratorTick(runId)
        else dispatch_tools
            OTP->>DB: upsert tool operation(s)
            OTP->>TQ: ExecuteToolOperation(opId...)
            TQ->>TH: message(s)
            TH->>MCP: execute websearch tool
            TH->>DB: persist operation result
            TH->>OQ: OrchestratorTick(runId)
        else terminal (final answer)
            OTP->>DB: persist final answer + status
            alt terminal is failed/aborted/timed_out/loop_stopped
                OTP->>T: refundByClientKey(clientKey) [anonymous only]
            end
        end

        OTP->>M: publish activity/answer/budget/phase/complete
        M-->>B: Mercure events
    end
```

## Queue Topology

- `orchestrator`: handles `OrchestratorTick` state transitions.
- `llm`: handles `ExecuteLlmOperation` model calls.
- `tool`: handles `ExecuteToolOperation` tool executions.

All handlers are intentionally short and finite. Long work is split across multiple messages.

## AI platform integration

Model calls go through Symfony AI’s `PlatformInterface`, resolved by **`App\Research\Platform\ResearchPlatformFactory`** from the **`AI_PLATFORM`** environment variable (`llama` \| `generic` \| `zai`). The active model id comes from **`RESEARCH_MODEL`**.

| Platform | Service | Typical env vars | Notes |
| :--- | :--- | :--- | :--- |
| **llama** | `App\Platform\LlamaCpp\PlatformFactory` | `LLAMACPP_BASE_URL`, `LLAMACPP_API_KEY` | Local [llama.cpp](https://github.com/ggerganov/llama.cpp) server; uses the generic completions stack with a custom **`Contract`** / **`ToolCallNormalizer`** for tool-argument JSON shape. |
| **generic** | `App\Platform\Generic\PlatformFactory` | `GENERIC_BASE_URL` | OpenAI-compatible HTTP API (`/v1/chat/completions`). |
| **zai** | `App\Platform\Zai\PlatformFactory` | `ZAI_BASE_URL` (default `https://api.z.ai/api/paas/v4/`), `ZAI_API_KEY` | Z.AI cloud; uses the same generic completions stack with Z.AI’s **`/chat/completions`** path, **`EventSourceHttpClient`** for streaming, and **`App\Platform\Zai\ModelCatalog`** for supported GLM models and capability flags. |

All three platforms use the shared **`App\Platform\Generic\Completions\ModelClient`** and **`App\Platform\Generic\Completions\ResultConverter`**. That converter normalizes streaming deltas (including tool arguments and optional “thinking” / reasoning fields) so orchestration and tracing stay consistent across providers.

`ExecuteLlmOperationHandler` also normalizes non-stream provider outputs: when a model invocation returns a direct `ToolCallResult` (instead of stream chunks), tool calls are preserved and persisted. This keeps fixture replay behavior aligned with production orchestration.

**Z.AI-specific behavior**: With GLM models and Z.AI’s interleaved **`tool_stream`** responses, reasoning, visible content, and tool-call fragments can arrive in one stream. How that is assembled and how reasoning is preserved across turns is documented in [docs/interleaved_reasoning_and_tool_calls.md](docs/interleaved_reasoning_and_tool_calls.md). Model-level options (for example **`preserve_reasoning_history`**) live in **`src/Platform/Zai/ModelCatalog.php`**; a capability summary is in **`src/Platform/Zai/CAPABILITIES.md`**.

Configuration references: **`config/services.yaml`** (tagged `ai.platform` services and `ResearchPlatformFactory` locator), **`config/packages/ai.yaml`** (platform list / bundle notes).

## Request Lifecycle

1. **Submission**: frontend sends query to `ResearchController::submit()`.
2. **Rate limit consume**: for **anonymous** requests, `ResearchThrottle::consumeOnSubmit()` consumes one token from the client IP limiter. If rejected, the API returns `429` with `status=throttled` and `retryAfter=86400`, and **no `ResearchRun` is persisted**.
3. **Run creation (accepted only)**: `ResearchRun` is stored with `status=queued`, `phase=queued`, and a Mercure topic.
4. **Initial tick dispatch**: controller dispatches `OrchestratorTick(runId)` directly to the `orchestrator` transport.
5. **Orchestrator transition**: `OrchestratorTickHandler` acquires a per-run lock and delegates to `OrchestratorTransitionService`.
6. **LLM/tools fan-out**: transition logic creates `ResearchOperation` rows and dispatches either LLM or tool operation messages.
7. **Worker completion feedback**: LLM/tool handlers persist operation results and dispatch a new `OrchestratorTick`.
8. **Run completion**: when terminal, final answer and status are persisted and a `complete` event is published.
9. **Token refund on terminal failure/cancel**: for anonymous clients, one limiter token is refunded when a run ends as `failed`, `aborted`, `timed_out`, or `loop_stopped`. Keys prefixed with `user:` (authenticated) are always skipped by this limiter.
10. **Run renewal (retry)**: owner can call `POST /research/runs/{id}/renew`; on success the API returns `202 {status:"renewing", strategy}` and dispatches work according to renewal strategy.

**Client key**: runs are scoped to a stable key from `ResearchController::buildClientKey()`: authenticated users use `user:` + a hash of the user identifier; anonymous users use `anon:` + `sha256(client IP + User-Agent)`. This key is stored on `ResearchRun` and used for list/show/stop authorization.

## History Management

History is rendered via Turbo Frame (`GET /research/history-frame`) and scoped by the same `clientKey` ownership model.

- **Single item delete**: `POST /research/runs/{id}/delete`
- **Delete all visible history**: `POST /research/runs/delete-all`

Both delete actions require a signed `_token` generated in the history frame, and the UI asks for browser confirmation before submission.

Deletion is run-centric: deleting a `research_run` cascades to related `research_operation` and `research_step` records via database `ON DELETE CASCADE` foreign keys.

## Orchestrator State Machine

Run progression is tracked with `ResearchRun.phase` and persisted JSON state (`orchestrator_state_json`).

Primary phases:

- `queued`: initialize prompt state and queue first LLM operation.
- `waiting_llm`: wait for turn result, then either finish or queue tools/next turn.
- `waiting_tools`: wait for all tool operations in turn, integrate results in order, then queue next LLM turn.
- terminal: `completed`, `failed`, `aborted` (plus run status variants like `loop_stopped`, `timed_out`, `throttled`).

Idempotency keys prevent duplicate operation creation:

- LLM: `<runUuid>:llm:<turnNumber>`
- Tool: `<runUuid>:tool:<turnNumber>:<position>`

`RunOrchestratorLock` uses a non-blocking per-run lock key (`research_run:{runUuid}:orchestrator`) to avoid concurrent transitions on the same run.

## Orchestrator Service Decomposition

The orchestration internals are intentionally split so behavior stays explicit and testable:

- **`OrchestratorTransitionService`**: timeout gate, queued-phase bootstrap (`run_started`), and phase routing.
- **`OrchestratorTurnProcessor`**: `waiting_llm` / `waiting_tools` transitions, safeguard enforcement, and next-action selection.
- **`OrchestratorOperationFactory`**: idempotent creation of LLM/tool operations and operation keys.
- **`OrchestratorOperationPayloadMapper`**: typed DTO encode/decode, message-window to `MessageBag` conversion, tool call normalization, metadata extraction.
- **`OrchestratorLlmInvocationRecorder`**: builds/persists `llm_invocation` trace payloads.
- **`OrchestratorStepRecorder`**: append-only `research_step` writer, including token snapshots.
- **`OrchestratorRunStateManager`**: state/version persistence, token budget accounting, terminal-failure helper, and chunked final-answer publishing.

Worker handlers (`ExecuteLlmOperationHandler`, `ExecuteToolOperationHandler`) also use `OrchestratorOperationPayloadMapper`, keeping operation payload contracts consistent end-to-end.

## Data Model

### `research_run` (authoritative snapshot)

Stores request identity and the latest run state:

- query, status, final answer markdown
- token budget counters
- `phase`, `cancel_requested_at`, `orchestration_version`, `orchestrator_state_json`
- timestamps and Mercure topic

### `research_operation` (mutable jobs)

Tracks execution lifecycle for LLM/tool units of work:

- `type`: `llm_call` or `tool_call`
- `status`: `queued`, `running`, `succeeded`, `failed`
- turn/position, idempotency key
- request/result payload JSON and error message
- started/completed timestamps

Operational note: maintenance pruning compacts old runs by removing `research_operation` rows for runs beyond the per-client keep window.

### `research_step` (append-only timeline)

Stores trace/audit history used by history and inspect views:

- sequence, step type, turn number
- summaries, tool metadata, payload JSON
- token snapshot fields where applicable

Operational note: maintenance pruning compacts old runs by replacing full step history with a single `trace_pruned` marker step.

## Event Contract and Streaming

Mercure payloads use a small set of top-level `type` values:

- `activity` — `stepType`, `summary`, `meta` (tool outcomes, reasoning summaries, warnings, incremental model text during an LLM operation via `assistant_stream`, retries, etc.).
- `answer` — final markdown stream (`markdown`, `isFinal`).
- `budget` — token usage meta.
- `phase` — high-level orchestration progress for the UI (`phase`, `status`, `message`, `meta`).
- `complete` — terminal run meta.

`answer` events are streamed in chunks during final output publication:

- intermediate chunks: `isFinal=false`
- terminal marker: `isFinal=true` (empty markdown payload)

Chunking is performed by `OrchestratorRunStateManager` (currently 320 UTF-8 chars per chunk).

Clients should tolerate additional Mercure `type` values (for example `phase`); answer streaming remains incremental via `answer` chunks plus a final empty `isFinal=true` marker.

## Frontend Evidence Mapping

The answer/reference UI consumes streamed markdown and maps references back to trace steps.

`assets/controllers/research_ui/reference_evidence.js` now supports:

- multiple headings (`References`, `Sources`, `Citations`, `Bibliography`, `Works Cited`)
- marker variants (`1`, `[1]`, `(1)`, superscripts)
- line span variants (`lines 10-20`, `L10-L20`)
- URL normalization and domain-level fallback when mapping references to tool trace entries

## Rate Limiting and Runtime Safeguards

### Rate Limiting (submit gate)

`App\Research\Throttle\ResearchThrottle`:

- scope: **anonymous requests only**; authenticated Symfony users bypass limiter consume/refund (`clientKey` starting with `user:`).
- identifier: client IP (`Request::getClientIp()`) for anonymous traffic
- policy: Symfony `sliding_window`, interval `1 day`
- per-day limit: `%env(int:RESEARCH_SUBMIT_RATE_LIMIT)%` (production default is `2`, set in `.env.prod`)
- submit behavior: `consumeOnSubmit()` uses `consume(1)` before run creation
- refund behavior: `refundByClientKey()` restores one token for non-success terminal runs (`failed`, `aborted`, `timed_out`, `loop_stopped`)
- on limit exceed: API returns `429` with `Retry-After` and throttled payload; no run row is created, so no throttled item appears in history

### Runtime safeguards (tick transitions)

`App\Research\Orchestration\OrchestratorTurnProcessor` enforces turn-level safeguards, and `OrchestratorTransitionService` enforces wall-clock timeout:

| Limit | Value | Description |
| :--- | :--- | :--- |
| Max Turns | 75 | Maximum orchestration turns before failure. |
| Wall Clock Timeout | 900 seconds | Run fails if total runtime exceeds 15 minutes. |
| Duplicate Tool Signature | 2 repeats allowed | Third identical tool call triggers `loop_stopped`. |
| Consecutive Tool Failures | 3 | Three tool failures in a row fail the run. |
| Empty LLM Retry | 5 | Repeated empty model responses fail the run. |
| Answer-Only Threshold | 5,000 remaining tokens | Below threshold, tools are disallowed and model is pushed to finalize. |

Token usage is persisted from LLM metadata and published through `budget` events. `hardCap` in budget events comes from `ResearchRun.tokenBudgetHardCap` (default: 75,000).

Timeout behavior uses a per-attempt baseline (`OrchestratorState.attemptStartedAtUnix`) instead of always using run creation time. The baseline is initialized for new runs and reset on renewal, so each retry gets a fresh wall-clock window.

## Cancellation

- **API**: `POST /research/runs/{id}/stop` (`ResearchController::stop`) sets `cancel_requested_at` when absent, flushes, and dispatches `OrchestratorTick` so the run moves toward a terminal state promptly. Access is enforced with the same client key as submit/show.
- **Orchestrator**: if `cancel_requested_at` is set, the next tick treats the run as user-aborted (`aborted`), persists a `run_aborted` step, and emits `complete` with `status=aborted`.
- **Workers**: `ExecuteLlmOperationHandler` and `ExecuteToolOperationHandler` check cancellation while work is in flight and fail operations cooperatively with a clear error when the run was stopped.

## Run Renewal (Retry)

- **API**: `POST /research/runs/{id}/renew` (`ResearchController::renew`) is owner-scoped by `clientKey`, returning:
  - `202` when renewal is accepted,
  - `409` when non-renewable (includes reason),
  - `404` when run is unknown or not owned.
- **Renewable statuses**:
  - `timed_out` -> retry latest operation,
  - `loop_stopped` -> special anti-loop renewal path,
  - `failed` -> only when classified as transient (timeout/network/transport signals),
  - `aborted` -> retry latest operation if present, otherwise restart from queue.
- **Standard retry path** (`retry_last_operation`): `RunRenewalService` resets the latest operation to `queued`, clears terminal run fields, appends `run_renewed`, publishes renewal phase/activity, then dispatches `ExecuteLlmOperation` or `ExecuteToolOperation`.
- **Queue restart path** (`restart_from_queue`): for aborted runs with no operation yet, renewal sets run back to `queued`, appends `run_renewed`, publishes renewal events, and dispatches `OrchestratorTick`.
- **Loop-stopped anti-loop path**: renewal does not replay the same operation payload. It deletes operation rows for the current looped turn, removes the last assistant tool-call turn from `orchestrator_state_json` message history, appends an anti-loop user instruction, clears loop flags, appends `run_renewed`, then dispatches `OrchestratorTick` from `waiting_llm`.
- **Trace semantics**: renewal keeps the same `run_uuid` timeline and adds a `run_renewed` step/event so retries are visible in history and inspect views.

## Component Responsibilities

- **`ResearchController`**: validates submit requests, persists runs, dispatches initial orchestrator ticks, serves owner-scoped history, and handles history delete actions.
- **`RunRenewalPolicy`**: classifies terminal runs as renewable/non-renewable and selects renewal strategy.
- **`RunRenewalService`**: executes renewal strategy (standard retry, restart-from-queue, loop anti-loop recovery), persists `run_renewed`, and dispatches next work.
- **`OrchestratorTickHandler`**: lock + load + one transition + flush + next-action dispatch.
- **`OrchestratorTransitionService`**: timeout checks, queued bootstrap, and phase delegation.
- **`OrchestratorTurnProcessor`**: core `waiting_llm` / `waiting_tools` transition logic and safeguards.
- **`OrchestratorOperationFactory`**: creates/fetches idempotent LLM and tool operations.
- **`OrchestratorOperationPayloadMapper`**: canonical payload codec/normalizer for orchestrator and workers.
- **`OrchestratorLlmInvocationRecorder`**: persists `llm_invocation` trace steps.
- **`OrchestratorStepRecorder`**: persists timeline steps and token snapshots.
- **`OrchestratorRunStateManager`**: persists state, updates budget counters, publishes final answers.
- **`ExecuteLlmOperationHandler`**: performs model invocation and persists operation result.
- **`ExecuteToolOperationHandler`**: executes toolbox call and persists operation result.
- **`RunOrchestratorLock`**: per-run non-blocking lock abstraction.
- **`ResearchPlatformFactory`**: selects `llama`, `generic`, or `zai` platform service per `AI_PLATFORM`.
- **`MercureEventPublisher`**: publishes `activity`, `answer`, `budget`, `phase`, and `complete` events.
- **`WebSearchTool`**: MCP-backed `websearch_search`, `websearch_open`, `websearch_find`.

## Development and Debugging

- **Queue consumers**: `make messenger-consume` processes non-failed transports.
- **Logs**: inspect worker logs to follow ticks, operations, and Mercure publications.
- **Profiler**: inspect Messenger envelopes, Doctrine queries, and HTTP requests.
- **SQLite**: DB file is `data/research` (local-only).
- **Inspect view**: `/research/runs/{id}/inspect` shows persisted run/step state.

## Extension Points

### Add a new tool

1. Create a tool service under `src/Research/Tool/`.
2. Expose tool methods using Symfony AI tool attributes.
3. Ensure arguments/results are serializable and safe for operation payloads.
4. Verify tool outputs integrate well with `tool_succeeded` trace payloads.

### Add or change a safeguard

1. Update transition logic in `OrchestratorTurnProcessor` (or `OrchestratorTransitionService` for timeout/queued behavior).
2. Persist any new counters/flags in `OrchestratorState`.
3. Emit a clear terminal status + `complete` event meta when tripped.

### Change prompting strategy

1. Update `ResearchSystemPromptBuilder` and/or `ResearchTaskPromptBuilder`.
2. Keep citation/output contracts aligned with frontend evidence parsing.

### Switch or extend the LLM provider

1. Set `AI_PLATFORM` and matching base URL / API key env vars; set `RESEARCH_MODEL` to an id the target catalog recognizes.
2. For Z.AI, adjust `App\Platform\Zai\ModelCatalog` / `CAPABILITIES.md` when adding or renaming models.
3. If the vendor’s streaming or tool JSON differs from OpenAI-style deltas, extend `App\Platform\Generic\Completions\ResultConverter` (and related normalizers) rather than branching orchestration code.
