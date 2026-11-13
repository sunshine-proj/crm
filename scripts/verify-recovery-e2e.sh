#!/usr/bin/env bash
# End-to-end check of record versioning and recovery against a running API and PostgreSQL database, where
# foreign keys really cascade: deleting a client removes its contacts and deals and detaches its projects,
# and restoring the client's delete revision brings them back. Also checks the deletion guards.
# Use a DISPOSABLE database: the script creates and deletes clients, contacts, deals, projects and time.
#
# Usage: API_URL=http://localhost:8081 ADMIN_EMAIL=... ADMIN_PASSWORD=... scripts/verify-recovery-e2e.sh
# Requires: curl, jq. The admin user needs ROLE_ADMIN.
set -Eeuo pipefail

API_URL="${API_URL:-http://localhost:8081}"
: "${ADMIN_EMAIL:?ADMIN_EMAIL is required}"
: "${ADMIN_PASSWORD:?ADMIN_PASSWORD is required}"
failures=0
check() { if [ "$2" = "$3" ]; then echo "PASS  $1"; else echo "FAIL  $1 (expected '$3', got '$2')"; failures=$((failures + 1)); fi; }

TOKEN="$(curl -fsS -X POST "$API_URL/api/login" -H 'Content-Type: application/json' \
  -d "$(jq -n --arg e "$ADMIN_EMAIL" --arg p "$ADMIN_PASSWORD" '{email:$e,password:$p}')" | jq -r .token)"
api() { local method="$1" path="$2"; shift 2; curl -sS -X "$method" "$API_URL/api$path" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' "$@"; }
status() { local method="$1" path="$2"; shift 2; api "$method" "$path" -o /dev/null -w '%{http_code}' "$@"; }

suffix="$(date +%s)"
client=$(api POST /clients -d "{\"title\":\"Recovery $suffix\",\"notes\":\"E2E\"}" | jq -r .id)
contact=$(api POST "/clients/$client/contacts" -d "{\"name\":\"Ann $suffix\",\"email\":\"ann-$suffix@example.test\"}" | jq -r .id)
deal=$(api POST /deals -d "{\"client_id\":$client,\"title\":\"Deal $suffix\",\"amount\":\"1200.00\",\"currency\":\"EUR\"}" | jq -r .id)
project=$(api POST /projects -d "{\"title\":\"Project $suffix\",\"description\":\"E2E\",\"client_id\":$client,\"status\":\"active\"}" | jq -r .id)
milestone=$(api POST "/projects/$project/milestones" -d '{"title":"Design","description":"E2E"}' | jq -r .id)

check "client delete succeeds" "$(status DELETE "/clients/$client")" "204"
check "the contact is gone with the client" "$(status GET "/clients/$client/contacts")" "404"
check "the deal is gone with the client" "$(status GET "/deals/$deal")" "404"
check "the project survives without a client" "$(api GET "/projects/$project" | jq -r '.client_id // "null"')" "null"

caused() { api GET "/records/revisions?type=$1&entity_id=$2&limit=1" | jq -r '.[0] | "\(.action):\(.cause.record_type)#\(.cause.record_id)"'; }
check "contact deletion is versioned with its cause" "$(caused client_contact "$contact")" "delete:client#$client"
check "deal deletion is versioned with its cause" "$(caused deal "$deal")" "delete:client#$client"
check "project detachment is versioned with its cause" "$(caused project "$project")" "update:client#$client"

revision=$(api GET "/records/revisions?type=client&action=delete&entity_id=$client" | jq -r '.[0].id')
restored="$(api POST "/records/revisions/$revision/restore")"
new_client=$(jq -r .record_id <<<"$restored")
check "client is recreated" "$(jq -r .recreated <<<"$restored")" "true"
check "contact, deal and project link come back with it" "$(jq -r .related_restored <<<"$restored")" "3"
check "contact restored under the new client" "$(api GET "/clients/$new_client/contacts" | jq -r '.[0].name')" "Ann $suffix"
check "deal restored under the new client" "$(api GET "/deals?client_id=$new_client" | jq -r "[.[] | select(.title == \"Deal $suffix\")] | length")" "1"
check "project re-linked to the new client" "$(api GET "/projects/$project" | jq -r .client_id)" "$new_client"

# Deleting a project removes its milestones through the database; restoring brings them back.
spare=$(api POST /projects -d "{\"title\":\"Spare $suffix\",\"description\":\"E2E\",\"client_id\":$new_client,\"status\":\"active\"}" | jq -r .id)
api POST "/projects/$spare/milestones" -d '{"title":"Kick-off","description":"E2E"}' >/dev/null
api DELETE "/projects/$spare" >/dev/null
revision=$(api GET "/records/revisions?type=project&action=delete&entity_id=$spare" | jq -r '.[0].id')
restored="$(api POST "/records/revisions/$revision/restore")"
check "project milestone returns with the project" "$(api GET "/projects/$(jq -r .record_id <<<"$restored")/milestones" | jq -r '.[0].title')" "Kick-off"

# Deletion guards: time entries must not vanish through a cascade.
api POST /operations/time-entries -d "{\"project_id\":$project,\"work_date\":\"2026-03-02\",\"duration_minutes\":60,\"description\":\"E2E\",\"billable\":false}" >/dev/null
check "a project with time entries cannot be deleted" "$(status DELETE "/projects/$project")" "409"
check "the project and its milestone are still there" "$(api GET "/projects/$project/milestones" | jq -r '.[0].id')" "$milestone"

[ "$failures" -eq 0 ] && echo "All recovery checks passed." || { echo "$failures check(s) failed."; exit 1; }
