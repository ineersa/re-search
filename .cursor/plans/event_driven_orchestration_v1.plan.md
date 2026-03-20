---
name: event driven orchestration v1
overview: Replace the blocking run loop with a small DB-backed event loop. Keep SQLite as source of truth, split LLM and tool work into separate Messenger queues, add real backend cancellation, and introduce a lightweight per-run lock early.
todos:
  - id: schema-state
    content: Add minimal run state fields (phase, cancel_requested_at, orchestration_version, orchestrator_state_json).
    status: pending
  - id: schema-operations
    content: Add research_operation entity/table for mutable LLM and tool jobs with INT autoincrement id.
    status: pending
  - id: queue-topology
    content: Configure messenger transports and routing for orchestrator, llm, and tool queues.
    status: pending
  - id: lock-foundation
    content: Add lightweight per-run lock service and key strategy for orchestrator safety.
    status: pending
  - id: orchestrator-tick
    content: Implement OrchestratorTick message and handler as one-step state machine (no long while loop).
    status: pending
  - id: llm-worker
    content: Implement LLM operation message and handler that executes model calls and stores results.
    status: pending
  - id: tool-worker
    content: Implement Tool operation message and handler that executes toolbox calls and stores results.
    status: pending
  - id: cancel-flow
    content: Add POST cancel endpoint and wire Stop button to backend cancellation.
    status: pending
  - id: tests-docs
    content: Add focused tests for state transitions and idempotency, then update README worker commands.
    status: pending
isProject: false
---

# Event-driven orchestration v1

## Goal

Make runs event-driven and resumable while keeping code and operations simple.

- DB is the source of truth.
- Orchestrator decides what to do next.
- LLM and tool workers perform external IO.
- Cancellation is backend enforced.

## Scope (intentionally small)

- Keep SQLite.
- Keep Doctrine Messenger transport.
- Keep current Mercure event contract (`activity`, `answer`, `budget`, `complete`).
- Add Symfony Lock in phase 1 with minimal non-blocking per-run lock usage.
- Assume one orchestrator consumer process (enough for current load).

## Non-goals (v1)

- No Postgres/Redis/Rabbit migration.
- No outbox/saga framework.
- No distributed lock coordinator.

## Proposed architecture

### Queues

- `orchestrator`: short state-machine ticks.
- `llm`: model invocations only.
- `tool`: tool invocations only.

### Storage

- `research_run`: authoritative run snapshot.
- `research_operation`: mutable job state for LLM/tool execution.
- `research_step`: existing timeline/audit stream for UI and inspect page.

Why keep `research_operation` instead of reusing `research_step`:

- `research_step` is append-only audit/history and already feeds UI directly.
- Job execution needs mutable lifecycle (`queued` -> `running` -> `succeeded/failed`) and retries.
- Mixing both concerns in one table makes idempotency and "what is still in-flight" queries harder.
- A tiny operation table keeps orchestrator logic simpler while preserving clean trace history.

## Data model changes

### `research_run` new fields

- `phase` string (queued, running, waiting_llm, waiting_tools, completed, failed, aborted)
- `cancel_requested_at` datetime nullable
- `orchestration_version` int (optimistic progression)
- `orchestrator_state_json` text nullable (serialized turn/message state)

### New `research_operation`

Minimal fields:

- `id` INT primary key autoincrement
- `run` relation
- `type` enum-like string (`llm_call`, `tool_call`)
- `status` string (`queued`, `running`, `succeeded`, `failed`)
- `turnNumber` int
- `position` int (tool order for deterministic replay)
- `idempotencyKey` string unique
- `requestPayloadJson` text
- `resultPayloadJson` text nullable
- `errorMessage` text nullable
- `createdAt`, `updatedAt`, `startedAt`, `completedAt`

Indexes:

- `(run_id, status, type, turn_number)`
- unique `(idempotency_key)`

## Runtime flow

1. Submit run creates `research_run` and dispatches `OrchestratorTick(runId)`.
2. Orchestrator tick loads run and exits if terminal.
3. If cancelled, mark aborted, publish `complete`, exit.
4. If needs LLM turn, create one LLM operation, dispatch LLM message, set phase `waiting_llm`, exit.
5. LLM worker stores result and dispatches `OrchestratorTick(runId)`.
6. Orchestrator consumes LLM result:
   - final answer -> complete run
   - tool calls -> create tool operations, set `waiting_tools`
   - no tools/final -> enqueue next LLM operation
