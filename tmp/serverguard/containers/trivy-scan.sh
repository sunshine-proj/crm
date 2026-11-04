#!/usr/bin/env bash
set -Eeuo pipefail

TARGET="${1:-.}"
REPORT_DIR="${REPORT_DIR:-/var/log/serverguard}"
mkdir -p "$REPORT_DIR"
REPORT_FILE="$REPORT_DIR/trivy-$(date +%Y%m%d-%H%M%S).log"

if ! command -v trivy >/dev/null 2>&1; then
    echo "trivy is not installed. Run the ServerGuard installer first." >&2
    exit 1
fi

echo "=== ServerGuard Trivy Scan ===" | tee "$REPORT_FILE"
trivy fs --security-checks vuln,config --format table "$TARGET" 2>&1 | tee -a "$REPORT_FILE" || true

echo "Trivy report saved to: $REPORT_FILE"
