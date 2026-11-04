#!/usr/bin/env bash
set -Eeuo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "This script must be run as root or with sudo." >&2
    exit 1
fi

if ! command -v aide >/dev/null 2>&1; then
    echo "AIDE is not installed. Ensure the ServerGuard installer has run." >&2
    exit 1
fi

echo "Initializing AIDE baseline..."
aideinit -y

echo "Baseline initialized. Review /etc/aide/aide.conf and rerun with:"
echo "sudo aide --check"
