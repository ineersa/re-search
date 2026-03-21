#!/bin/bash
set -euo pipefail

host_uid="$(id -u)"
host_gid="$(id -g)"

args=()
for arg in "$@"; do
    arg="${arg//\/home\/ineersa\/projects\/re-search/\/app}"
    args+=("$arg")
done

echo "[$(date)] Running: docker compose exec -T -u ${host_uid}:${host_gid} php php vendor/bin/phpstan ${args[*]}" >> /tmp/phpstan-vscode-docker.log
docker compose exec -T php sh -lc "mkdir -p /app/var/phpstan && chown -R ${host_uid}:${host_gid} /app/var/phpstan" 2>> /tmp/phpstan-vscode-docker-err.log
docker compose exec -T -u "${host_uid}:${host_gid}" php php vendor/bin/phpstan "${args[@]}" 2>> /tmp/phpstan-vscode-docker-err.log
