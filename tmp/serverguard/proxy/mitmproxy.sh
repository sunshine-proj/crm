#!/usr/bin/env bash
set -Eeuo pipefail

LISTEN_HOST="${LISTEN_HOST:-0.0.0.0}"
LISTEN_PORT="${LISTEN_PORT:-8080}"
LOG_DIR="${LOG_DIR:-/var/log/serverguard/proxy}"
CONF_DIR="${CONF_DIR:-/etc/serverguard/proxy/mitmproxy}"
PID_FILE="${PID_FILE:-${LOG_DIR}/mitmproxy.pid}"
LOG_FILE="${LOG_FILE:-${LOG_DIR}/mitmproxy.log}"

mkdir -p "$LOG_DIR" "$CONF_DIR"

if [[ ! -d "$CONF_DIR" ]]; then
    mkdir -p "$CONF_DIR"
fi

case "${1:-status}" in
    setup)
        echo "Creating mitmproxy configuration directory: $CONF_DIR"
        cat > "$CONF_DIR/mitmproxy.conf" <<CONFIG
[mitmproxy]
listen_host = ${LISTEN_HOST}
listen_port = ${LISTEN_PORT}
showhost = true
stream_large_bodies = 1000000
CONFIG
        echo "Configuration created at $CONF_DIR/mitmproxy.conf"
        ;;
    start)
        if pgrep -f "mitmdump.*--listen-port ${LISTEN_PORT}" >/dev/null 2>&1; then
            echo "mitmproxy is already running on port ${LISTEN_PORT}"
            exit 0
        fi

        if ! command -v mitmdump >/dev/null 2>&1; then
            echo "mitmdump is not installed. Run the ServerGuard installer first." >&2
            exit 1
        fi

        echo "Starting mitmproxy on ${LISTEN_HOST}:${LISTEN_PORT}"
        nohup mitmdump \
            --listen-host "$LISTEN_HOST" \
            --listen-port "$LISTEN_PORT" \
            --set confdir="$CONF_DIR" \
            --set stream_large_bodies=1000000 \
            --set ssl_insecure=true \
            > "$LOG_FILE" 2>&1 &

        echo $! > "$PID_FILE"
        sleep 1
        echo "mitmproxy started. PID: $(cat "$PID_FILE")"
        echo "Logs: $LOG_FILE"
        ;;
    stop)
        if [[ -f "$PID_FILE" ]]; then
            PID="$(cat "$PID_FILE" 2>/dev/null || true)"
            if [[ -n "${PID}" ]] && kill -0 "$PID" 2>/dev/null; then
                kill "$PID"
                rm -f "$PID_FILE"
                echo "mitmproxy stopped"
                exit 0
            fi
            rm -f "$PID_FILE"
        fi

        PID=$(pgrep -f "mitmdump.*--listen-port ${LISTEN_PORT}" | head -n 1 || true)
        if [[ -n "$PID" ]]; then
            kill "$PID"
            echo "mitmproxy stopped"
        else
            echo "mitmproxy is not running"
        fi
        ;;
    status)
        if pgrep -f "mitmdump.*--listen-port ${LISTEN_PORT}" >/dev/null 2>&1; then
            echo "mitmproxy is running on ${LISTEN_HOST}:${LISTEN_PORT}"
        else
            echo "mitmproxy is stopped"
        fi
        ;;
    *)
        echo "Usage: $0 {setup|start|stop|status}"
        exit 1
        ;;
esac
