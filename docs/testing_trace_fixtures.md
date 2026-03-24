# Trace Fixture Testing Plan

## Goal

Build reliable regression coverage for the event-driven research orchestrator by capturing real `research_run` traces from SQLite, storing normalized fixtures, and replaying them in PHPUnit contract tests.

This complements unit tests by locking in behavior across multi-step orchestration flows (`queued -> waiting_llm -> waiting_tools -> terminal`).

## Strategy

- Capture real runs from `research_run` + `research_operation` + `research_step`.
- Export each run into a stable, normalized fixture JSON.
- Replay fixtures in tests and assert orchestration contracts:
  - terminal status and phase
  - required/forbidden step types per scenario
  - contiguous step sequence
  - idempotency key format and uniqueness
  - token snapshot monotonicity

## Fixture Schema (v1)

Each fixture contains:

- `run`: run-level snapshot (`status`, `phase`, budget counters, terminal metadata)
- `operations`: compact operation timeline with idempotency keys + payload summaries
- `steps`: append-only trace timeline with payload summaries
- `expected`: status/phase + required/forbidden step type contracts

Payloads are summarized (not stored in full) to keep fixtures readable and stable while retaining enough evidence for replay assertions.

## Implemented in this slice

### 1) Export command

Command: `app:research:fixture:export`

Source: `src/Command/ExportResearchTraceFixtureCommand.php`

Usage:

```bash
make console cmd='app:research:fixture:export <run-uuid> <fixture-name>'
```

Options:

- `--dir=tests/Fixtures/research_traces` (default)
- `--force` to overwrite an existing fixture

### 2) Replay test suite

Test: `tests/Research/FixtureReplay/ResearchTraceFixtureReplayTest.php`

Behavior:

- auto-loads all `tests/Fixtures/research_traces/*.json`
- replays fixture timelines and verifies orchestration contracts

### 3) Workflow coverage suite (fixture-backed mocks)

Test: `tests/Research/FixtureReplay/ResearchTraceWorkflowCoverageTest.php`

Behavior:

- boots the real orchestrator services
- runs transitions + handlers in-process against test DB
- uses fixture-backed test doubles for:
  - `Symfony\AI\Platform\PlatformInterface`
  - `App\Research\Mcp\McpWebSearchClient` (via mocked HttpClient responses)
- validates final run status/phase and step-type contracts

Service wiring is in `config/services_test.yaml`.

## Scenario matrix

Priority for fixture capture:

P0:

1. `completed_happy_path`
2. `timed_out`
3. `failed_llm_operation`
4. `loop_stopped_duplicate_signature`
5. `aborted_stop_requested`

P1:

1. `failed_tool_3x_consecutive`
2. `failed_empty_response_retries`
3. `answer_only_enabled_then_final`
4. `throttled_submit`

Current captured fixtures:

- `completed_happy_path`
- `timed_out_wall_clock`
- `failed_llm_operation`
- `loop_stopped_duplicate_signature`
- `aborted_stop_requested`
- `failed_tool_3x_consecutive`
- `failed_empty_response_retries`
- `answer_only_enabled_then_final`
- `throttled_submit`

## Capture workflow

1. Run scenario in app and wait for terminal status.
2. Export fixture immediately:

   ```bash
   make console cmd='app:research:fixture:export <run-uuid> <scenario-name>'
   ```

3. Verify fixture appears under `tests/Fixtures/research_traces/`.
4. Run replay test suite.

## Important operational note

Trace pruning runs periodically and can collapse old traces to `trace_pruned`.

Relevant files:

- `src/Research/Maintenance/ResearchMaintenanceSchedule.php`
- `src/Research/Maintenance/ResearchTracePruner.php`

Because of this, export fixtures soon after scenario execution.
