#!/bin/bash
# MuseDock Panel - Start Script
# Usage: ./musedock-panel.sh [start|stop|status]

PANEL_DIR="/opt/musedock-panel"
PID_FILE="${PANEL_DIR}/storage/panel.pid"
LOG_FILE="${PANEL_DIR}/storage/logs/panel.log"
PHP_BIN="/usr/bin/php"

# Read port from .env or default to 8444
if [ -f "${PANEL_DIR}/.env" ]; then
    PORT=$(grep -E '^PANEL_PORT=' "${PANEL_DIR}/.env" | cut -d= -f2 | tr -d ' "'"'"'')
fi
PORT=${PORT:-8444}

start() {
    if [ -f "$PID_FILE" ] && kill -0 $(cat "$PID_FILE") 2>/dev/null; then
        echo "MuseDock Panel is already running (PID: $(cat $PID_FILE))"
        return 1
    fi

    echo "Starting MuseDock Panel on port ${PORT}..."
    nohup $PHP_BIN -S 127.0.0.1:${PORT} -t "${PANEL_DIR}/public" "${PANEL_DIR}/public/router.php" >> "$LOG_FILE" 2>&1 &
    echo $! > "$PID_FILE"
    echo "Started (PID: $(cat $PID_FILE))"
}

stop() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if kill -0 "$PID" 2>/dev/null; then
            echo "Stopping MuseDock Panel (PID: $PID)..."
            kill "$PID"
            rm -f "$PID_FILE"
            echo "Stopped."
        else
            echo "Process not found. Cleaning up PID file."
            rm -f "$PID_FILE"
        fi
    else
        echo "MuseDock Panel is not running."
    fi
}

status() {
    if [ -f "$PID_FILE" ] && kill -0 $(cat "$PID_FILE") 2>/dev/null; then
        echo "MuseDock Panel is running (PID: $(cat $PID_FILE)) on port ${PORT}"
    else
        echo "MuseDock Panel is not running."
    fi
}

case "${1:-start}" in
    start)  start ;;
    stop)   stop ;;
    restart) stop; sleep 1; start ;;
    status) status ;;
    *) echo "Usage: $0 {start|stop|restart|status}" ;;
esac
