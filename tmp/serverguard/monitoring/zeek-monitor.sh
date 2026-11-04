#!/usr/bin/env bash
set -Eeuo pipefail

REPORT_DIR="${REPORT_DIR:-/var/log/serverguard}"
mkdir -p "$REPORT_DIR"
REPORT_FILE="$REPORT_DIR/zeek-$(date +%Y%m%d-%H%M%S).log"

if ! command -v zeek >/dev/null 2>&1; then
    echo "zeek is not installed. Run the ServerGuard installer first." >&2
    exit 1
fi

echo "=== ServerGuard Zeek Monitor ===" | tee "$REPORT_FILE"
zeek -v 2>&1 | tee -a "$REPORT_FILE" || true

echo "Zeek is ready for network logging. See /var/log/zeek for output." | tee -a "$REPORT_FILE"
