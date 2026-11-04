#!/usr/bin/env bash
set -Eeuo pipefail

REPORT_DIR="${REPORT_DIR:-/var/log/serverguard}"
mkdir -p "$REPORT_DIR"
REPORT_FILE="$REPORT_DIR/lynis-$(date +%Y%m%d-%H%M%S).log"

if ! command -v lynis >/dev/null 2>&1; then
    echo "lynis is not installed. Run the ServerGuard installer first." >&2
    exit 1
fi

echo "=== ServerGuard Lynis Audit ===" | tee "$REPORT_FILE"
echo "Timestamp: $(date -Iseconds)" | tee -a "$REPORT_FILE"
lynis audit system --quick --no-colors 2>&1 | tee -a "$REPORT_FILE" || true

echo "Lynis report saved to: $REPORT_FILE"
