#!/usr/bin/env bash
set -Eeuo pipefail

REPORT_NAME="${1:-serverguard-report}"
OUTPUT_DIR="${2:-/var/log/serverguard/reports}"
mkdir -p "$OUTPUT_DIR"

cat > "$OUTPUT_DIR/${REPORT_NAME}.json" <<JSON
{
  "report_name": "${REPORT_NAME}",
  "generated_at": "$(date -Iseconds)",
  "target": "$(hostname)",
  "modules": [
    "system",
    "network",
    "proxy",
    "monitoring",
    "web",
    "tls",
    "containers"
  ]
}
JSON

echo "JSON report created at $OUTPUT_DIR/${REPORT_NAME}.json"
