#!/usr/bin/env bash
# End-to-end check of the document registry against a running API and a DISPOSABLE PostgreSQL database: concurrent
# registration numbering, content search, confidential visibility through named readers, the PDF card and draft
# deletion. Creates its own document type (code E<timestamp>) so it can run repeatedly.
#
# Usage: API_URL=http://localhost:8081 ADMIN_EMAIL=... ADMIN_PASSWORD=... USER_EMAIL=... USER_PASSWORD=... scripts/verify-dms-e2e.sh
# Requires: curl, jq. The admin needs ROLE_ADMIN (which includes ROLE_DMS_ADMIN); the user needs no extra role.
set -Eeuo pipefail

API_URL="${API_URL:-http://localhost:8081}"
: "${ADMIN_EMAIL:?ADMIN_EMAIL is required}"
: "${ADMIN_PASSWORD:?ADMIN_PASSWORD is required}"
: "${USER_EMAIL:?USER_EMAIL is required}"
: "${USER_PASSWORD:?USER_PASSWORD is required}"
failures=0
check() { if [ "$2" = "$3" ]; then echo "PASS  $1"; else echo "FAIL  $1 (expected '$3', got '$2')"; failures=$((failures + 1)); fi; }
login() { curl -fsS -X POST "$API_URL/api/login" -H 'Content-Type: application/json' -d "$(jq -n --arg e "$1" --arg p "$2" '{email:$e,password:$p}')" | jq -r .token; }
ADMIN="$(login "$ADMIN_EMAIL" "$ADMIN_PASSWORD")"
USER="$(login "$USER_EMAIL" "$USER_PASSWORD")"
api() { local token="$1" method="$2" path="$3"; shift 3; curl -sS -X "$method" "$API_URL/api$path" -H "Authorization: Bearer $token" -H 'Content-Type: application/json' "$@"; }
status() { local token="$1" method="$2" path="$3"; shift 3; curl -sS -o /dev/null -w '%{http_code}' -X "$method" "$API_URL/api$path" -H "Authorization: Bearer $token" "$@"; }
work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
upload() { printf '%s' "$3" > "$work/file.txt"; curl -sS -X POST "$API_URL/api/dms/documents/$2/versions" -H "Authorization: Bearer $1" -F "file=@$work/file.txt;filename=$4" -F 'comment=e2e'; }

s="$(date +%s)"; code="E${s: -8}"; year="$(date +%Y)"
type=$(api "$ADMIN" POST /dms/types -d "{\"code\":\"$code\",\"name\":\"E2E $s\",\"requires_approval\":false,\"number_pattern\":\"$code-{YYYY}-{SEQ:4}\"}" | jq -r .id)
check "document type created" "$([ "$type" != null ] && echo ok)" "ok"
doc() { api "$1" POST /dms/documents -d "{\"type_id\":$type,\"title\":\"$2\",\"confidentiality\":\"${3:-general}\",\"tags\":[\"e2e-$s\"]}" | jq -r .id; }

first=$(doc "$USER" "Searchable memo $s")
check "version 1 stored with its hash" "$(upload "$USER" "$first" "Unique phrase zebra$s 100% done_ok$s" memo.txt | jq -r .current_version.sha256)" "$(printf '%s' "Unique phrase zebra$s 100% done_ok$s" | sha256sum | cut -d' ' -f1)"
check "file content is searchable" "$(api "$USER" GET "/dms/documents?q=ZEBRA$s" | jq -r '.items[0].id')" "$first"
check "LIKE wildcards in the query are literal" "$(api "$USER" GET "/dms/documents?q=100%25%20done_ok$s" | jq -r .total)" "1"
check "tag filter" "$(api "$USER" GET "/dms/documents?tag=e2e-$s" | jq -r .total)" "1"

# Eight documents registered at the same moment must receive consecutive numbers with no gaps and no duplicates.
ids="$(for i in 1 2 3 4 5 6 7 8; do id=$(doc "$USER" "Concurrent $i"); upload "$USER" "$id" "Body $i" "c$i.txt" >/dev/null; echo "$id"; done)"
numbers="$(printf '%s\n' $ids | xargs -P 8 -I{} curl -sS -X POST "$API_URL/api/dms/documents/{}/register" -H "Authorization: Bearer $ADMIN" | jq -r '.registration_number // "ERROR"' | sort)"
expected="$(seq 1 8 | xargs -I{} printf "$code-$year-%04d\n" {})"
check "eight concurrent registrations get consecutive numbers" "$numbers" "$expected"
check "a registered document cannot be registered again" "$(status "$ADMIN" POST "/dms/documents/$(printf '%s\n' $ids | head -1)/register")" "409"

secret=$(doc "$ADMIN" "Board minutes $s" confidential)
check "a confidential document is hidden from others" "$(status "$USER" GET "/dms/documents/$secret")" "404"
check "and missing from their list" "$(api "$USER" GET "/dms/documents?q=minutes%20$s" | jq -r .total)" "0"
user_id=$(api "$USER" GET /org/me | jq -r .user.id)
api "$ADMIN" PUT "/dms/documents/$secret/readers" -d "{\"user_ids\":[$user_id]}" >/dev/null
check "a named reader sees it in the list" "$(api "$USER" GET "/dms/documents?q=minutes%20$s" | jq -r .total)" "1"

check "registration card is a PDF" "$(curl -sS -o /dev/null -w '%{content_type}' "$API_URL/api/dms/documents/$first/card.pdf" -H "Authorization: Bearer $USER")" "application/pdf"
check "an unregistered draft can be deleted" "$(status "$USER" DELETE "/dms/documents/$first")" "204"
check "and is gone" "$(status "$USER" GET "/dms/documents/$first")" "404"

[ "$failures" -eq 0 ] && echo "All document registry checks passed." || { echo "$failures check(s) failed."; exit 1; }
