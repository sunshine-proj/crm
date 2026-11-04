#!/usr/bin/env bash
set -Eeuo pipefail

PROXY_URL="${PROXY_URL:-http://127.0.0.1:3128}"
TARGET_URL="${TARGET_URL:-http://example.com}"
TIMEOUT="${TIMEOUT:-15}"

if ! command -v curl >/dev/null 2>&1; then
    echo "curl is required but not installed." >&2
    exit 1
fi

if ! command -v squid >/dev/null 2>&1; then
    echo "squid is not installed. Ensure the ServerGuard installer has run." >&2
    exit 1
fi

echo "Testing Squid proxy at ${PROXY_URL}"
echo "Target URL: ${TARGET_URL}"
echo

curl -sS -m "$TIMEOUT" \
    --proxy "$PROXY_URL" \
    -I "$TARGET_URL" \
    -A "ServerGuard-Squid-Test" || true

echo

echo "If Squid is active, the request should pass through the configured proxy listener."
