#!/usr/bin/env bash
set -Eeuo pipefail

if ! command -v conntrack >/dev/null 2>&1; then
    echo "conntrack is not installed. Run the ServerGuard installer first." >&2
    exit 1
fi

conntrack -L 2>&1 | head -n 200
