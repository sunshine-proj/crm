#!/usr/bin/env bash
set -Eeuo pipefail

IFACE="${1:-$(ip -o link show 2>/dev/null | awk -F': ' 'NR==1 {print $2}' || echo lo)}"
DURATION="${2:-30}"
OUTPUT_DIR="${OUTPUT_DIR:-/var/log/serverguard}"
mkdir -p "$OUTPUT_DIR"
PCAP_FILE="$OUTPUT_DIR/packet-capture-$(date +%Y%m%d-%H%M%S)-${IFACE}.pcap"

if [[ ${EUID} -ne 0 ]]; then
    echo "This script must be run as root or with sudo." >&2
    exit 1
fi

if ! command -v tcpdump >/dev/null 2>&1; then
    echo "tcpdump is not installed. Run the ServerGuard installer first." >&2
    exit 1
fi

echo "Capturing traffic on ${IFACE} for ${DURATION}s..."
tcpdump -i "$IFACE" -w "$PCAP_FILE" -s 0 -c 500 2>/dev/null &
CAPTURE_PID=$!
sleep "$DURATION"
kill "$CAPTURE_PID" 2>/dev/null || true
wait "$CAPTURE_PID" 2>/dev/null || true

echo "Capture saved to: $PCAP_FILE"
