# Failed Run Renewal Plan

## Goal

Add a safe renewal flow for terminal runs that most often fail due to transient API/network/timeouts, and allow loop-stopped runs to be renewed using the same strategy: retry the last operation.

This plan keeps the same `run_uuid` and continues the same run timeline.

## Context and findings

From the downloaded DB (`./research`), all recent failed runs ended with LLM timeout errors:

- `LLM operation failed: Idle timeout reached for "https://api.z.ai/api/paas/v4//chat/completions".`

The existing architecture already persists enough state to recover:

- run state: `research_run.status`, `research_run.phase`, `orchestrator_state_json`
- operation state: `research_operation` (`type`, `status`, `turn_number`, payload/error)
- step timeline: `research_step` (append-only trace)

Current terminal statuses include:

- `failed`
- `timed_out`
- `loop_stopped`
- `aborted`
- `throttled`
- `completed`

## Product behavior (target)

### Renewable statuses

Renewal is allowed for:

- `failed` (when failure is transient/API/timeout-related)
- `timed_out`
- `loop_stopped` (same retry strategy)

Renewal is not allowed for:

- `completed`
- `throttled`
- `aborted` (user explicitly stopped)
- non-transient `failed` reasons (for example bad model config or payload schema errors)

### Renewal strategy

Single strategy for renewable runs:

1. Find the latest operation in the run (order by `turn_number DESC`, `position DESC`, `id DESC`).
2. Reset that operation to `queued`.
3. Reset run terminal fields back to active.
4. Dispatch the corresponding worker message:
   - LLM operation -> `ExecuteLlmOperation`
   - tool operation -> `ExecuteToolOperation`

No new run is created.

## Backend design

### 1) Add renewal policy classifier

Create `src/Research/Renewal/RunRenewalPolicy.php`.

Responsibilities:

- evaluate run status + failure reason + latest operation error payload
- return either:
  - `renewable(strategy: retry_last_operation, reason: ...)`
  - `non_renewable(reason: ...)`

Transient signals to treat as renewable:

- timeout messages (`idle timeout`, `timed out`, `timeout reached`)
- transport/network errors from operation payload classes (for example timeout/transport exceptions)
- tool transport failures (temporary host/network issues)

`loop_stopped` and `timed_out` are directly renewable (same retry strategy).

### 2) Add renewal service

Create `src/Research/Renewal/RunRenewalService.php`.

Responsibilities:

- load and validate run domain state
- acquire per-run lock via `RunOrchestratorLock`
- read latest operation from repository
- apply operation reset + run reset
- append renewal trace step
- publish phase/activity events
- flush + dispatch worker message

Operation reset:

- `status = queued`
- `startedAt = null`
- `completedAt = null`
- `errorMessage = null`
- `resultPayloadJson = null`

Run reset:

- `status = running`
- `phase = waiting_llm` or `waiting_tools` (from operation type)
- `failureReason = null`
- `completedAt = null`
- `cancelRequestedAt = null`
- `loopDetected = false` (important for `loop_stopped` renewals)

Trace/event additions:

- append step type `run_renewed`
- summary example: `Renewed run: retrying last llm_call operation`
- emit phase update: `Run renewed, retrying last operation`

### 3) Timeout baseline reset for renewed attempts

Problem:

- current timeout check uses `createdAt`; renewed `timed_out` runs would immediately time out again.

Plan:

- extend `OrchestratorState` with `attemptStartedAtUnix` (int, nullable/default 0)
- set on initial queued transition if missing
- set to `time()` on renewal
- update timeout check in `OrchestratorTransitionService` to use:
  - `attemptStartedAtUnix` when available
  - fallback to `createdAt` for backward compatibility

This avoids mutating `createdAt` and cleanly supports multiple attempts.

### 4) Repository support

Add method in `src/Repository/ResearchOperationRepository.php`:

- `findLatestByRun(ResearchRun $run): ?ResearchOperation`

Query order:

- `turnNumber DESC`, `position DESC`, `id DESC`

### 5) HTTP endpoint

Add route in `src/Research/Controller/ResearchController.php`:

- `POST /research/runs/{id}/renew` (name: `app_research_renew`)

Flow:

- enforce same client-key ownership pattern as `stop`
- call renewal service
- responses:
  - `202 Accepted` on success (`status: renewing`, `runId`, `strategy`)
  - `409 Conflict` if not renewable
  - `404` for unknown/not-owned run

## Frontend plan

Update `assets/controllers/research_ui_controller.js` and `templates/home/index.html.twig`:

- add a Retry button in run actions (terminal views/history-loaded runs)
- show only when run is terminal and renewable candidate (`failed`, `timed_out`, `loop_stopped`)
- call new renew endpoint
- on success:
  - clear terminal UI state
  - subscribe/reconnect Mercure to the same run topic
  - render `is-searching` again
  - keep trace visible (append renewed activity naturally)

Optional UX copy:

- button text: `Retry`
- in-progress text: `Retrying...`

## Testing plan

### Unit tests

1. `RunRenewalPolicyTest`

- classifies timeout/API failures as renewable
- classifies `timed_out` and `loop_stopped` as renewable
- rejects non-transient failures and non-renewable statuses

2. `RunRenewalServiceTest`

- resets latest LLM operation and run fields correctly
- resets latest tool operation and run fields correctly
- appends `run_renewed` step
- dispatches correct message type
- throws domain exception on missing latest operation

### Integration/controller tests

Extend `tests/Research/Controller/ResearchControllerTest.php`:

- renew returns `202` for eligible failed run
- renew returns `202` for loop-stopped run
- renew returns `202` for timed-out run
- renew returns `409` for completed/throttled/aborted
- renew returns `404` for owner mismatch

### Regression checks

- ensure renewed runs continue through normal orchestrator path
- ensure `timed_out` renewal does not instantly timeout again
- ensure repeated renew attempts do not corrupt operation idempotency rows

## Rollout

1. Implement backend service + endpoint + tests.
2. Implement UI retry action.
3. Verify locally on imported DB copy:
   - renew each failed timeout run
   - confirm operation retried and run proceeds
4. Monitor logs/profiler for renewal frequency and outcomes.
5. Optional follow-up: add CLI batch command `app:research:renew-failed` for admin backfill.

## File-level implementation checklist

- `src/Research/Renewal/RunRenewalPolicy.php` (new)
- `src/Research/Renewal/RunRenewalService.php` (new)
- `src/Repository/ResearchOperationRepository.php` (latest operation query)
- `src/Research/Controller/ResearchController.php` (renew endpoint)
- `src/Research/Orchestration/Dto/OrchestratorState.php` (attempt start field)
- `src/Research/Orchestration/OrchestratorTransitionService.php` (timeout baseline update)
- `assets/controllers/research_ui_controller.js` (retry action)
- `templates/home/index.html.twig` (Retry button)
- `tests/Research/Controller/ResearchControllerTest.php` (controller coverage)
- `tests/Research/Renewal/*` (new policy/service tests)
