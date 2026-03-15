# re-search

This project uses the Symfony UX frontend stack. Five agent skills are installed to help you work with it.

## Which tool to use

- **Pure JS behavior, no server round-trip** -- use the `stimulus` skill
- **Navigation, partial page updates** -- use the `turbo` skill
- **Reusable static UI component** -- use the `twig-component` skill
- **Reactive component that re-renders on user input** -- use the `live-component` skill
- **Not sure which one fits** -- use the `symfony-ux` skill (orchestrator / decision tree)

## Key rules

- Always render `{{ attributes }}` on the root element of a LiveComponent
- Prefer HTML syntax (`<twig:Alert />`) over Twig syntax (`{% component 'Alert' %}`)
- Use `data-model="debounce(300)|field"` for text inputs in LiveComponents
- Stimulus controllers must clean up listeners and observers in `disconnect()`
- Turbo Frame IDs must match between the page and the server response
- Use Turbo Streams when updating multiple page sections; use Frames for a single section
- `<twig:Turbo:Stream:Append>` syntax is available since Symfony UX 2.22+
- Diagnostics (PHPStan, PHPUnit tests, logs, profiler checks) MUST use MATE tools via the `mate-tools` skill wrapper script — not make commands
- Infrastructure operations (setup, up/down, composer, console, doctrine) MUST use `make` targets (no direct `docker compose exec` for these)
- PHPStan, PHPUnit, Symfony profiler, Monolog symfony logs, PHP/OS information
  MUST always load `mate-tools` skill and use MATE diagnostics

## Docker setup

- Runtime stack uses FrankenPHP (PHP 8.5), Symfony 8 worker mode, built-in Mercure, and SQLite.
- SQLite database file path is `data/research` (mapped in app as `DATABASE_URL=sqlite:///%kernel.project_dir%/data/research`).
- Keep `data/.gitignore` as `*` plus `!.gitignore` so data is local-only.
- Local development uses `compose.yaml` + `compose.override.yaml`.
- Production-style local runs use `compose.yaml` + `compose.prod.yaml`.

## Make commands

- Use `make help` for the full target list.
- Primary flow: `make setup`, then `make logs`.
- Local lifecycle: `make up`, `make down`, `make restart`, `make ps`.
- Production-like lifecycle: `make up-prod`, `make down-prod`, `make restart-prod`, `make ps-prod`.
- Symfony/Composer in container: `make composer-install`, `make console cmd='about'`, `make doctrine-migrate`, `make test`.
- Config checks: `make config` and `make config-prod`.

## MATE diagnostics (use `mate-tools` skill)

- PHPStan analysis: `phpstan-analyse` tool
- PHPUnit tests: `phpunit-run-suite` tool
- Log inspection: `monolog-tail` tool
- Symfony profiler: `symfony-profiler-latest` tool
