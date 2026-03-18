---
name: mate-tools
description: Symfony AI Mate operational skill for MCP tool usage, parameter selection, and Docker-first CLI execution. Triggers - mate, symfony ai mate, mcp tools call, phpstan tool, phpunit tool, monolog tool, profiler tool, docker mate wrapper.
license: MIT
metadata:
  author: OpenCode
  version: "1.0"
---

# Mate Tools

Use this skill when working with Symfony AI Mate capabilities in this repository: static analysis, test execution, logs, Symfony service/profiler inspection, and runtime introspection.

This project runs Mate from the Docker `php` service. Tool execution should therefore mirror container runtime behavior, not host PHP behavior.

## When to use

- User asks to run or explain Mate tools.
- User needs schema/parameters for `phpstan`, `phpunit`, `monolog`, or Symfony profiler tools.
- User wants CLI equivalents for MCP tool calls.
- User needs repeatable Docker-safe commands for Mate operations.

## Required: ALWAYS use the wrapper script

**MANDATORY**: All Mate MCP tool invocations MUST use the provided wrapper script:

```bash
.opencode/skills/mate-tools/scripts/mate-tool-call.sh <tool-name> '<json-input>'
```

**NEVER** call `docker compose exec ... vendor/bin/mate` directly. The wrapper ensures:
- Correct Docker container context (PHP 8.5 runtime)
- TOON format output for LLM efficiency
- Proper stderr suppression of bootstrap noise
- Consistent environment across all tool calls

### Why this wrapper exists

- Keeps all tool calls inside the `php` container.
- Avoids repeating long commands.
- Standardizes TOON output format for LLM efficiency.

### Usage

- `<tool-name>`: Mate MCP tool name, e.g. `php-version`, `phpstan-analyse`.
- `<json-input>`: JSON object string, e.g. `'{}'`, `'{"mode":"summary"}'`.

Run from repo root (where `compose.yaml` lives). JSON must be valid. Use single quotes around the JSON string in shell. If Docker stack is down, start it first (`make up`).

### Discovering available tools

```bash
docker compose exec -T php php vendor/bin/mate mcp:tools:list --format=toon
```

### Debugging

- Wrapper hides Mate bootstrap `[INFO]` lines by default by redirecting stderr.
- To keep bootstrap logs visible: `MATE_TOOL_CALL_SHOW_BOOT_LOGS=1 .opencode/skills/mate-tools/scripts/mate-tool-call.sh ...`.

## Operating rules

- **ALWAYS use the wrapper script** for all Mate tool calls.
- Prefer Mate MCP tools for focused diagnostics and machine-readable output.
- All tool output uses `--format=toon` for maximum token efficiency in LLM contexts.
- Use concise modes first (`summary`/default), then increase detail only when needed.
- For direct shell-based quality tasks in this repo, follow project policy and prefer `make` targets.
- Keep JSON input explicit and valid; pass `{}` when a tool expects an object with no required fields.

## Tool catalog

Tool names below are the Mate MCP names used with `mcp:tools:call`.

### Runtime / Environment

| Tool | Input | Description |
|------|-------|-------------|
| `php-version` | `{}` | Active PHP version used by Mate runtime |
| `operating-system` | `{}` | OS name where Mate is running |
| `operating-system-family` | `{}` | OS family |
| `php-extensions` | `{}` | Loaded PHP extensions |

### Tool categories (see references for parameters)

- **PHPStan** — `phpstan-analyse`, `phpstan-analyse-file`, `phpstan-clear-cache` — [references/phpstan.md](references/phpstan.md)
- **PHPUnit** — `phpunit-list-tests`, `phpunit-run-suite`, `phpunit-run-file`, `phpunit-run-method` — [references/phpunit.md](references/phpunit.md)
- **Observability** — Monolog logs and Symfony profiler — [references/observability.md](references/observability.md)
- **Composer** — `composer-install`, `composer-require`, `composer-update`, `composer-why`, `composer-why-not` — [references/composer-tools.md](references/composer-tools.md)

## Quick start

```bash
# Check runtime identity
.opencode/skills/mate-tools/scripts/mate-tool-call.sh php-version '{}'
.opencode/skills/mate-tools/scripts/mate-tool-call.sh operating-system '{}'

# Code health
.opencode/skills/mate-tools/scripts/mate-tool-call.sh phpstan-analyse '{"mode":"summary"}'

# Tests
.opencode/skills/mate-tools/scripts/mate-tool-call.sh phpunit-run-suite '{"mode":"summary"}'

# Logs
.opencode/skills/mate-tools/scripts/mate-tool-call.sh monolog-tail '{"lines":50}'

# Symfony profiler
.opencode/skills/mate-tools/scripts/mate-tool-call.sh symfony-profiler-latest '{}'
```

## Composer Tools

Composer dependency management via `matesofmate/composer-extension`:

```bash
# Install dependencies
.opencode/skills/mate-tools/scripts/mate-tool-call.sh composer-install '{}'

# Add a new package
.opencode/skills/mate-tools/scripts/mate-tool-call.sh composer-require '{"package":"symfony/console","version":"^6.4"}'

# Remove a package
.opencode/skills/mate-tools/scripts/mate-tool-call.sh composer-remove '{"package":"symfony/debug-bundle","dev":true}'

# Update dependencies
.opencode/skills/mate-tools/scripts/mate-tool-call.sh composer-update '{"mode":"summary"}'

# Investigate package dependencies
.opencode/skills/mate-tools/scripts/mate-tool-call.sh composer-why '{"package":"psr/log"}'
.opencode/skills/mate-tools/scripts/mate-tool-call.sh composer-why-not '{"package":"php","version":"7.4"}'
```

## References

- [references/phpstan.md](references/phpstan.md) — PHPStan usage patterns
- [references/phpunit.md](references/phpunit.md) — PHPUnit usage patterns
- [references/observability.md](references/observability.md) — Monolog and Symfony profiler diagnostics
- [references/composer-tools.md](references/composer-tools.md) — Composer dependency tools
