# Mate MCP Tool Reference

Tool names below are the Mate MCP names used with `mcp:tools:call`.

## Command output controls

- All output uses `--format=toon` for compact, LLM-friendly token-efficient output.
- Mate bootstrap discovery logs are written to stderr; append `2>/dev/null` if you need clean stdout only.

## Runtime / Environment

### `php-version`

- Returns active PHP version used by Mate runtime.
- Input: `{}`.

### `operating-system`

- Returns OS name where Mate is running.
- Input: `{}`.

### `operating-system-family`

- Returns OS family.
- Input: `{}`.

### `php-extensions`

- Returns loaded PHP extensions.
- Input: `{}`.

## Tool Categories

See the specialized references for detailed tool documentation:

- **[PHPStan tools](phpstan.md)** — `phpstan-analyse`, `phpstan-analyse-file`, `phpstan-clear-cache`
- **[PHPUnit tools](phpunit.md)** — `phpunit-list-tests`, `phpunit-run-suite`, `phpunit-run-file`, `phpunit-run-method`
- **[Observability tools](observability.md)** — Monolog logs and Symfony profiler introspection
