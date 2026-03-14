# CLI Wrapper for Mate Tool Calls

This skill includes a helper script:

- `scripts/mate-tool-call.sh`

It wraps:

`docker compose exec -T php php vendor/bin/mate mcp:tools:call --format=toon`

## Why this wrapper exists

- Keeps all tool calls inside the `php` container.
- Avoids repeating long commands.
- Standardizes TOON output format for LLM efficiency.

## Usage

```bash
# From skill root
scripts/mate-tool-call.sh <tool-name> '<json-input>'
```

- `<tool-name>`: Mate MCP tool name, e.g. `php-version`, `phpstan-analyse`.
- `<json-input>`: JSON object string, e.g. `'{}'`, `'{"mode":"summary"}'`.

## Examples

```bash
scripts/mate-tool-call.sh php-version '{}'
scripts/mate-tool-call.sh phpstan-analyse '{"mode":"summary"}'
scripts/mate-tool-call.sh phpunit-run-suite '{"mode":"summary"}'
scripts/mate-tool-call.sh monolog-tail '{"lines":100,"level":"ERROR"}'
```

## Discovering available tools

To list all available Mate tools:

```bash
docker compose exec -T php php vendor/bin/mate mcp:tools:list --format=toon
```

Or via raw MCP (without the wrapper):

```bash
docker compose exec -T php php vendor/bin/mate mcp:tools:list
```

## Notes

- Run from repo root (where `compose.yaml` lives).
- JSON must be valid. Use single quotes around the JSON string in shell.
- If Docker stack is down, start it first (`make up`).
- Wrapper always uses TOON format for compact, LLM-friendly output.
- Wrapper hides Mate bootstrap `[INFO]` lines by default by redirecting stderr.
- To keep bootstrap logs visible for debugging: `MATE_TOOL_CALL_SHOW_BOOT_LOGS=1 scripts/mate-tool-call.sh ...`.
