#!/usr/bin/env bash
# Restore test: fetch one backup set, decrypt it, restore the database into a scratch database and
# verify the archive. Never restores over the live database. Prints elapsed time to compare with the 24 h RTO.
#
# Usage: restore.sh <backup-set-stamp|latest> <age-identity-file> [scratch-db-name]
# Required environment: BACKUP_REMOTE, and PG* variables with rights to create/drop the scratch database.
set -Eeuo pipefail

set_name="${1:?backup set (e.g. 20261103T020000Z) or 'latest'}"
identity="${2:?path to the age identity (private key) file}"
scratch_db="${3:-crm_restore_test}"
: "${BACKUP_REMOTE:?BACKUP_REMOTE is required}"
export PGHOST="${PGHOST:-postgres}" PGPORT="${PGPORT:-5432}" PGUSER="${PGUSER:-app}"
WORK_DIR="${BACKUP_WORK_DIR:-/tmp/crm-restore}"
started="$(date -u +%s)"

if [ "$scratch_db" = "${PGDATABASE:-app}" ]; then
  echo "refusing to restore into the live database '$scratch_db'" >&2
  exit 2
fi
if [ "$set_name" = "latest" ]; then
  set_name="$(rclone lsf --dirs-only "$BACKUP_REMOTE" | tr -d / | sort | tail -n 1)"
fi
[ -n "$set_name" ] || { echo "no backup sets found" >&2; exit 1; }

rm -rf "$WORK_DIR"; mkdir -p "$WORK_DIR"
trap 'rm -rf "$WORK_DIR"' EXIT
rclone copy "$BACKUP_REMOTE/$set_name" "$WORK_DIR" --checksum
( cd "$WORK_DIR" && sha256sum --check "SHA256SUMS-$set_name" )

age -d -i "$identity" -o "$WORK_DIR/db.dump" "$WORK_DIR/db-$set_name.dump.age"
dropdb --if-exists "$scratch_db"
createdb "$scratch_db"
pg_restore --no-owner --exit-on-error -d "$scratch_db" "$WORK_DIR/db.dump"

tables="$(psql -d "$scratch_db" -Atc "select count(*) from information_schema.tables where table_schema='public'")"
migrations="$(psql -d "$scratch_db" -Atc "select count(*) from doctrine_migration_versions")"
invoices="$(psql -d "$scratch_db" -Atc "select count(*) from invoice" 2>/dev/null || echo n/a)"

if [ -f "$WORK_DIR/documents-$set_name.tar.gz.age" ]; then
  age -d -i "$identity" -o "$WORK_DIR/documents.tar.gz" "$WORK_DIR/documents-$set_name.tar.gz.age"
  docs="$(tar -tzf "$WORK_DIR/documents.tar.gz" | grep -vc '/$' || true)"
else
  docs="missing"
fi

echo "restore verified: set=$set_name tables=$tables migrations=$migrations invoices=$invoices document_files=$docs"
echo "elapsed: $(( $(date -u +%s) - started ))s (RTO target: 86400s)"
echo "Drop the scratch database when finished: dropdb $scratch_db"
