#!/usr/bin/env bash
set -Eeuo pipefail

TARGET="${1:-localhost}"
REPORT_DIR="${REPORT_DIR:-/var/log/serverguard}"
mkdir -p "$REPORT_DIR"
REPORT_FILE="$REPORT_DIR/network-audit-$(date +%Y%m%d-%H%M%S).log"

if [[ ${EUID} -ne 0 ]]; then
    echo "This script must be run as root or with sudo." >&2
    exit 1
fi

if ! command -v nmap >/dev/null 2>&1; then
    echo "nmap is not installed. Run the ServerGuard installer first." >&2
    exit 1
fi

echo "=== ServerGuard Network Audit ===" | tee "$REPORT_FILE"
echo "Target: $TARGET" | tee -a "$REPORT_FILE"
echo "Timestamp: $(date -Iseconds)" | tee -a "$REPORT_FILE"
ss -tulpn | tee -a "$REPORT_FILE"
ip -brief addr show 2>/dev/null | tee -a "$REPORT_FILE" || ip addr show | tee -a "$REPORT_FILE"
ip route show 2>/dev/null | tee -a "$REPORT_FILE" || route -n | tee -a "$REPORT_FILE"
nmap -sV -T4 -F "$TARGET" 2>&1 | tee -a "$REPORT_FILE" || true

echo "Network audit saved to: $REPORT_FILE"
