#!/usr/bin/env bash
set -Eeuo pipefail

PROFILE="${1:-cis_debian12_L1}" 
REPORT_DIR="${REPORT_DIR:-/var/log/serverguard}"
mkdir -p "$REPORT_DIR"
REPORT_FILE="$REPORT_DIR/openscap-audit-$(date +%Y%m%d-%H%M%S).log"

if [[ ${EUID} -ne 0 ]]; then
    echo "This script must be run as root or with sudo." >&2
    exit 1
fi

if ! command -v oscap >/dev/null 2>&1; then
    echo "oscap is not installed. Ensure the ServerGuard installer has run." >&2
    exit 1
fi

# Try to locate a valid profile first.
PROFILE_PATH=""
for candidate in \
    /usr/share/xml/scap/ssg/content/ssg-debian*-ds.xml \
    /usr/share/xml/scap/ssg/content/ssg-debian*-xccdf.xml \
    /usr/share/xml/scap/ssg/content/ssg-*.xml
 do
    if [[ -f "$candidate" ]]; then
        PROFILE_PATH="$candidate"
        break
    fi
done

if [[ -z "$PROFILE_PATH" ]]; then
    echo "No SCAP benchmark content was found on this system." | tee "$REPORT_FILE"
    echo "Install the openscap content package and rerun the audit." | tee -a "$REPORT_FILE"
    exit 1
fi

echo "=== ServerGuard OpenSCAP Audit ===" | tee "$REPORT_FILE"
echo "Profile: $PROFILE" | tee -a "$REPORT_FILE"
echo "Timestamp: $(date -Iseconds)" | tee -a "$REPORT_FILE"

echo "Using content file: $PROFILE_PATH" | tee -a "$REPORT_FILE"

oscap info "$PROFILE_PATH" 2>&1 | tee -a "$REPORT_FILE" || true

echo "Running OpenSCAP evaluation..." | tee -a "$REPORT_FILE"

# If the requested profile is present, run it; otherwise fall back to a generic DS evaluation.
if oscap info "$PROFILE_PATH" 2>/dev/null | grep -qi "profile.*$PROFILE"; then
    oscap xccdf eval --profile "$PROFILE" --results "$REPORT_DIR/openscap-results-$(date +%Y%m%d-%H%M%S).xml" "$PROFILE_PATH" 2>&1 | tee -a "$REPORT_FILE" || true
else
    echo "Requested profile '$PROFILE' was not found; running a default evaluation." | tee -a "$REPORT_FILE"
    oscap xccdf eval --results "$REPORT_DIR/openscap-results-$(date +%Y%m%d-%H%M%S).xml" "$PROFILE_PATH" 2>&1 | tee -a "$REPORT_FILE" || true
fi

echo "OpenSCAP audit saved to: $REPORT_FILE"
