#!/usr/bin/env bash
# Runs backup.sh once a day at BACKUP_HOUR_UTC (default 02) and records failures; the container exits
# on startup misconfiguration so a missing key or remote is noticed immediately.
set -uo pipefail

: "${BACKUP_AGE_RECIPIENT:?BACKUP_AGE_RECIPIENT is required}"
: "${BACKUP_REMOTE:?BACKUP_REMOTE is required}"
hour="${BACKUP_HOUR_UTC:-02}"

while true; do
  now="$(date -u +%s)"
  next="$(date -u -d "today ${hour}:00" +%s)"
  [ "$next" -le "$now" ] && next="$(date -u -d "tomorrow ${hour}:00" +%s)"
  sleep "$(( next - now ))"
  /usr/local/bin/backup.sh || echo "backup run failed at $(date -u +%FT%TZ)" >&2
done
