#!/usr/bin/env bash
set -Eeuo pipefail

TARGET="${1:-https://example.com}"
REPORT_DIR="${REPORT_DIR:-/var/log/serverguard}"
mkdir -p "$REPORT_DIR"
REPORT_FILE="$REPORT_DIR/zap-audit-$(date +%Y%m%d-%H%M%S).log"

if ! command -v zaproxy >/dev/null 2>&1; then
    echo "OWASP ZAP is not installed. Ensure the ServerGuard installer has run." >&2
    exit 1
fi

echo "=== ServerGuard OWASP ZAP Audit ===" | tee "$REPORT_FILE"
echo "Target: $TARGET" | tee -a "$REPORT_FILE"
echo "Timestamp: $(date -Iseconds)" | tee -a "$REPORT_FILE"

echo "Running ZAP baseline scan..." | tee -a "$REPORT_FILE"

zaproxy -cmd \
    -quickurl "$TARGET" \
    -quickout "$REPORT_DIR/zap-baseline-$(date +%Y%m%d-%H%M%S).html" \
    -quickprogress false 2>&1 | tee -a "$REPORT_FILE" || true

echo "ZAP audit saved to: $REPORT_FILE"
