#!/usr/bin/env bash
# Container health: the last backup must have succeeded (or still be pending after start-up) and the status
# must be fresher than BACKUP_MAX_AGE_HOURS (default 26), so a silently stopped schedule is noticed too.
set -uo pipefail

status_file="${BACKUP_STATUS_FILE:-/var/lib/backup/last-status}"
max_age=$(( ${BACKUP_MAX_AGE_HOURS:-26} * 3600 ))

[ -f "$status_file" ] || { echo "no backup status recorded"; exit 1; }
read -r state stamp _ < "$status_file"
case "$state" in
  ok|pending) ;;
  *) echo "last backup: $state $stamp"; exit 1 ;;
esac
recorded="$(date -u -d "$stamp" +%s 2>/dev/null)" || { echo "unreadable backup status"; exit 1; }
age=$(( $(date -u +%s) - recorded ))
[ "$age" -le "$max_age" ] || { echo "backup status is ${age}s old"; exit 1; }
echo "$state $stamp"
