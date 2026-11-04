#!/usr/bin/env bash
set -Eeuo pipefail

TARGET="${1:-localhost}"
PORT="${2:-443}"
REPORT_DIR="${REPORT_DIR:-/var/log/serverguard}"
mkdir -p "$REPORT_DIR"
REPORT_FILE="$REPORT_DIR/tls-audit-$(date +%Y%m%d-%H%M%S).log"

echo "=== ServerGuard TLS Audit ===" | tee "$REPORT_FILE"
echo "Target: $TARGET:$PORT" | tee -a "$REPORT_FILE"
echo "Timestamp: $(date -Iseconds)" | tee -a "$REPORT_FILE"

echo "[1/3] OpenSSL certificate details" | tee -a "$REPORT_FILE"
openssl s_client -connect "$TARGET:$PORT" -servername "$TARGET" </dev/null 2>/dev/null | openssl x509 -noout -subject -issuer -dates -fingerprint -sha256 2>&1 | tee -a "$REPORT_FILE" || true

echo "[2/3] Cipher strength check" | tee -a "$REPORT_FILE"
openssl s_client -connect "$TARGET:$PORT" -tls1_2 -cipher 'HIGH:!aNULL:!MD5' </dev/null 2>/dev/null | tee -a "$REPORT_FILE" || true

echo "[3/3] testssl.sh scan" | tee -a "$REPORT_FILE"
if command -v testssl.sh >/dev/null 2>&1; then
    testssl.sh --quiet "$TARGET:$PORT" 2>&1 | tee -a "$REPORT_FILE" || true
else
    echo "testssl.sh is not installed." | tee -a "$REPORT_FILE"
fi

echo "TLS audit saved to: $REPORT_FILE"
