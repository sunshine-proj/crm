#!/usr/bin/env bash
# End-to-end check of the document registry against a running API and a DISPOSABLE PostgreSQL database: concurrent
# registration numbering, content search, confidential visibility through named readers, the PDF card, the audit
# trail, legal hold, registry reports and draft deletion. Creates its own document type (code E<timestamp>) so it can run repeatedly.
#
# Usage: API_URL=http://localhost:8081 ADMIN_EMAIL=... ADMIN_PASSWORD=... USER_EMAIL=... USER_PASSWORD=... scripts/verify-dms-e2e.sh
# Optional: APPROVERS="a@x:pw b@x:pw c@x:pw" (three users without a position) adds the approval checks: the script builds
# its own small structure (the user reports to the three of them) and approves in parallel, then runs an
# acknowledgement request through it (simultaneous confirmations and reminders, partial boards, CSV and PDF exports)
# and checks that the registry reports and the audit trail reflect it.
# USER must have no current primary position.
# Requires: curl, jq. The admin needs ROLE_ADMIN (which includes ROLE_DMS_ADMIN); the other users need no extra role.
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
# Audit trail and legal hold (Phase 5).
api "$USER" GET "/dms/documents/$first" >/dev/null; api "$USER" GET "/dms/documents/$first" >/dev/null
check "a view is audited once per person and day" "$(api "$USER" GET "/dms/documents/$first/audit?action=viewed" | jq -r .total)" "1"
check "the audit trail exports as CSV" "$(curl -sS "$API_URL/api/dms/documents/$first/audit?format=csv" -H "Authorization: Bearer $USER" | sed -n 1p | tr -d '\r\357\273\277')" 'When,Source,Action,Actor,"On behalf of",Details'
check "a legal hold needs a clerk" "$(status "$USER" POST "/dms/documents/$first/legal-hold" -H 'Content-Type: application/json' -d '{"reason":"Case 1"}')" "403"
check "a clerk places a legal hold" "$(status "$ADMIN" POST "/dms/documents/$first/legal-hold" -H 'Content-Type: application/json' -d '{"reason":"Case 1"}')" "200"
check "a held draft cannot be deleted" "$(status "$USER" DELETE "/dms/documents/$first")" "409"
check "the hold is released" "$(status "$ADMIN" DELETE "/dms/documents/$first/legal-hold")" "200"
check "registry reports are for clerks" "$(status "$USER" GET /dms/reports)" "403"
check "registry reports count documents" "$(api "$ADMIN" GET /dms/reports | jq -r '.documents.total > 0')" "true"
check "an unregistered draft can be deleted" "$(status "$USER" DELETE "/dms/documents/$first")" "204"
check "and is gone" "$(status "$USER" GET "/dms/documents/$first")" "404"

