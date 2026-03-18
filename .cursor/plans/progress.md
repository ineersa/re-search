# Web Research Flow — Progress

Plan: [web_research_flow_5c8ddc68.plan.md](web_research_flow_5c8ddc68.plan.md)

## Completed

### Task 1: Backend domain skeleton in `src/Research/`

- **Run orchestration:** `RunOrchestrator`
- **Brief building:** `ResearchBriefBuilder`
- **Event publishing:** `EventPublisherInterface`, `NullEventPublisher` (stub)
- **Limiter/budget enforcement:** `ResearchBudgetEnforcerInterface`, `ResearchBudgetEnforcer` (stub)
- **History loading:** `HistoryLoaderInterface`, `HistoryLoader`, `ResearchRunRepository`
- **DTOs:** `ResearchTurnInput`, `ResearchTurnResult`, `ToolCallDecision`, `BudgetState`
- **Entry point:** `ResearchRunService` delegating to orchestrator
- **Service wiring:** Interface bindings in `config/services.yaml` (EventPublisher → NullEventPublisher)

### Task 2: Persistence model and migrations

- **Entities:** `ResearchRun`, `ResearchStep`, `ResearchMessage`, `ResearchSource`
- **Repositories:** `App\Repository\ResearchRunRepository`, `ResearchStepRepository`, `ResearchMessageRepository`, `ResearchSourceRepository`
- **Migration:** `Version20260317215012` — SQLite-backed tables created via `doctrine:migrations:diff`
- **Persistence adapter:** `App\Research\Persistence\ResearchRunRepository` maps entities to arrays for `HistoryLoader`

### Quality

- PHPStan passes (array type hints added; baseline for skeleton dead-code)
- Migration runs successfully

## Pending

- Task 3: Request throttling and research budgets
- Task 4: Real research orchestration service
- Task 5: Mercure-backed streaming endpoints
- Task 6: Live streaming UI