7. Tool workers store results and dispatch `OrchestratorTick(runId)`.
8. Orchestrator waits until all tool ops for current turn are finished, integrates results in order, then schedules next LLM turn.

Important: each handler does small, finite work and returns quickly.

## Locking strategy (phase 1)

- Use Symfony Lock with a per-run key (`research_run:{runUuid}:orchestrator`).
- Acquire lock in non-blocking mode for orchestrator work; if lock is busy, exit safely.
- Release lock in `finally`.
- Goal: prevent duplicate concurrent orchestration transitions for the same run.
- Keep locking local and simple; no distributed lock backend needed for current scale.

## Cancellation flow

### Backend

- Add `POST /research/runs/{id}/cancel`.
- Validate ownership using existing client key logic.
- Set `cancel_requested_at` and dispatch orchestrator tick.

### Frontend

- Stop button calls cancel endpoint (instead of UI-only stop).
- Show `Stopping...` immediately.
- Final state arrives through normal `complete` event (`aborted`).

## Guardrails (simplified)

Move in-memory guardrail state to persisted run state:

- token usage from run + operation metadata
- duplicate tool signature counts in `orchestrator_state_json`
- answer-only flag in state json

Keep current behavior rules, just persist state centrally.

## Implementation plan

### Slice 1: schema + wiring

- Add migration for `research_run` fields.
- Add `ResearchOperation` entity + migration.
- Add repositories.
- Configure messenger transports and routing.
- Add lock service/wrapper and lock key convention for run orchestration.
- Generate migration via Symfony command with container wrapper: `make console cmd='make:migration'`.

Done when:

- App boots, migrations run, no behavior changes yet.
- Lock service is in place and ready to be used by orchestrator handlers.

### Slice 2: operation workers

- Add messages:
  - `OrchestratorTick`
  - `ExecuteLlmOperation`
  - `ExecuteToolOperation`
- Add handlers for LLM and tool operations that only execute job + persist result + dispatch tick.

Done when:

- Operations can be created and processed independently.

### Slice 3: orchestrator state machine

- Replace the blocking loop with `OrchestratorTickHandler` that does one short transition and exits.
- Use per-run Symfony Lock from phase 1 to avoid concurrent transitions for the same run.
- Keep existing `research_step` event shape so UI contracts stay unchanged.

Step-by-step:

1. Add `OrchestratorState` DTO (turn number, message window, answer-only flag, loop signature counters) with JSON serialize/deserialize helper.
2. Add orchestrator transition service that receives `(run, state)` and returns one `NextAction`.
3. Implement deterministic phase handling:
   - `queued` -> initialize state, append `run_started`, create first LLM operation, set `waiting_llm`.
   - `waiting_llm` -> if latest LLM op not terminal, exit; if terminal, integrate result, then either complete or create tool ops / next LLM op.
   - `waiting_tools` -> if any tool op still non-terminal, exit; otherwise integrate tool results by `position`, then create next LLM op.
4. Build idempotent operation creation keys using run + turn + operation type (+ position for tools).
5. Move budget/loop checks to persisted state updates (no in-memory singleton state).
6. Update `ExecuteResearchRun` path to only bootstrap run + dispatch initial tick (no long orchestration work).
7. Keep each tick bounded (single state transition max) and always release lock in `finally`.

Done when:

- One run progresses through repeated ticks until terminal state without any long-running orchestrator loop.
- Duplicate `OrchestratorTick` messages are safe (no duplicate operations created).
- Existing trace/history views still render coherent steps.

### Slice 4: cancellation end-to-end

- Add cancel endpoint.
- Wire UI stop action to cancel endpoint.

Done when:

- Cancelling a running run reliably ends as `aborted`.

### Slice 5: tests + docs

- Add tests for:
  - idempotent operation creation
  - repeated tick safety
  - cancel transition
- Document worker commands and queue layout in README.

Done when:

- Basic confidence for hobby-level maintenance.

## Acceptance criteria

- A run no longer blocks one long worker process.
- LLM and tool execution run via separate queue handlers.
- Run state is recoverable from SQLite after process restart.
- Stop from UI performs real backend cancellation.
- Duplicate delivery of messages does not duplicate operations.
- Existing history and inspect UIs still show coherent traces.

## Operational defaults

- Start with 1 orchestrator consumer, 1 llm consumer, 1 tool consumer.
- If LLM is slow, scale llm consumer count first.
- Add Symfony Lock only if we later run multiple orchestrator consumers in parallel.
