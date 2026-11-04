#!/usr/bin/env bash
set -Eeuo pipefail

TARGET="${1:-localhost}"
PORT="${2:-443}"
REPORT_DIR="${REPORT_DIR:-/var/log/serverguard}"
mkdir -p "$REPORT_DIR"
REPORT_FILE="$REPORT_DIR/testssl-$(date +%Y%m%d-%H%M%S).log"

if ! command -v testssl.sh >/dev/null 2>&1; then
    echo "testssl.sh is not installed. Run the ServerGuard installer first." >&2
    exit 1
fi

testssl.sh --quiet "$TARGET:$PORT" 2>&1 | tee "$REPORT_FILE" || true

echo "TLS test report saved to: $REPORT_FILE"
