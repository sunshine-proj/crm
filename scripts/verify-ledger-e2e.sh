#!/usr/bin/env bash
# End-to-end check of ledger postings, payment concurrency and source-to-ledger reconciliation against a
# running API and PostgreSQL database. Use a DISPOSABLE database: the script creates accounts, a fiscal period,
# a client, a project and an invoice.
#
# Usage: API_URL=http://localhost:8081 ADMIN_EMAIL=... ADMIN_PASSWORD=... scripts/verify-ledger-e2e.sh
# Requires: curl, jq, xargs. The admin user needs ROLE_ADMIN.
set -Eeuo pipefail

API_URL="${API_URL:-http://localhost:8081}"
: "${ADMIN_EMAIL:?ADMIN_EMAIL is required}"
: "${ADMIN_PASSWORD:?ADMIN_PASSWORD is required}"
failures=0
check() { if [ "$2" = "$3" ]; then echo "PASS  $1"; else echo "FAIL  $1 (expected '$3', got '$2')"; failures=$((failures + 1)); fi; }

TOKEN="$(curl -fsS -X POST "$API_URL/api/login" -H 'Content-Type: application/json' \
  -d "$(jq -n --arg e "$ADMIN_EMAIL" --arg p "$ADMIN_PASSWORD" '{email:$e,password:$p}')" | jq -r .token)"
api() { local method="$1" path="$2"; shift 2; curl -sS -X "$method" "$API_URL/api$path" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' "$@"; }
account() { api POST /finance/accounts -d "{\"code\":\"$1\",\"name\":\"$2\",\"type\":\"$3\"}" | jq -r .id; }

suffix="$(date +%s)"
ar=$(account "E$suffix-1" Receivables asset); revenue=$(account "E$suffix-2" Revenue revenue); cash=$(account "E$suffix-3" Cash asset)
ap=$(account "E$suffix-4" Payables liability); expense=$(account "E$suffix-5" Expense expense); expense_payable=$(account "E$suffix-6" 'Expense payable' liability)
api PUT /finance/account-mappings -d "{\"accounts_receivable\":$ar,\"revenue\":$revenue,\"cash\":$cash,\"accounts_payable\":$ap,\"project_expense\":$expense,\"expense_payable\":$expense_payable}" >/dev/null
api POST /finance/periods -d '{"name":"E2E 2026","starts_on":"2026-01-01","ends_on":"2026-12-31"}' >/dev/null || true

client=$(api POST /clients -d '{"title":"E2E client","notes":"E2E verification"}' | jq -r .id)
project=$(api POST /projects -d "{\"title\":\"E2E project\",\"description\":\"E2E verification\",\"client_id\":$client,\"status\":\"active\"}" | jq -r .id)
invoice=$(api POST /invoices -d "{\"project_id\":$project,\"invoice_date\":\"2026-03-01\",\"due_date\":\"2026-03-31\",\"currency\":\"EUR\",\"items\":[{\"description\":\"Work\",\"amount\":\"100.00\"}]}" | jq -r .id)
issued=$(api POST "/invoices/$invoice/issue" | jq -r .status)
check "invoice issue succeeds" "$issued" "issued"

# Ten concurrent receipts of 30.00 against a 100.00 invoice: exactly three (90.00) may be accepted.
codes="$(seq 1 10 | xargs -P 10 -I{} curl -sS -o /dev/null -w '%{http_code}\n' -X POST "$API_URL/api/invoices/$invoice/payments" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"amount":"30.00","paid_at":"2026-03-02","reference":"concurrent"}')"
accepted="$(grep -c '^201$' <<<"$codes" || true)"
check "exactly 3 of 10 concurrent receipts accepted" "$accepted" "3"
paid="$(api GET "/invoices/$invoice/payments" | jq '[.[].amount | tonumber] | add')"
check "recorded receipts total 90" "$paid" "90"
check "no overpayment: balance due" "$(api GET "/invoices/$invoice" | jq -r .balance_due)" "10.00"

recon="$(api GET /finance/reports/reconciliation)"
check "every issued invoice and receipt has a journal posting" "$(jq -r .reconciled <<<"$recon")" "true"
tb="$(api GET '/finance/reports/trial-balance?currency=EUR')"
check "trial balance debits equal credits" "$(jq -r .balanced <<<"$tb")" "true"

[ "$failures" -eq 0 ] && echo "All ledger checks passed." || { echo "$failures check(s) failed."; exit 1; }
