#!/bin/bash

# Watch for file changes and restart FrankenPHP workers
# This script uses inotifywait to monitor file changes and triggers
# the FrankenPHP admin API to restart workers

set -e

echo "Starting file watcher for FrankenPHP worker restart..."
echo "Monitoring /app directory (excluding node_modules, vendor, .git, var)"
echo "Press Ctrl+C to stop"

while true; do
    inotifywait -e modify -e move -e create -e delete -r /app \
        --exclude 'node_modules|vendor|.git|var' \
        --format '%w%f %e' |
    while read path event; do
        # Only restart for PHP and Twig files
        if [[ "$path" =~ \.(php|twig)$ ]]; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] File changed: $path ($event)"
            
            # Trigger worker restart via admin API
            if curl -s -X POST http://localhost:2019/frankenphp/workers/restart > /dev/null 2>&1; then
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] Workers restarted successfully"
            else
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] Failed to restart workers"
            fi
        fi
    done
    
    # Restart the inotifywait process if it exits (e.g., filesystem changes)
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Restarting file watcher..."
    sleep 1
done
