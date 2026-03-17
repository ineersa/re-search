#!/bin/bash
# Wrapper to log and execute PHPStan

# Convert paths like php-cs-fixer
args=()
for arg in "$@"; do
    arg="${arg//\/home\/ineersa\/projects\/re-search/\/app}"
    args+=("$arg")
done

echo "[$(date)] Running: docker compose exec -T php php vendor/bin/phpstan ${args[*]}" >> /tmp/phpstan-vscode-docker.log
docker compose exec -T php php vendor/bin/phpstan "${args[@]}" 2>> /tmp/phpstan-vscode-docker-err.log
