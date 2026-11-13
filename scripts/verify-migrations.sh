#!/usr/bin/env bash
# Migration check on a throwaway PostgreSQL container: applies every migration to an empty database,
# verifies that the schema equals the entity mappings, reverses all migrations, applies them again and
# verifies once more. Nothing outside the temporary containers is touched (no deployed database, no storage/).
#
# Usage: scripts/verify-migrations.sh
# Optional: PHP_IMAGE (default crm/php-1.1), POSTGRES_IMAGE (default postgres:17-alpine, as in docker-compose.yml).
# Requires: docker and installed Composer dependencies in src/vendor.
set -Eeuo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
php_image="${PHP_IMAGE:-crm/php-1.1}"
postgres_image="${POSTGRES_IMAGE:-postgres:17-alpine}"
name="crm-migration-check-$$"
var_dir="$(mktemp -d)"
failures=0
check() { if [ "$2" = "$3" ]; then echo "PASS  $1"; else echo "FAIL  $1 (expected '$3', got '$2')"; failures=$((failures + 1)); fi; }
cleanup() {
  docker rm -f "$name-db" >/dev/null 2>&1 || true
  docker network rm "$name" >/dev/null 2>&1 || true
  # The cache was written from inside a container, so remove it the same way.
  docker run --rm -v "$var_dir:/v" "$php_image" find /v -mindepth 1 -delete >/dev/null 2>&1 || true
  rmdir "$var_dir" 2>/dev/null || true
}
trap cleanup EXIT

docker network create "$name" >/dev/null
docker run -d --name "$name-db" --network "$name" --tmpfs /var/lib/postgresql/data \
  -e POSTGRES_DB=app -e POSTGRES_USER=app -e POSTGRES_PASSWORD=check "$postgres_image" >/dev/null
# The image starts a temporary server while it initialises, so wait for two successful queries in a row.
ready=0
for _ in $(seq 1 90); do
  if docker exec "$name-db" psql -U app -d app -Atc 'select 1' >/dev/null 2>&1; then ready=$((ready + 1)); else ready=0; fi
  [ "$ready" -ge 2 ] && break
  sleep 1
done
[ "$ready" -ge 2 ] || { echo "PostgreSQL did not become ready." >&2; exit 1; }
server_major="$(docker exec "$name-db" psql -U app -d app -Atc 'show server_version' | cut -d. -f1)"
console() {
  docker run --rm --network "$name" -v "$root/src:/var/www/html" -v "$var_dir:/var/www/html/var" -w /var/www/html \
    -e APP_ENV=dev -e DATABASE_URL="postgresql://app:check@$name-db:5432/app?serverVersion=$server_major&charset=utf8" \
    "$php_image" php bin/console "$@"
}
in_sync() { console doctrine:schema:validate 2>&1 | grep -c 'database schema is in sync' || true; }
applied() { docker exec "$name-db" psql -U app -d app -Atc 'select count(*) from doctrine_migration_versions'; }
expected="$(find "$root/src/migrations" -name 'Version*.php' | wc -l | tr -d ' ')"

console doctrine:migrations:migrate --no-interaction --quiet
check "all $expected migrations apply to an empty PostgreSQL $server_major database" "$(applied)" "$expected"
check "schema matches the entity mappings" "$(in_sync)" "1"
console doctrine:migrations:migrate first --no-interaction --quiet
check "every migration can be reversed" "$(applied)" "0"
check "no application tables are left behind" "$(docker exec "$name-db" psql -U app -d app -Atc "select count(*) from information_schema.tables where table_schema='public' and table_name <> 'doctrine_migration_versions'")" "0"
console doctrine:migrations:migrate --no-interaction --quiet
check "all migrations apply again" "$(applied)" "$expected"
check "schema still matches the entity mappings" "$(in_sync)" "1"

[ "$failures" -eq 0 ] && echo "All migration checks passed." || { echo "$failures check(s) failed."; exit 1; }
