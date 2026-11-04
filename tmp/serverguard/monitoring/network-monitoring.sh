#!/usr/bin/env bash
set -Eeuo pipefail

IFACE="${1:-$(ip -o link show 2>/dev/null | awk -F': ' 'NR==1 {print $2}' || echo lo)}"
DURATION="${2:-10}"
OUTPUT_DIR="${OUTPUT_DIR:-/var/log/serverguard/monitoring}"
mkdir -p "$OUTPUT_DIR"

if [[ ${EUID} -ne 0 ]]; then
    echo "This script must be run as root or with sudo." >&2
    exit 1
fi

if ! command -v tcpdump >/dev/null 2>&1; then
    echo "tcpdump is not installed. Run the ServerGuard installer first." >&2
    exit 1
fi

PCAP_FILE="$OUTPUT_DIR/network-monitor-$(date +%Y%m%d-%H%M%S)-${IFACE}.pcap"
LOG_FILE="$OUTPUT_DIR/network-monitor-$(date +%Y%m%d-%H%M%S)-${IFACE}.log"

echo "Starting network monitoring on interface: $IFACE" | tee "$LOG_FILE"
echo "Capture duration: ${DURATION}s" | tee -a "$LOG_FILE"
echo "PCAP output: $PCAP_FILE" | tee -a "$LOG_FILE"

tcpdump -i "$IFACE" -w "$PCAP_FILE" -c 200 -s 0 "not arp" &
CAPTURE_PID=$!
sleep "$DURATION"
kill "$CAPTURE_PID" 2>/dev/null || true
wait "$CAPTURE_PID" 2>/dev/null || true

echo "[1/4] Active network connections" | tee -a "$LOG_FILE"
ss -tulpn | tee -a "$LOG_FILE"

echo "[2/4] Interface status" | tee -a "$LOG_FILE"
ip -brief addr show 2>/dev/null | tee -a "$LOG_FILE" || ip addr show | tee -a "$LOG_FILE"

echo "[3/4] TCP conversations" | tee -a "$LOG_FILE"
if command -v tshark >/dev/null 2>&1; then
    tshark -r "$PCAP_FILE" -q -z conv,tcp 2>/dev/null | tee -a "$LOG_FILE" || true
else
    echo "tshark is not installed; skipping conversation summary." | tee -a "$LOG_FILE"
fi

echo "[4/4] Protocol hierarchy" | tee -a "$LOG_FILE"
if command -v tshark >/dev/null 2>&1; then
    tshark -r "$PCAP_FILE" -q -z io,phs 2>/dev/null | tee -a "$LOG_FILE" || true
else
    echo "tshark is not installed; packet capture is available at ${PCAP_FILE}." | tee -a "$LOG_FILE"
fi

echo "Network monitor saved to: $LOG_FILE" | tee -a "$LOG_FILE"
