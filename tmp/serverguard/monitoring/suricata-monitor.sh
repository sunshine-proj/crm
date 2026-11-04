#!/usr/bin/env bash
set -Eeuo pipefail

REPORT_DIR="${REPORT_DIR:-/var/log/serverguard}"
mkdir -p "$REPORT_DIR"
REPORT_FILE="$REPORT_DIR/suricata-$(date +%Y%m%d-%H%M%S).log"

if ! command -v suricata >/dev/null 2>&1; then
    echo "suricata is not installed. Run the ServerGuard installer first." >&2
    exit 1
fi

echo "=== ServerGuard Suricata Monitor ===" | tee "$REPORT_FILE"
suricata -T 2>&1 | tee -a "$REPORT_FILE" || true
suricata -S /etc/suricata/rules/*.rules -i any 2>&1 | tee -a "$REPORT_FILE" || true

echo "Suricata log saved to: $REPORT_FILE"
