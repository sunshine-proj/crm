#!/usr/bin/env bash
set -Eeuo pipefail

REPORT_NAME="${1:-serverguard-report}"
OUTPUT_DIR="${2:-/var/log/serverguard/reports}"
mkdir -p "$OUTPUT_DIR"

if ! command -v python3 >/dev/null 2>&1; then
    echo "python3 is required for PDF generation." >&2
    exit 1
fi

python3 - <<'PY'
import os
from pathlib import Path

name = os.environ.get('REPORT_NAME', 'serverguard-report')
out_dir = Path(os.environ.get('OUTPUT_DIR', '/var/log/serverguard/reports'))
out_dir.mkdir(parents=True, exist_ok=True)
path = out_dir / f"{name}.pdf"
path.write_bytes(b"%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 144] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n4 0 obj\n<< /Length 52 >>\nstream\nBT /F1 18 Tf 50 80 Td (ServerGuard Report) Tj ET\nendstream\nendobj\n5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\nxref\n0 6\n0000000000 65535 f \n0000000010 00000 n \n0000000063 00000 n \n0000000120 00000 n \n0000000278 00000 n \n0000000810 00000 n \ntrailer\n<< /Root 1 0 R /Size 6 >>\nstartxref\n890\n%%EOF\n")
print(f"PDF report created at {path}")
PY

echo "PDF report created at $OUTPUT_DIR/${REPORT_NAME}.pdf"
