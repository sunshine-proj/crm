#!/usr/bin/env bash
# Daily encrypted backup of the PostgreSQL database and uploaded project documents.
#
# Required environment:
#   BACKUP_AGE_RECIPIENT  age public key (age1...). The private key must NOT be stored on this host.
#   BACKUP_REMOTE         rclone destination off this host, e.g. "s3backup:crm-backups/prod".
#                         Configure the remote through RCLONE_CONFIG_<NAME>_* variables or a mounted rclone.conf.
# Optional: PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE, DOCUMENTS_DIR, BACKUP_RETENTION_DAYS (default 35),
#           BACKUP_WORK_DIR, BACKUP_STATUS_FILE.
set -Eeuo pipefail

: "${BACKUP_AGE_RECIPIENT:?BACKUP_AGE_RECIPIENT is required}"
: "${BACKUP_REMOTE:?BACKUP_REMOTE is required}"
export PGHOST="${PGHOST:-postgres}" PGPORT="${PGPORT:-5432}" PGUSER="${PGUSER:-app}" PGDATABASE="${PGDATABASE:-app}"
DOCUMENTS_DIR="${DOCUMENTS_DIR:-/data/project-documents}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-35}"
WORK_DIR="${BACKUP_WORK_DIR:-/tmp/crm-backup}"
STATUS_FILE="${BACKUP_STATUS_FILE:-/var/lib/backup/last-status}"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
started="$(date -u +%s)"
mkdir -p "$WORK_DIR" "$(dirname "$STATUS_FILE")"
trap 'rm -rf "$WORK_DIR"; ' EXIT
trap 'echo "failed $(date -u +%FT%TZ)" > "$STATUS_FILE"; echo "backup FAILED" >&2' ERR

db_file="$WORK_DIR/db-$stamp.dump.age"
docs_file="$WORK_DIR/documents-$stamp.tar.gz.age"

# Custom-format dump is consistent (single snapshot) and restorable with pg_restore.
pg_dump --format=custom --no-owner --compress=6 | age -r "$BACKUP_AGE_RECIPIENT" -o "$db_file"

if [ -d "$DOCUMENTS_DIR" ]; then
  tar -C "$DOCUMENTS_DIR" -czf - . | age -r "$BACKUP_AGE_RECIPIENT" -o "$docs_file"
else
  echo "warning: $DOCUMENTS_DIR not found; no documents archived" >&2
fi

( cd "$WORK_DIR" && sha256sum ./*.age > "SHA256SUMS-$stamp" )
rclone copy "$WORK_DIR" "$BACKUP_REMOTE/$stamp" --checksum

# Verify the off-host copy matches before reporting success or pruning anything.
rclone check "$WORK_DIR" "$BACKUP_REMOTE/$stamp" --one-way

# Retention: remove backup sets older than the retention window (35 days by default).
rclone delete "$BACKUP_REMOTE" --min-age "${RETENTION_DAYS}d" --rmdirs

echo "ok $(date -u +%FT%TZ) set=$stamp duration=$(( $(date -u +%s) - started ))s" > "$STATUS_FILE"
cat "$STATUS_FILE"
