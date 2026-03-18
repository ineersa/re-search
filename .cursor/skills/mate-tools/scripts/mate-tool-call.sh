#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -lt 1 ]; then
  cat <<'USAGE'
Usage:
  mate-tool-call.sh <tool-name> [json-input]

Examples:
  mate-tool-call.sh php-version '{}'
  mate-tool-call.sh phpstan-analyse '{"mode":"summary"}'
  mate-tool-call.sh phpunit-run-suite '{"mode":"summary"}'

Defaults:
  json-input: {}

Behavior:
  Always uses TOON format for compact, LLM-friendly output.
  Suppresses Mate bootstrap INFO lines by default.
  Set MATE_TOOL_CALL_SHOW_BOOT_LOGS=1 to show them.
USAGE
  exit 1
fi

tool_name="$1"
json_input='{}'

if [ "$#" -ge 2 ]; then
  json_input="$2"
fi

if [ "${MATE_TOOL_CALL_SHOW_BOOT_LOGS:-0}" = "1" ]; then
  exec docker compose exec -T -u "$(id -u):$(id -g)" php php vendor/bin/mate mcp:tools:call \
    "$tool_name" \
    "$json_input" \
    --format=toon
fi

exec docker compose exec -T -u "$(id -u):$(id -g)" php php vendor/bin/mate mcp:tools:call \
  "$tool_name" \
  "$json_input" \
  --format=toon \
  2>/dev/null