if [ -n "${APPROVERS:-}" ]; then
  read -r -a approver_pairs <<<"$APPROVERS"
  [ "${#approver_pairs[@]}" -eq 3 ] || { echo "APPROVERS needs exactly three email:password pairs." >&2; exit 2; }
  tokens=(); approver_ids=()
  for pair in "${approver_pairs[@]}"; do t="$(login "${pair%%:*}" "${pair#*:}")"; tokens+=("$t"); approver_ids+=("$(api "$t" GET /org/me | jq -r .user.id)"); done
  today="$(date +%Y-%m-%d)"
  # ORG (head: approver 3) > UNIT (head: approver 2) > SECTION (head: approver 1; staff: user).
  parent=null
  for level in 3 2 1; do
    kind=$([ $level = 3 ] && echo organization || ([ $level = 2 ] && echo unit || echo section))
    unit=$(api "$ADMIN" POST /org/units -d "{\"code\":\"A$level${s: -6}\",\"name\":\"Approval $level $s\",\"kind\":\"$kind\"$([ "$parent" = null ] || echo ",\"parent_id\":$parent")}" | jq -r .id)
    head=$(api "$ADMIN" POST /org/positions -d "{\"unit_id\":$unit,\"title\":\"Head $level\",\"is_head\":true}" | jq -r .id)
    api "$ADMIN" POST /org/employments -d "{\"user_id\":${approver_ids[$((level - 1))]},\"position_id\":$head,\"starts_on\":\"$today\"}" >/dev/null
    parent=$unit
  done
  staff=$(api "$ADMIN" POST /org/positions -d "{\"unit_id\":$parent,\"title\":\"Author\"}" | jq -r .id)
  api "$ADMIN" POST /org/employments -d "{\"user_id\":$user_id,\"position_id\":$staff,\"starts_on\":\"$today\"}" >/dev/null
  check "the author reports to the three approvers" "$(api "$USER" GET /org/me | jq -r '[.superiors[].id] | map(tostring) | join(",")')" "$(IFS=,; echo "${approver_ids[*]}")"

  ord=$(api "$ADMIN" POST /dms/types -d "{\"code\":\"P${s: -8}\",\"name\":\"Approval $s\",\"requires_approval\":true,\"auto_register\":true,\"number_pattern\":\"P${s: -8}-{YYYY}-{SEQ:3}\"}" | jq -r .id)
  doc=$(api "$USER" POST /dms/documents -d "{\"type_id\":$ord,\"title\":\"Parallel approval $s\"}" | jq -r .id)
  upload "$USER" "$doc" "Approve me $s" order.txt >/dev/null
  steps="$(for id in "${approver_ids[@]}"; do printf '{"assignee_id":%s,"kind":"approve"},' "$id"; done)"
  body="{\"stages\":[{\"rule\":\"all\",\"steps\":[${steps%,}]}]}"
  codes="$(for _ in 1 2; do echo x; done | xargs -P 2 -I{} curl -sS -o /dev/null -w '%{http_code}\n' -X POST "$API_URL/api/dms/documents/$doc/approval/submit" -H "Authorization: Bearer $USER" -H 'Content-Type: application/json' -d "$body" | sort | tr '\n' ' ')"
  check "two simultaneous submissions create one round" "$codes" "201 409 "

  for i in 0 1 2; do echo "${tokens[$i]} $(api "${tokens[$i]}" GET /dms/approvals/inbox | jq -r '.[0].id')"; done > "$work/steps"
  # All three approve at the same moment: the stage must complete exactly once and the number be taken once.
  results="$(xargs -P 3 -L 1 sh -c 'curl -sS -o /dev/null -w "%{http_code}\n" -X POST "$0/api/dms/approvals/steps/$2/decision" -H "Authorization: Bearer $1" -H "Content-Type: application/json" -d "{\"decision\":\"approve\"}"' "$API_URL" < "$work/steps" | sort | tr '\n' ' ')"
  check "three parallel approvals are all accepted" "$results" "200 200 200 "
  history="$(api "$USER" GET "/dms/documents/$doc/approval")"
  check "the round is approved once" "$(jq -r '.rounds | length' <<<"$history")/$(jq -r '.rounds[0].status' <<<"$history")" "1/approved"
  check "three approvals are recorded" "$(jq -r '[.rounds[0].actions[] | select(.action == "approved")] | length' <<<"$history")" "3"
  document="$(api "$USER" GET "/dms/documents/$doc")"
  check "the approved document is registered automatically" "$(jq -r .status <<<"$document") $(jq -r .registration_number <<<"$document")" "in_force P${s: -8}-$year-001"
  first_step="$(head -1 "$work/steps")"
  check "a repeated decision is refused" "$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$API_URL/api/dms/approvals/steps/${first_step#* }/decision" -H "Authorization: Bearer ${first_step%% *}" -H 'Content-Type: application/json' -d '{"decision":"approve"}')" "409"

  # Acknowledgement: the head of UNIT (approver 2) asks UNIT and everything below it to read the registered order.
  unit_id=$(api "${tokens[1]}" GET "/dms/documents/$doc/acknowledgements/audience" | jq -r '.units | map(select(.name == "Approval 2 '"$s"'")) | .[0].id')
  sent="$(api "${tokens[1]}" POST "/dms/documents/$doc/acknowledgements" -d "{\"targets\":[{\"kind\":\"unit\",\"id\":$unit_id,\"include_subunits\":true}],\"due_on\":\"$today\"}")"
  request=$(jq -r .id <<<"$sent")
  check "a unit head asks their subtree (the sender excluded)" "$(jq -r '[.recipients[].user.id] | sort | map(tostring) | join(",")' <<<"$sent")" "$(printf '%s\n' "${approver_ids[0]}" "$user_id" | sort -n | paste -sd,)"
  check "the board starts at the first unit with people of its own" "$(jq -r '[.units[] | "\(.depth):\(.total.total)"] | join(" ")' <<<"$sent")" "0:2"
  check "a unit outside the sender's subtree is refused" "$(status "${tokens[0]}" POST "/dms/documents/$doc/acknowledgements" -H 'Content-Type: application/json' -d "{\"targets\":[{\"kind\":\"unit\",\"id\":$unit_id}]}")" "409"
  mine=$(api "$USER" GET /dms/acknowledgements/inbox | jq -r '.[0].id')
  check "confirming before opening is refused" "$(status "$USER" POST "/dms/acknowledgements/recipients/$mine/acknowledge")" "409"
  status "$USER" GET "/dms/documents/$doc/versions/1/file" >/dev/null
  codes="$(for _ in 1 2; do echo x; done | xargs -P 2 -I{} curl -sS -o /dev/null -w '%{http_code}\n' -X POST "$API_URL/api/dms/acknowledgements/recipients/$mine/acknowledge" -H "Authorization: Bearer $USER" | sort | tr '\n' ' ')"
  check "two simultaneous confirmations count once" "$codes" "200 409 "
  check "one acknowledged event" "$(api "$USER" GET "/dms/documents/$doc" | jq -r '[.events[] | select(.action == "acknowledged")] | length')" "1"
  codes="$(for _ in 1 2; do echo x; done | xargs -P 2 -I{} curl -sS -o /dev/null -w '%{http_code}\n' -X POST "$API_URL/api/dms/acknowledgements/$request/remind" -H "Authorization: Bearer ${tokens[1]}" -H 'Content-Type: application/json' -d '{"recipient_ids":null}' | sort | tr '\n' ' ')"
  check "simultaneous 'remind all' succeed or answer 409" "$(tr ' ' '\n' <<<"$codes" | grep -cvE '^(200|409)?$' || true)" "0"
  check "the pending person got exactly one manual reminder" "$(api "${tokens[0]}" GET '/notifications?limit=50' | jq -r '[.items[] | select(.message | startswith("Reminder from"))] | length')" "1"
  board="$(api "${tokens[0]}" GET "/dms/acknowledgements/$request")"
  check "the section head sees only their section" "$(jq -r '"\(.is_partial) \(.counts.total) \(.units[0].depth)"' <<<"$board")" "true 2 0"
  check "status export is CSV with a header and two people" "$(curl -sS "$API_URL/api/dms/acknowledgements/$request/export.csv" -H "Authorization: Bearer ${tokens[1]}" | wc -l | tr -d ' ')" "3"
  check "acknowledgement sheet is a PDF" "$(curl -sS -o /dev/null -w '%{content_type}' "$API_URL/api/dms/acknowledgements/$request/sheet.pdf" -H "Authorization: Bearer ${tokens[1]}")" "application/pdf"
  check "the user's history lists the confirmation" "$(api "$USER" GET /dms/acknowledgements/history | jq -r '.[0].status')" "acknowledged"

  # Status report (Phase 5): the approvals and the acknowledgement above appear in the registry reports.
  report="$(api "$ADMIN" GET /dms/reports)"
  check "the report times the three approvals" "$(jq -r '.approval_time.by_kind[] | select(.kind == "approve") | .decided' <<<"$report")" "3"
  check "the report shows acknowledgement completion for the section" "$(jq -r '.acknowledgement.by_unit[] | select(.unit == "Approval 1 '"$s"'") | "\(.total) \(.acknowledged)"' <<<"$report")" "2 1"
  check "the audit trail merges the three approval decisions" "$(api "$USER" GET "/dms/documents/$doc/audit?action=approval_approved" | jq -r '[.entries[] | select(.source == "approval")] | length')" "3"
fi

[ "$failures" -eq 0 ] && echo "All document registry checks passed." || { echo "$failures check(s) failed."; exit 1; }
