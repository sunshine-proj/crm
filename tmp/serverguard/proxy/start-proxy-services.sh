#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

case "${1:-status}" in
    start)
        "$SCRIPT_DIR/mitmproxy.sh" setup
        "$SCRIPT_DIR/mitmproxy.sh" start
        "$SCRIPT_DIR/squid.sh" setup
        "$SCRIPT_DIR/squid.sh" start
        ;;
    stop)
        "$SCRIPT_DIR/mitmproxy.sh" stop
        "$SCRIPT_DIR/squid.sh" stop
        ;;
    status)
        "$SCRIPT_DIR/mitmproxy.sh" status
        "$SCRIPT_DIR/squid.sh" status
        ;;
    *)
        echo "Usage: $0 {start|stop|status}"
        exit 1
        ;;
esac
