#!/usr/bin/env bash
set -Eeuo pipefail

TARGET="${1:-https://localhost}"
REPORT_DIR="${REPORT_DIR:-/var/log/serverguard}"
mkdir -p "$REPORT_DIR"
REPORT_FILE="$REPORT_DIR/web-audit-$(date +%Y%m%d-%H%M%S).log"

if ! command -v curl >/dev/null 2>&1; then
    echo "curl is required but not installed." >&2
    exit 1
fi

echo "=== ServerGuard Web Audit ===" | tee "$REPORT_FILE"
echo "Target: $TARGET" | tee -a "$REPORT_FILE"
echo "Timestamp: $(date -Iseconds)" | tee -a "$REPORT_FILE"

echo "[1/3] HTTP headers" | tee -a "$REPORT_FILE"
curl -I -L --max-redirs 5 --connect-timeout 10 -A "ServerGuard Web Audit" "$TARGET" 2>&1 | tee -a "$REPORT_FILE" || true

echo "[2/3] TLS certificate check" | tee -a "$REPORT_FILE"
openssl s_client -connect "${TARGET#https://}" -servername "${TARGET#https://}" </dev/null 2>/dev/null | openssl x509 -noout -subject -issuer -dates 2>&1 | tee -a "$REPORT_FILE" || true

echo "[3/3] Nikto scan" | tee -a "$REPORT_FILE"
if command -v nikto >/dev/null 2>&1; then
    nikto -h "$TARGET" -Format txt -output "$REPORT_FILE.nikto.txt" 2>&1 | tee -a "$REPORT_FILE" || true
else
    echo "nikto is not installed." | tee -a "$REPORT_FILE"
fi

echo "Web audit saved to: $REPORT_FILE"
