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
    C->>T: peek() / consume(0)
    T-->>C: accepted
    C->>DB: create ResearchRun (queued)
    C->>OQ: OrchestratorTick(runId)
    C-->>B: 202 + runId + topic

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
            OTP->>T: consumeByClientKey(clientKey) [on completed only]
        end

        OTP->>M: publish activity/answer/budget/complete
        M-->>B: Mercure events
    end
```

## Queue Topology

- `orchestrator`: handles `OrchestratorTick` state transitions.
- `llm`: handles `ExecuteLlmOperation` model calls.
- `tool`: handles `ExecuteToolOperation` tool executions.

All handlers are intentionally short and finite. Long work is split across multiple messages.

## Request Lifecycle

1. **Submission**: frontend sends query to `ResearchController::submit()`.
2. **Rate limit preflight**: `ResearchThrottle::peek()` checks client IP quota via Symfony RateLimiter (`consume(0)`, no token consumption).
3. **Run creation**: `ResearchRun` is stored with `status=queued`, `phase=queued`, and a Mercure topic.
4. **Initial tick dispatch**: controller dispatches `OrchestratorTick(runId)` directly to the `orchestrator` transport.
5. **Orchestrator transition**: `OrchestratorTickHandler` acquires a per-run lock and delegates to `OrchestratorTransitionService`.
6. **LLM/tools fan-out**: transition logic creates `ResearchOperation` rows and dispatches either LLM or tool operation messages.
7. **Worker completion feedback**: LLM/tool handlers persist operation results and dispatch a new `OrchestratorTick`.
8. **Run completion**: when terminal, final answer and status are persisted and a `complete` event is published.
9. **Token consumption on success**: one limiter token is consumed only when a run reaches `completed` (not on failed/aborted/throttled runs).

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

### `research_step` (append-only timeline)

Stores trace/audit history used by history and inspect views:

- sequence, step type, turn number
- summaries, tool metadata, payload JSON
- token snapshot fields where applicable

## Event Contract and Streaming

The Mercure contract remains stable:

- `activity`
- `answer`
- `budget`
- `complete`

`answer` events are now streamed in chunks during final output publication:

- intermediate chunks: `isFinal=false`
- terminal marker: `isFinal=true` (empty markdown payload)

Chunking is performed by `OrchestratorRunStateManager` (currently 320 UTF-8 chars per chunk).

This preserves the existing event type contract while restoring incremental answer rendering in the UI.

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

- identifier: client IP only (`Request::getClientIp()`)
- policy: Symfony `sliding_window`, interval `1 day`
- per-day limit: `%env(int:RESEARCH_SUBMIT_RATE_LIMIT)%` (production default is `2`, set in `.env.prod`)
- submit behavior: `peek()` uses `consume(0)` to check quota before run creation (no token spent at submit time)
- token spend point: `consumeByClientKey()` is called after successful finalization (`status=completed`)
- on limit exceed: request is recorded as `throttled`, API returns `429` with `Retry-After`

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

## Cancellation (Current State)

Backend cancellation is supported at orchestration level:

- if `ResearchRun.cancel_requested_at` is set, next tick marks run `aborted`
- `run_aborted` step is persisted and `complete` event is emitted with `status=aborted`

HTTP cancel endpoint wiring and Stop-button backend integration are not yet completed in this slice.

## Component Responsibilities

- **`ResearchController`**: validates submit requests, persists runs, dispatches initial orchestrator ticks.
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
- **`MercureEventPublisher`**: publishes `activity/answer/budget/complete` events.
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
