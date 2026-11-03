#!/usr/bin/env bash
# End-to-end check of weighted-average inventory valuation against a running API and a DISPOSABLE PostgreSQL
# database: purchase approval, goods receipt with landed cost, dispatch from a sales shipment, three-way bill
# matching and the inventory/GR-IR reconciliation.
#
# Usage: API_URL=http://localhost:8081 ADMIN_EMAIL=... ADMIN_PASSWORD=... scripts/verify-inventory-e2e.sh
# Requires: curl, jq. The admin user needs ROLE_ADMIN. Dates are fixed to 2026 so the fiscal period is created too.
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
ap=$(account "I$s-1" Payables liability); expense=$(account "I$s-2" Expense expense); inventory=$(account "I$s-3" Inventory asset)
cogs=$(account "I$s-4" 'Cost of sales' expense); grni=$(account "I$s-5" 'GR/IR' liability); adjustment=$(account "I$s-6" Adjustments expense)
ppv=$(account "I$s-7" 'Price variance' expense); ar=$(account "I$s-8" Receivables asset); revenue=$(account "I$s-9" Revenue revenue)
api PUT /finance/account-mappings -d "{\"accounts_payable\":$ap,\"project_expense\":$expense,\"inventory\":$inventory,\"cost_of_sales\":$cogs,\"goods_received_not_invoiced\":$grni,\"inventory_adjustment\":$adjustment,\"purchase_price_variance\":$ppv,\"accounts_receivable\":$ar,\"revenue\":$revenue}" >/dev/null
api POST /finance/periods -d '{"name":"INV 2026","starts_on":"2026-01-01","ends_on":"2026-12-31"}' >/dev/null || true

supplier=$(api POST /inventory/suppliers -d "{\"code\":\"S$s\",\"name\":\"Supplier $s\"}" | jq -r .id)
warehouse=$(api POST /inventory/warehouses -d "{\"code\":\"W$s\",\"name\":\"Warehouse $s\"}" | jq -r .id)
product=$(api POST /products -d "{\"sku\":\"P$s\",\"name\":\"Widget\",\"currency\":\"EUR\",\"unit_price\":\"25.00\"}" | jq -r .id)

api PUT /inventory/purchase-approval-policy -d '{"threshold":"50.00"}' >/dev/null
order=$(api POST /inventory/purchase-orders -d "{\"supplier_id\":$supplier,\"currency\":\"EUR\",\"ordered_at\":\"2026-03-01\",\"items\":[{\"product_id\":$product,\"quantity\":10,\"unit_cost\":\"10.00\"}]}")
order_id=$(jq -r .id <<<"$order")
check "order above the threshold awaits approval" "$(jq -r .status <<<"$order")" "pending_approval"
check "creator-admin may approve" "$(api POST "/inventory/purchase-orders/$order_id/approve" -d '{"comment":"ok"}' | jq -r .status)" "open"
api PUT /inventory/purchase-approval-policy -d '{"threshold":null}' >/dev/null

receipt="$(api POST "/inventory/purchase-orders/$order_id/receipts" -d "{\"warehouse_id\":$warehouse,\"received_at\":\"2026-03-02\",\"items\":[{\"product_id\":$product,\"quantity\":10}],\"landed_costs\":[{\"description\":\"Freight\",\"amount\":\"50.00\"}]}")"
check "receipt records the landed cost" "$(jq -r .landed_total <<<"$receipt")" "50.00"
check "stock is valued at goods plus landed cost" "$(api GET /inventory/valuation | jq -r '.rows[] | select(.warehouse_id=='"$warehouse"') | .value')" "150.00"

client=$(api POST /clients -d '{"title":"Inventory client","notes":"E2E"}' | jq -r .id)
quote=$(api POST /quotes -d "{\"client_id\":$client,\"currency\":\"EUR\",\"valid_until\":\"2027-12-31\",\"items\":[{\"product_id\":$product,\"quantity\":4}]}" | jq -r .id)
api PATCH "/quotes/$quote/status" -d '{"status":"sent"}' >/dev/null
api PATCH "/quotes/$quote/status" -d '{"status":"accepted"}' >/dev/null
sales_order=$(api POST "/quotes/$quote/convert" | jq -r .id)
shipment=$(api POST "/sales-orders/$sales_order/shipments" -d "{\"shipped_at\":\"2026-03-10\",\"items\":[{\"product_id\":$product,\"quantity\":4}]}" | jq -r '.id // .shipment_id')
check "dispatch succeeds" "$(api POST "/inventory/sales-shipments/$shipment/dispatch" -d "{\"warehouse_id\":$warehouse,\"moved_at\":\"2026-03-10\"}" | jq -r .status)" "dispatched"
check "dispatch removed 4 x 15.00 of stock value" "$(api GET /inventory/valuation | jq -r '.rows[] | select(.warehouse_id=='"$warehouse"') | .value')" "90.00"
check "cost of sales posted" "$(api GET '/finance/reports/trial-balance?currency=EUR' | jq -r '.accounts[] | select(.account_id=='"$cogs"') | .debit')" "60.00"

bill() { api POST /finance/payables -d "{\"vendor_name\":\"Supplier $s\",\"vendor_reference\":\"$1\",\"currency\":\"EUR\",\"total\":\"$2\",\"bill_date\":\"2026-03-12\",\"purchase_order_id\":$order_id${3:-}}"; }
check "bill within received value matches" "$(bill "B1-$s" 150.00 | jq -r .match_status)" "matched"
check "bill beyond received value is refused" "$(bill "B2-$s" 5.00 | jq -r .error | cut -c1-24)" "The bill exceeds the rec"
check "override records an exception" "$(bill "B3-$s" 5.00 ',"override_reason":"Late fee"' | jq -r .match_status)" "exception"

sub="$(api GET '/finance/reports/subledger-reconciliation?currency=EUR')"
check "inventory control matches the ledger" "$(jq -r .inventory.difference <<<"$sub")" "0.00"
check "goods received not invoiced is cleared" "$(jq -r .goods_received_not_invoiced.difference <<<"$sub")" "0.00"
check "no missing, differing or orphaned postings" "$(jq -r '[.missing_postings, .amount_mismatches, .orphaned_postings] | map(length) | add' <<<"$sub")" "0"

[ "$failures" -eq 0 ] && echo "All inventory checks passed." || { echo "$failures check(s) failed."; exit 1; }
