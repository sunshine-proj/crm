#!/usr/bin/env bash
set -Eeuo pipefail

PROXY_URL="${PROXY_URL:-http://127.0.0.1:8080}"
TARGET_URL="${TARGET_URL:-https://example.com}"
TIMEOUT="${TIMEOUT:-15}"

if ! command -v curl >/dev/null 2>&1; then
    echo "curl is required but not installed." >&2
    exit 1
fi

if ! command -v mitmdump >/dev/null 2>&1; then
    echo "mitmdump is not installed. Ensure the ServerGuard installer has run." >&2
    exit 1
fi

echo "Testing mitmproxy at ${PROXY_URL}"
echo "Target URL: ${TARGET_URL}"
echo

echo "[1/2] HTTP through proxy"
curl -sS -m "$TIMEOUT" \
    --proxy "$PROXY_URL" \
    -I "$TARGET_URL" \
    -A "ServerGuard-MitM-Test" || true

echo

echo "[2/2] HTTPS through proxy (insecure MITM cert allowed)"
curl -sS -m "$TIMEOUT" \
    --proxy "$PROXY_URL" \
    -k \
    -L \
    -A "ServerGuard-MitM-Test" \
    "$TARGET_URL" | head -n 20 || true

echo

echo "If the proxy is active, requests should appear in mitmproxy's traffic history."
