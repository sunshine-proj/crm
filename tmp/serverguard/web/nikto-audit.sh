#!/usr/bin/env bash
set -Eeuo pipefail

TARGET="${1:-https://example.com}"
REPORT_DIR="${REPORT_DIR:-/var/log/serverguard}"
mkdir -p "$REPORT_DIR"
REPORT_FILE="$REPORT_DIR/nikto-$(date +%Y%m%d-%H%M%S).log"

if ! command -v nikto >/dev/null 2>&1; then
    echo "nikto is not installed. Run the ServerGuard installer first." >&2
    exit 1
fi

nikto -h "$TARGET" -Format txt -output "$REPORT_FILE" 2>&1 | tee -a "$REPORT_FILE" || true

echo "Nikto report saved to: $REPORT_FILE"
