# re-search

This project uses the Symfony UX frontend stack. Six agent skills are installed to help you work with it.

## Which tool to use

- **Pure JS behavior, no server round-trip** -- use the `stimulus` skill
- **Navigation, partial page updates** -- use the `turbo` skill
- **Reusable static UI component** -- use the `twig-component` skill
- **Reactive component that re-renders on user input** -- use the `live-component` skill
- **Not sure which one fits** -- use the `symfony-ux` skill (orchestrator / decision tree)
- **Browser automation / UI testing** -- use the `playwright-cli` skill via Task tool with `subagent_type: "playwright-cli"`

## Key rules

- Always render `{{ attributes }}` on the root element of a LiveComponent
- Prefer HTML syntax (`<twig:Alert />`) over Twig syntax (`{% component 'Alert' %}`)
- Use `data-model="debounce(300)|field"` for text inputs in LiveComponents
- Stimulus controllers must clean up listeners and observers in `disconnect()`
- Turbo Frame IDs must match between the page and the server response
- Use Turbo Streams when updating multiple page sections; use Frames for a single section
- `<twig:Turbo:Stream:Append>` syntax is available since Symfony UX 2.22+
- **MATE tools (mandatory)**: Logs, profiler, PHPStan, PHPUnit, and Composer MUST use the `mate-tools` skill and its wrapper script (`.cursor/skills/mate-tools/scripts/mate-tool-call.sh`). NEVER use `make test`, `make phpstan`, or direct `vendor/bin` commands for these.
- Infrastructure operations (setup, up/down, console, doctrine) MUST use `make` targets (no direct `docker compose exec` for these)
- **NEVER run Composer on the host** — Composer MUST run inside the Docker container only (via `make composer-*` or MATE `composer-*` tools)
- **NEVER run PHP on the host** — All PHP commands (phpunit, phpstan, console, etc.) MUST run inside the container
- For browser testing and UI verification, ALWAYS use Task tool with `subagent_type: "playwright-cli"`
- NEVER compile assets in development environment; the `make tailwind-watch` process handles this automatically
- DO NOT run `make assets-compile` during development; only do this in production builds

## Docker setup

- Runtime stack uses FrankenPHP (PHP 8.5), Symfony 8 worker mode, built-in Mercure, and SQLite.
- SQLite database file path is `data/research` (mapped in app as `DATABASE_URL=sqlite:///%kernel.project_dir%/data/research`).
- Keep `data/.gitignore` as `*` plus `!.gitignore` so data is local-only.
- Local development uses `compose.yaml` + `compose.override.yaml`.
- Production-style local runs use `compose.yaml` + `compose.prod.yaml` (includes Messenger worker containers). VPS / TLS / domain: [docs/server-deployment.md](docs/server-deployment.md).

## Make commands

- Use `make help` for the full target list.
- Primary flow: `make setup`, then `make dev-bootstrap`, run `make messenger-consume` while developing (covers scheduler transport via `--all`), then `make logs` as needed.
- Local lifecycle: `make up`, `make down`, `make restart`, `make ps`.
- Production-like lifecycle: `make up-prod`, `make down-prod`, `make restart-prod`, `make ps-prod`.
- Symfony/Composer in container: `make composer-install`, `make console cmd='about'`, `make doctrine-migrate`, `make test`.
- Config checks: `make config` and `make config-prod`.

## MATE diagnostics (use `mate-tools` skill)

Agents MUST use these MATE tools — do not use `make` or direct `vendor/bin` for them:

- PHPStan: `phpstan-analyse` tool
- PHPUnit: `phpunit-run-suite` tool
- Runtime info: `server-info` tool
- Logs: `monolog-tail`, `monolog-search` tool (`level`/`regex` params)
- Profiler: `symfony-profiler-list` tool (`limit: 1` for latest)
- Composer: `composer-install`, `composer-require`, `composer-update` tools

## Important documentation

- [docs/interleaved_reasoning_and_tool_calls.md](docs/interleaved_reasoning_and_tool_calls.md) — Details on Z.AI's interleaved `tool_stream` behavior and our custom ResultConverter implementation that handles reasoning, content, and tool calls together in streaming responses.

<!-- BEGIN AI_MATE_INSTRUCTIONS -->
AI Mate Summary:
- Role: MCP-powered, project-aware coding guidance and tools.
- Required action: Read and follow `mate/AGENT_INSTRUCTIONS.md` before taking any action in this project, and prefer MCP tools over raw CLI commands whenever possible.
- Installed extensions: matesofmate/composer-extension, matesofmate/phpstan-extension, matesofmate/phpunit-extension, symfony/ai-mate, symfony/ai-monolog-mate-extension, symfony/ai-symfony-mate-extension.
<!-- END AI_MATE_INSTRUCTIONS -->
