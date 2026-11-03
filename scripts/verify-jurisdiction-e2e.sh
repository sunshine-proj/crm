#!/usr/bin/env bash
# End-to-end check of jurisdiction-gated tax, gapless numbering, credit notes and bank-statement matching against a
# running API and a DISPOSABLE PostgreSQL database. Uses Latvia (LV) and fixed 2026 dates; the "adviser" in the sign-offs
# is a test fixture and carries no legal meaning.
#
# Usage: API_URL=http://localhost:8081 ADMIN_EMAIL=... ADMIN_PASSWORD=... scripts/verify-jurisdiction-e2e.sh
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
account() { api POST /finance/accounts -d "{\"code\":\"$1\",\"name\":\"$2\",\"type\":\"$3\"}" | jq -r .id; }

s="$(date +%s)"
api PUT /settings -d '{"country_code":"LV","default_currency":"EUR","retention_years":10}' >/dev/null
ar=$(account "J$s-1" Receivables asset); revenue=$(account "J$s-2" Revenue revenue); cash=$(account "J$s-3" Cash asset); tax=$(account "J$s-4" 'Output tax' liability)
api PUT /finance/account-mappings -d "{\"accounts_receivable\":$ar,\"revenue\":$revenue,\"cash\":$cash,\"tax_payable\":$tax}" >/dev/null
api POST /finance/periods -d '{"name":"JUR 2026","starts_on":"2026-01-01","ends_on":"2026-12-31"}' >/dev/null || true

client=$(api POST /clients -d '{"title":"Jurisdiction client","notes":"E2E","legal_name":"Acme SIA","vat_number":"LV40003123456"}' | jq -r .id)
project=$(api POST /projects -d "{\"title\":\"Jurisdiction project\",\"description\":\"E2E\",\"client_id\":$client,\"status\":\"active\"}" | jq -r .id)
draft() { api POST /invoices -d "{\"project_id\":$project,\"invoice_date\":\"2026-03-01\",\"currency\":\"EUR\",\"items\":[{\"description\":\"Work\",\"amount\":\"$1\"}]}"; }

before="$(draft 100.00)"
check "without a sign-off nothing is taxed" "$(jq -r .total <<<"$before")" "100.00"

api POST /jurisdiction/standard-tax-code >/dev/null
sign() { api POST /jurisdiction/validations -d "{\"country_code\":\"LV\",\"scope\":\"$1\",\"adviser_name\":\"Test fixture\",\"reference\":\"e2e-$s\",\"validated_on\":\"2026-02-01\"$2}" | jq -r .active; }
check "tax sign-off recorded" "$(sign tax ',"rounding":"per_rate_group"')" "true"
check "numbering sign-off recorded" "$(sign numbering '')" "true"
api PUT /jurisdiction/legal-profile -d '{"legal_name":"My Company SIA","vat_number":"LV40001000001","address":"Brivibas 1, Riga"}' >/dev/null

invoice="$(draft 100.00)"; invoice_id=$(jq -r .id <<<"$invoice")
check "tax is added on the server" "$(jq -r .total <<<"$invoice")" "121.00"
issued="$(api POST "/invoices/$invoice_id/issue")"
check "issued number comes from the gapless sequence" "$(jq -r .invoice_number <<<"$issued")" "INV-LV-2026-000001"

note="$(api POST "/invoices/$invoice_id/credit-notes" -d '{"reason":"Goodwill","credit_date":"2026-03-10","items":[{"description":"Discount","amount":"10.00","tax_code":"LV-STD"}]}')"
check "credit note total includes reversed tax" "$(jq -r .total <<<"$note")" "12.10"
check "credit note is numbered in its own series" "$(jq -r .credit_note_number <<<"$note")" "CN-LV-2026-000001"
check "balance reflects the credit note" "$(api GET "/invoices/$invoice_id" | jq -r .balance_due)" "108.90"
payment="$(api POST "/invoices/$invoice_id/payments" -d '{"amount":"108.90","paid_at":"2026-03-12","reference":"wire"}')"
check "receipt for the remaining balance is accepted" "$(jq -r .balance_due <<<"$payment")" "0.00"
check "invoice is settled" "$(api GET "/invoices/$invoice_id" | jq -r .status)" "paid"

recon="$(api GET '/finance/reports/subledger-reconciliation?currency=EUR')"
check "tax payable control matches the ledger" "$(jq -r .tax_payable.difference <<<"$recon")" "0.00"

bank=$(api POST /bank/accounts -d "{\"name\":\"Main $s\",\"currency\":\"EUR\",\"ledger_account_id\":$cash}" | jq -r .id)
xml="<?xml version=\"1.0\"?><Document xmlns=\"urn:iso:std:iso:20022:tech:xsd:camt.053.001.02\"><BkToCstmrStmt><Stmt><Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp><Amt Ccy=\"EUR\">500.00</Amt><CdtDbtInd>CRDT</CdtDbtInd></Bal><Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp><Amt Ccy=\"EUR\">608.90</Amt><CdtDbtInd>CRDT</CdtDbtInd></Bal><Ntry><Amt Ccy=\"EUR\">108.90</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts><BookgDt><Dt>2026-03-12</Dt></BookgDt><NtryDtls><TxDtls><RmtInf><Ustrd>Payment INV-LV-2026-000001</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry></Stmt></BkToCstmrStmt></Document>"
imported="$(api POST "/bank/accounts/$bank/imports" -d "$(jq -n --arg c "$xml" '{filename:"march.xml",format:"camt053",content:$c}')")"
check "statement imported" "$(jq -r .imported_lines <<<"$imported")" "1"
check "auto-match pairs the receipt by invoice number" "$(api POST "/bank/accounts/$bank/auto-match" | jq -r .matched)" "1"
check "March reconciles" "$(api GET "/bank/accounts/$bank/reconciliation?from=2026-03-01&to=2026-03-31" | jq -r .reconciled)" "true"

# Eight invoices issued at the same moment must receive consecutive numbers with no gaps and no duplicates.
ids="$(for _ in 1 2 3 4 5 6 7 8; do draft 10.00 | jq -r .id; done)"
numbers="$(printf '%s\n' $ids | xargs -P 8 -I{} curl -sS -X POST "$API_URL/api/invoices/{}/issue" -H "Authorization: Bearer $TOKEN" | jq -r '.invoice_number // "ERROR"' | sort)"
expected="$(seq 2 9 | xargs -I{} printf 'INV-LV-2026-%06d\n' {})"
check "eight concurrent issues get consecutive numbers" "$numbers" "$expected"

[ "$failures" -eq 0 ] && echo "All jurisdiction checks passed." || { echo "$failures check(s) failed."; exit 1; }
