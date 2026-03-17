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
scripts/mate-tool-call.sh <tool-name> '<json-input>'
```

**NEVER** call `docker compose exec ... vendor/bin/mate` directly. The wrapper ensures:
- Correct Docker container context (PHP 8.5 runtime)
- TOON format output for LLM efficiency
- Proper stderr suppression of bootstrap noise
- Consistent environment across all tool calls

## Operating rules

- **ALWAYS use the wrapper script** for all Mate tool calls.
- Prefer Mate MCP tools for focused diagnostics and machine-readable output.
- All tool output uses `--format=toon` for maximum token efficiency in LLM contexts.
- Use concise modes first (`summary`/default), then increase detail only when needed.
- For direct shell-based quality tasks in this repo, follow project policy and prefer `make` targets.
- Keep JSON input explicit and valid; pass `{}` when a tool expects an object with no required fields.
- Mate bootstrap `[INFO]` lines are emitted on stderr; suppress with `2>/dev/null` when you need clean payload output.

## Quick start

```bash
# Check runtime identity
scripts/mate-tool-call.sh php-version '{}'
scripts/mate-tool-call.sh operating-system '{}'

# Code health
scripts/mate-tool-call.sh phpstan-analyse '{"mode":"summary"}'

# Tests
scripts/mate-tool-call.sh phpunit-run-suite '{"mode":"summary"}'

# Logs
scripts/mate-tool-call.sh monolog-tail '{"lines":50}'

# Symfony profiler
scripts/mate-tool-call.sh symfony-profiler-latest '{}'
```

## Composer Tools

Composer dependency management via `matesofmate/composer-extension`:

```bash
# Install dependencies
scripts/mate-tool-call.sh composer-install '{}'

# Add a new package
scripts/mate-tool-call.sh composer-require '{"package":"symfony/console","version":"^6.4"}'

# Remove a package
scripts/mate-tool-call.sh composer-remove '{"package":"symfony/debug-bundle","dev":true}'

# Update dependencies
scripts/mate-tool-call.sh composer-update '{"mode":"summary"}'

# Investigate package dependencies
scripts/mate-tool-call.sh composer-why '{"package":"psr/log"}'
scripts/mate-tool-call.sh composer-why-not '{"package":"php","version":"7.4"}'
```

## References

- Tool invocation wrapper and examples: [references/command-wrapper.md](references/command-wrapper.md)
- Complete tool catalog and parameters: [references/tool-reference.md](references/tool-reference.md)
- PHPStan usage patterns: [references/phpstan.md](references/phpstan.md)
- PHPUnit usage patterns: [references/phpunit.md](references/phpunit.md)
- Monolog and Symfony diagnostics: [references/observability.md](references/observability.md)
- Composer dependency tools: [references/composer-tools.md](references/composer-tools.md)
