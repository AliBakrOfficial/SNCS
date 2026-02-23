#!/bin/bash
# =====================================================
# SNCS — Start WebSocket Server
# =====================================================
# Usage: bash scripts/start-ws.sh [--daemon]
# =====================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
WS_SCRIPT="$PROJECT_ROOT/backend/websocket/server.php"
LOG_DIR="$PROJECT_ROOT/logs"
PID_FILE="$LOG_DIR/ws-server.pid"

mkdir -p "$LOG_DIR"

# Check PHP
if ! command -v php &> /dev/null; then
    echo "ERROR: PHP is not installed or not in PATH"
    exit 1
fi

# Check if already running
if [ -f "$PID_FILE" ]; then
    existing_pid=$(cat "$PID_FILE")
    if kill -0 "$existing_pid" 2>/dev/null; then
        echo "WebSocket server already running (PID: $existing_pid)"
        echo "Stop it first: kill $existing_pid"
        exit 1
    fi
    rm -f "$PID_FILE"
fi

echo "╔══════════════════════════════════════════════╗"
echo "║  Starting SNCS WebSocket Server              ║"
echo "╚══════════════════════════════════════════════╝"

if [ "${1:-}" = "--daemon" ]; then
    nohup php "$WS_SCRIPT" >> "$LOG_DIR/ws-server.log" 2>&1 &
    echo $! > "$PID_FILE"
    echo "Server started in background (PID: $!)"
    echo "Logs: $LOG_DIR/ws-server.log"
else
    php "$WS_SCRIPT"
fi
