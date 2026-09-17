#!/usr/bin/env bash

set -euo pipefail

# ============================================================
# Symfony Audit Log + rsyslog + logrotate setup
# ============================================================

# Configuration
RSYSLOG_CONFIG="/etc/rsyslog.d/app-audit.conf"
LOGROTATE_CONFIG="/etc/logrotate.d/app-audit"

LOG_DIR="/var/log/app"
LOG_FILE="${LOG_DIR}/audit.log"

LOG_USER="www-data"
LOG_GROUP="www-data"

echo "============================================================"
echo " Symfony Audit Log - rsyslog setup"
echo "============================================================"
echo

# ------------------------------------------------------------
# Check root privileges
# ------------------------------------------------------------

if [[ "${EUID}" -ne 0 ]]; then
    echo "ERROR: This script must be run as root."
    echo
    echo "Run:"
    echo "  sudo $0"
    exit 1
fi

# ------------------------------------------------------------
# Check required commands
# ------------------------------------------------------------

for command in rsyslogd logger systemctl logrotate; do
    if ! command -v "${command}" >/dev/null 2>&1; then
        echo "ERROR: Required command '${command}' was not found."
        echo
        echo "Install rsyslog and logrotate first."
        exit 1
    fi
done

# ------------------------------------------------------------
# Create log directory
# ------------------------------------------------------------

echo "==> Creating log directory: ${LOG_DIR}"

mkdir -p "${LOG_DIR}"

# ------------------------------------------------------------
# Create audit log
# ------------------------------------------------------------

echo "==> Creating audit log: ${LOG_FILE}"

touch "${LOG_FILE}"

# ------------------------------------------------------------
# Set ownership and permissions
# ------------------------------------------------------------

echo "==> Setting log permissions..."

chown "${LOG_USER}:${LOG_GROUP}" "${LOG_FILE}"
chmod 0640 "${LOG_FILE}"

# Make sure the directory is accessible to rsyslog
chown "${LOG_USER}:${LOG_GROUP}" "${LOG_DIR}"
chmod 0750 "${LOG_DIR}"

# ------------------------------------------------------------
# Configure rsyslog
# ------------------------------------------------------------

echo "==> Creating rsyslog configuration:"
echo "    ${RSYSLOG_CONFIG}"

cat > "${RSYSLOG_CONFIG}" <<'EOF'
# ============================================================
# Symfony application audit logs
# ============================================================
#
# Symfony/Monolog sends audit messages using the local0
# syslog facility.
#
# Example:
#   local0.info
#
# All local0 messages are written to the audit log.
# ============================================================

local0.*    /var/log/app/audit.log
EOF

# ------------------------------------------------------------
# Validate rsyslog configuration
# ------------------------------------------------------------

echo "==> Validating rsyslog configuration..."

rsyslogd -N1

# ------------------------------------------------------------
# Configure logrotate
# ------------------------------------------------------------

echo "==> Creating logrotate configuration:"
echo "    ${LOGROTATE_CONFIG}"

cat > "${LOGROTATE_CONFIG}" <<EOF
/var/log/app/audit.log {
    daily
    rotate 30
    maxsize 100M

    compress
    delaycompress

    missingok
    notifempty

    create 0640 ${LOG_USER} ${LOG_GROUP}

    sharedscripts

    postrotate
        systemctl reload rsyslog >/dev/null 2>&1 || true
    endscript
}
EOF

# ------------------------------------------------------------
# Validate logrotate configuration
# ------------------------------------------------------------

echo "==> Validating logrotate configuration..."

logrotate -d "${LOGROTATE_CONFIG}" >/dev/null

echo "    Logrotate configuration is valid."

# ------------------------------------------------------------
# Enable and restart rsyslog
# ------------------------------------------------------------

echo "==> Enabling rsyslog..."

systemctl enable rsyslog >/dev/null

echo "==> Restarting rsyslog..."

systemctl restart rsyslog

# ------------------------------------------------------------
# Test rsyslog
# ------------------------------------------------------------

echo "==> Sending test audit message..."

TEST_MESSAGE="rsyslog Symfony audit test $(date '+%Y-%m-%d %H:%M:%S')"

logger -p local0.info -t app-audit "${TEST_MESSAGE}"

sleep 1

# ------------------------------------------------------------
# Verify test message
# ------------------------------------------------------------

if grep -qF "${TEST_MESSAGE}" "${LOG_FILE}"; then

    echo
    echo "============================================================"
    echo " SUCCESS"
    echo "============================================================"
    echo
    echo "Rsyslog audit logging is configured successfully."
    echo
    echo "Rsyslog configuration:"
    echo "  ${RSYSLOG_CONFIG}"
    echo
    echo "Logrotate configuration:"
    echo "  ${LOGROTATE_CONFIG}"
    echo
    echo "Audit log:"
    echo "  ${LOG_FILE}"
    echo
    echo "Facility:"
    echo "  local0"
    echo
    echo "Rotation:"
    echo "  Daily"
    echo "  Keep 30 rotated logs"
    echo "  Rotate early at 100 MB"
    echo "  Compress old logs"
    echo
    echo "Test message:"
    echo "  ${TEST_MESSAGE}"
    echo
    echo "============================================================"

else

    echo
    echo "============================================================"
    echo " ERROR"
    echo "============================================================"
    echo
    echo "The rsyslog test message was not found in:"
    echo "  ${LOG_FILE}"
    echo
    echo "Check rsyslog status:"
    echo "  systemctl status rsyslog"
    echo
    echo "Check rsyslog logs:"
    echo "  journalctl -u rsyslog -n 50"
    echo
    echo "Check audit log:"
    echo "  tail -50 ${LOG_FILE}"
    echo
    exit 1

fi
