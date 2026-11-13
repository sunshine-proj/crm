#!/usr/bin/env bash
# Backup/restore drill: builds the backup image, takes an encrypted backup of a running database into a
# local folder standing in for the off-host remote, restores it into a scratch database and compares row
# counts with the source. The source database is only read; the scratch database is dropped afterwards.
#
# This proves the scripts and the encryption round trip. It does NOT prove the off-host destination:
# repeat the restore test in docs/BACKUP.md against the real remote and record it in the restore log.
#
# Usage: DOCKER_NETWORK=crm_app PGPASSWORD=... [PGHOST=postgres PGUSER=app PGDATABASE=app] \
#        [DOCUMENTS_DIR=src/var/project-documents] scripts/verify-backup-drill.sh
# Requires: docker. The database user must be allowed to create and drop the scratch database.
set -Eeuo pipefail

: "${DOCKER_NETWORK:?DOCKER_NETWORK is required (docker network of the PostgreSQL container)}"
: "${PGPASSWORD:?PGPASSWORD is required}"
PGHOST="${PGHOST:-postgres}" PGUSER="${PGUSER:-app}" PGDATABASE="${PGDATABASE:-app}"
SCRATCH_DB="${SCRATCH_DB:-crm_restore_drill}"
root="$(cd "$(dirname "$0")/.." && pwd)"
documents="${DOCUMENTS_DIR:-$root/src/var/project-documents}"
work="$(mktemp -d)"
image="crm-backup:drill"
failures=0
check() { if [ "$2" = "$3" ]; then echo "PASS  $1"; else echo "FAIL  $1 (expected '$3', got '$2')"; failures=$((failures + 1)); fi; }

mkdir -p "$work/remote" "$work/status" "$work/key" "$work/empty"
[ -d "$documents" ] || documents="$work/empty"
docker build -q -t "$image" "$root/docker/backup" >/dev/null
run() {
  docker run --rm --network "$DOCKER_NETWORK" -e PGHOST="$PGHOST" -e PGUSER="$PGUSER" -e PGPASSWORD="$PGPASSWORD" \
    -e PGDATABASE="$PGDATABASE" -e BACKUP_REMOTE=/remote/drill -e BACKUP_AGE_RECIPIENT="${recipient:-}" \
    -v "$work/remote:/remote" -v "$work/status:/var/lib/backup" -v "$work/key:/key" \
    -v "$documents:/data/project-documents:ro" --entrypoint "$1" "$image" "${@:2}"
}
counts() {
  run psql -d "$1" -Atc "select string_agg(n::text, ',' order by t) from (
    select 'invoice' t, count(*) n from invoice union all select 'payment', count(*) from payment
    union all select 'journal_entry_line', count(*) from journal_entry_line union all select 'client', count(*) from client
    union all select 'stock_movement', count(*) from stock_movement union all select 'record_revision', count(*) from record_revision
    union all select 'migrations', count(*) from doctrine_migration_versions) c"
}

# Throwaway key pair: the private key never leaves this drill's temporary folder.
recipient="$(run sh -c 'age-keygen -o /key/drill.key 2>&1' | grep -o 'age1[0-9a-z]*')"
old_set="$(date -u -d '-40 days' +%Y%m%dT%H%M%SZ)"
run sh -c "mkdir -p /remote/drill/$old_set && echo expired > /remote/drill/$old_set/db-$old_set.dump.age"

run backup.sh >"$work/backup.log" 2>&1 && backup=ok || backup=failed
check "backup run succeeds" "$backup" "ok"
check "status file reports success" "$(cut -d' ' -f1 "$work/status/last-status")" "ok"
check "health check passes" "$(run healthcheck.sh >/dev/null 2>&1 && echo healthy || echo unhealthy)" "healthy"
check "a set older than the retention window is pruned" "$([ -e "$work/remote/drill/$old_set" ] && echo kept || echo pruned)" "pruned"
check "artifacts are encrypted (age format)" "$(run sh -c 'head -c 21 /remote/drill/*/db-*.dump.age')" "age-encryption.org/v1"

run restore.sh latest /key/drill.key "$SCRATCH_DB" >"$work/restore.log" 2>&1 && restore=ok || restore=failed
check "restore into a scratch database succeeds" "$restore" "ok"
grep -E '^(restore verified|elapsed|backup age)' "$work/restore.log" || true
check "restored row counts equal the source" "$(counts "$SCRATCH_DB")" "$(counts "$PGDATABASE")"
check "restoring over the live database is refused" "$(run restore.sh latest /key/drill.key "$PGDATABASE" >/dev/null 2>&1; echo $?)" "2"
run dropdb --if-exists "$SCRATCH_DB" >/dev/null
# Files were written by the container's root user, so remove them from a container too.
run sh -c 'find /remote /var/lib/backup /key -mindepth 1 -delete'
rmdir "$work/remote" "$work/status" "$work/key" "$work/empty"

if [ "$failures" -ne 0 ]; then
  echo "$failures check(s) failed; see $work/backup.log and $work/restore.log."
  exit 1
fi
rm -f "$work/backup.log" "$work/restore.log"
rmdir "$work"
echo "Backup drill passed."
