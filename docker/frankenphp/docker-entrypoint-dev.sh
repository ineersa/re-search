#!/bin/bash
set -e

# Start FrankenPHP in the background without --watch flag
# (we'll use our own file watcher)
frankenphp run --config /etc/caddy/Caddyfile &
FRANKENPHP_PID=$!

# Start the file watcher in the background
/usr/local/bin/watch-and-restart.sh &
WATCHER_PID=$!

echo "================================================"
echo "FrankenPHP started with PID: $FRANKENPHP_PID"
echo "File watcher started with PID: $WATCHER_PID"
echo "================================================"
echo "App is accessible at: http://localhost:8080"
echo "Admin API: http://localhost:2019"
echo "================================================"

# Function to handle shutdown
shutdown() {
    echo "Shutting down..."
    kill -TERM $FRANKENPHP_PID 2>/dev/null || true
    kill -TERM $WATCHER_PID 2>/dev/null || true
    wait $FRANKENPHP_PID 2>/dev/null || true
    wait $WATCHER_PID 2>/dev/null || true
    echo "Shutdown complete"
    exit 0
}

# Trap signals
trap shutdown SIGTERM SIGINT

# Wait for both processes
wait -n $FRANKENPHP_PID $WATCHER_PID

# If one process exited, shut down the other
shutdown
