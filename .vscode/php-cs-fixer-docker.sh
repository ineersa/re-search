#!/bin/bash
# Wrapper script to run PHP CS Fixer inside Docker container
# Usage: ./php-cs-fixer-docker.sh [php-cs-fixer-args]

# Convert host paths to container paths
# The project is mounted at /app inside the container
# /tmp is also mounted to /tmp (see compose.override.yaml)
args=()
for arg in "$@"; do
    # Replace host project path with container path
    arg="${arg//\/home\/ineersa\/projects\/re-search/\/app}"
    args+=("$arg")
done

docker compose exec -u "$(id -u):$(id -g)" php php vendor/bin/php-cs-fixer "${args[@]}"
