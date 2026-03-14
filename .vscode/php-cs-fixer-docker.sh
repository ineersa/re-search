#!/bin/bash
# Wrapper script to run PHP CS Fixer inside Docker container
# Usage: ./php-cs-fixer-docker.sh [php-cs-fixer-args]

docker compose exec -u "$(id -u):$(id -g)" php php vendor/bin/php-cs-fixer "$@"
