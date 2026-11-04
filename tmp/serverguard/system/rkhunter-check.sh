#!/usr/bin/env bash
set -Eeuo pipefail

REPORT_DIR="${REPORT_DIR:-/var/log/serverguard}"
mkdir -p "$REPORT_DIR"
REPORT_FILE="$REPORT_DIR/rkhunter-check-$(date +%Y%m%d-%H%M%S).log"

if [[ ${EUID} -ne 0 ]]; then
    echo "This script must be run as root or with sudo." >&2
    exit 1
fi

if ! command -v rkhunter >/dev/null 2>&1; then
    echo "rkhunter is not installed. Ensure the ServerGuard installer has run." >&2
    exit 1
fi

echo "=== ServerGuard rkhunter Check ===" | tee "$REPORT_FILE"
echo "Timestamp: $(date -Iseconds)" | tee -a "$REPORT_FILE"

rkhunter --check --sk 2>&1 | tee -a "$REPORT_FILE" || true

echo "rkhunter check saved to: $REPORT_FILE"
