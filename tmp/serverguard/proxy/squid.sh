#!/usr/bin/env bash
set -Eeuo pipefail

SQUID_CONFIG_DIR="${SQUID_CONFIG_DIR:-/etc/serverguard/proxy/squid}"
SQUID_CONFIG_FILE="${SQUID_CONFIG_FILE:-${SQUID_CONFIG_DIR}/squid.conf}"
LOG_DIR="${LOG_DIR:-/var/log/serverguard/proxy}"
PID_FILE="${PID_FILE:-${LOG_DIR}/squid.pid}"
LOG_FILE="${LOG_FILE:-${LOG_DIR}/squid.log}"
LISTEN_PORT="${LISTEN_PORT:-3128}"
LISTEN_HOST="${LISTEN_HOST:-0.0.0.0}"

mkdir -p "$SQUID_CONFIG_DIR" "$LOG_DIR"

case "${1:-status}" in
    setup)
        echo "Creating Squid configuration: $SQUID_CONFIG_FILE"
        cat > "$SQUID_CONFIG_FILE" <<CONFIG
http_port ${LISTEN_HOST}:${LISTEN_PORT}
visible_hostname serverguard-proxy
cache deny all

# Allow localhost access by default.
auth_param basic program /usr/lib/squid/basic_ncsa_auth /etc/squid/passwords
acl localnet src 127.0.0.1/32 ::1/128
acl SSL_ports port 443
acl Safe_ports port 80
acl Safe_ports port 21
acl Safe_ports port 443
acl Safe_ports port 70
acl Safe_ports port 1025-65535
acl CONNECT method CONNECT

http_access allow localhost
http_access deny !Safe_ports
http_access deny CONNECT !SSL_ports
http_access allow localnet
http_access deny all

access_log stdio:
cache_log /var/log/squid/cache.log
error_log /var/log/squid/error.log
CONFIG
        echo "Configuration created at $SQUID_CONFIG_FILE"
        ;;
    start)
        if ! command -v squid >/dev/null 2>&1; then
            echo "squid is not installed. Run the ServerGuard installer first." >&2
            exit 1
        fi

        if [[ ! -f "$SQUID_CONFIG_FILE" ]]; then
            $0 setup
        fi

        if pgrep -f "squid.*-f.*${SQUID_CONFIG_FILE}" >/dev/null 2>&1; then
            echo "Squid is already running"
            exit 0
        fi

        squid -N -f "$SQUID_CONFIG_FILE" > "$LOG_FILE" 2>&1 &
        sleep 1
        SQUID_PID=$(pgrep -af "squid.*-f.*${SQUID_CONFIG_FILE}" | head -n 1 | awk '{print $1}' || true)

        if [[ -n "$SQUID_PID" ]]; then
            echo "$SQUID_PID" > "$PID_FILE"
            echo "Squid started with PID $SQUID_PID"
        else
            echo "Squid failed to start. Check logs: $LOG_FILE" >&2
            exit 1
        fi
        ;;
    stop)
        if [[ -f "$PID_FILE" ]]; then
            PID="$(cat "$PID_FILE" 2>/dev/null || true)"
            if [[ -n "$PID" ]] && kill -0 "$PID" 2>/dev/null; then
                kill "$PID"
                rm -f "$PID_FILE"
                echo "Squid stopped"
                exit 0
            fi
        fi

        PID=$(pgrep -af "squid.*-f.*${SQUID_CONFIG_FILE}" | head -n 1 | awk '{print $1}' || true)
        if [[ -n "$PID" ]]; then
            kill "$PID"
            echo "Squid stopped"
        else
            echo "Squid is not running"
        fi
        ;;
    status)
        if pgrep -f "squid.*-f.*${SQUID_CONFIG_FILE}" >/dev/null 2>&1; then
            echo "Squid is running on ${LISTEN_HOST}:${LISTEN_PORT}"
        else
            echo "Squid is stopped"
        fi
        ;;
    *)
        echo "Usage: $0 {setup|start|stop|status}"
        exit 1
        ;;
esac
