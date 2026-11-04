#!/usr/bin/env bash
set -Eeuo pipefail

REPORT_NAME="${1:-serverguard-report}"
OUTPUT_DIR="${2:-/var/log/serverguard/reports}"
mkdir -p "$OUTPUT_DIR"

cat > "$OUTPUT_DIR/${REPORT_NAME}.html" <<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <title>${REPORT_NAME}</title>
</head>
<body>
  <h1>ServerGuard Security Report</h1>
  <p>Target: $(hostname)</p>
  <p>Generated: $(date -Iseconds)</p>
  <table>
    <tr><th>Module</th><th>Status</th></tr>
    <tr><td>System Security</td><td>Collected</td></tr>
    <tr><td>Network Analysis</td><td>Collected</td></tr>
    <tr><td>Proxy Services</td><td>Collected</td></tr>
    <tr><td>Web Security</td><td>Collected</td></tr>
    <tr><td>TLS Security</td><td>Collected</td></tr>
    <tr><td>Container Security</td><td>Collected</td></tr>
  </table>
</body>
</html>
HTML

echo "HTML report created at $OUTPUT_DIR/${REPORT_NAME}.html"
