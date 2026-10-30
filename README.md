# crm
test crm

## Current CRM Functionality

The application is a Vue 3 frontend backed by a Symfony JSON API. The current client workspace brings together client records, contacts, notes, linked projects, and a client activity timeline.

The activity timeline supports manual interaction entries and automatically records create, update, and delete events for client-linked projects and tasks. Notes attached to a client, project, or task also generate timeline entries; the note content itself is not duplicated in the timeline. These entries are written with the triggering record change, and records that cannot be associated with a client do not appear in a client timeline. Backend audit logs remain a separate operational record.

The Sales workspace currently supports a priced product catalog, quotes, quote-to-order conversion, shipment quantity tracking, order-generated draft invoices, explicit invoice issue state, and invoice payment records. Issuing an invoice and recording a customer receipt now create mapped general-ledger postings. Monetary amounts use fixed EUR/GBP/USD currency snapshots and integer minor-unit server calculations. This is an operational workflow, not tax-compliant invoicing, inventory valuation, payment processing, or statutory accounting; see [PRD.md](PRD.md) and [NOTES.md](NOTES.md) for scope and remaining requirements.

The Finance workspace adds configurable posting mappings, chart-of-account and fiscal-period setup, balanced manual and opening-balance journal posting, reversals, reasoned administrator close/reopen, automatic postings for issued invoices, customer receipts, vendor bills/payments, approved expenses, and reimbursements, plus a source-record posting check. It includes single-currency trial balance, operational P&L/balance-sheet/cash-movement summaries, and AR/AP reports. Configure active account mappings and open fiscal periods before posting. The reports are not statutory statements, external bank reconciliation, tax calculations, or tax filings.

Project Operations supports currency-scoped project budgets and billable hourly rates, per-user cost-rate snapshots on new time entries, manager/admin timesheet approval, category-tagged expense approval, administrator-recorded partial reimbursements, budget/cost/profitability summaries, and draft invoice creation from approved billable time or completed priced milestones. Task ownership, due/overdue indicators, and same-project task dependencies are available. Project details expose milestones and notes inline; client records include contacts, linked projects, activity, and search. Project documents use authenticated endpoints and local Symfony `var/` storage, limited to 10 MB and an allowed MIME list, without virus scanning or external backup. These workflows are operational estimates and do not provide payroll, FX conversion, or statutory project accounting.

The Inventory workspace supports warehouses, suppliers, product-based purchase orders, partial goods receipts, movement-derived stock balances, count adjustments, explicit dispatch of sales shipments, and bounded customer/supplier returns. Stock movements are append-only and quantity-only; dispatches and receipts do not automatically post to the general ledger. Stock valuation, transfers, bins, lots, and serial tracking are not implemented yet.

Stage 1 is not complete: organization/tenant scoping, deals and pipelines, structured legal/account data for clients, dedicated client/project detail routes and schedule views, full change/version recovery, and offline storage and synchronization remain roadmap requirements. Task due status is visible in the app, but outbound reminders/escalations are not implemented. See [NOTES.md](NOTES.md) for the staged roadmap and current implementation gaps.

## Remaining Work, Prioritized for ERP Use

| Priority | Remaining work | Why it matters |
|---|---|---|
| **P0 — Before production or multi-organization use** | Initial deployment is one organization per installation; initial countries are Latvia, Lithuania, Estonia, Germany, and the UK. Settings now captures country, default currency, and a 10-year retention period, with audited settings history and restore. Do not serve multiple organizations: tenant isolation is not implemented. Country tax/accounting profiles remain unverified, finance roles remain unchanged, and the accepted encrypted off-host daily backup policy (35-day retention, quarterly restore tests, 24-hour RPO/RTO) is not automated or verified. General business-record recovery and measured automated test coverage are not yet in place. | Country selection is not legal or tax compliance. Retention start rules, finance-specific access, backup implementation and restore verification, and jurisdiction review remain production blockers. |
| **P1 — Connect finance** | Implemented: mapped accrual postings for invoice issue/receipt, vendor bills/payments, approved expenses/reimbursements; opening-balance entries, reversals, reasoned period close/reopen, operational statements, and source-record posting checks. Remaining: verify reconciliation against ledger amounts and payment/external bank records, cover sales/inventory accounting, complete authorization/audit controls, and add backend integration coverage. | This establishes a connected operational ledger, not audited or statutory accounting. Postings require active mappings and open periods; tax and bank reconciliation are not implemented. |
| **P1 — Complete the CRM and delivery loop** | Implemented: client/project search, task assignment and due/overdue status, same-project task dependencies, milestone tracking, inline client/project context, and a project-management timetable with status and overdue summaries. Remaining: structured client legal/account data, dedicated detail routes, reminder delivery/escalation, and broader auditable change/version history. | Schedule visibility and status summaries are in place; complete account records and a unified client/project workspace still need product design and delivery. |
| **P1 — Link project costs to billing** | Implemented: timesheet approval, per-user cost-rate and project billing-rate snapshots, expense categories and partial manual reimbursements with ledger links, single-currency budget/profitability summaries, and draft invoices from approved time or completed priced milestones. Remaining: richer period-filtered project financial reporting, time-entry correction policies, and broader tests/permissions review. | Provides operational cost and billing visibility only; it is not payroll, tax accounting, FX conversion, or statutory project accounting. |
| **P1/P2 — Make inventory financially meaningful** | When inventory is material to the business, implement a validated stock-costing method, landed costs, GL postings for receipts/dispatches/returns, and purchasing controls such as PO approvals and supplier-invoice matching. | Current inventory is quantity-only and is not a valued accounting asset. |
| **P2 — Add jurisdiction-specific finance operations** | After confirming requirements, implement country-specific tax calculation, compliant invoice numbering and correction flows, bank/payment integrations and reconciliation, and FX where needed. | Configured tax codes are not applied, invoice output is not claimed to be legally compliant, and currencies are not converted. |
| **Later — Validate demand first** | Consider manufacturing, MRP, payroll, advanced planning, BI, and other broad ERP modules only when supported by user and market research. | These are later-stage, market-dependent capabilities, not prerequisites for a coherent CRM and project-focused ERP. |

**Recommended next milestone:** add and run backend integration tests against the new migrations and end-to-end posting flows, then verify the source-record reconciliation against the posted ledger.

The two new finance/project schema migrations have not been applied in this workspace. Frontend build/type-check and unit tests pass; backend validation is limited to PHP syntax checks because Composer/PHPUnit dependencies are unavailable here. See [PRD.md](PRD.md) and [NOTES.md](NOTES.md) for detailed scope and limitations.

## bundles
composer require symfony/security-bundle
composer require symfony/orm-pack
<!-- composer require symfony/rate-limiter -->
composer require lexik/jwt-authentication-bundle

composer require --dev symfony/profiler-pack
composer require --dev symfony/maker-bundle
composer require symfony/monolog-bundle

## Install

Default admin:
INSERT INTO app_user (email, password, roles) VALUES ('super@admin.local', '', '{'ROLE_ADMIN'}');

### Rsyslog
In php container execute commands
```
chmod +x bootstrap/setup-rsyslog-audit.sh
bootstrap/setup-rsyslog-audit.sh

chmod 600 /etc/logrotate.d/app-audit
```

### Debug
docker compose exec php composer show doctrine/dbal doctrine/doctrine-migrations-bundle --format=json
docker compose exec php composer update doctrine/dbal --with-dependencies --no-interaction
docker compose exec php composer show doctrine/doctrine-migrations-bundle --all --no-interaction
docker compose exec php composer show doctrine/migrations 4.0.x-dev --available --no-interaction
=> "doctrine/dbal": "^4.5@dev",

docker compose exec php composer update doctrine/dbal --with-all-dependencies --no-interaction
docker compose exec php bin/console doctrine:migrations:diff --no-interaction


### Reformat files
awk 'length($0) > 120 { print FNR ":" length($0) ":" $0 }' frontend/src/*.ts frontend/src/*.vue frontend/src/components/*.vue

find . -type f \( -name '*.vue' -o -name '*.ts' -o -name '*.css' -o -name '*.html' \) -print0 | xargs -0 awk 'length($0) > 120 { count++; print FILENAME ":" FNR ":" length($0) } END { print "TOTAL:" count }'

command -v prettier || true; find . -maxdepth 2 -type f -name 'prettier*' -o -name '.prettierrc*'

npx --yes prettier@3.6.2 --print-width 120 --write src

## commands
bin/console make:migration
bin/console cache:clear
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate --no-interaction

php bin/console lexik:jwt:generate-keypair


php bin/console messenger:consume async_incoming

php bin/console app:import-file /var/www/html/static/archive/file.csv

## Upgrade node

node --version
which node
apt policy nodejs

sudo apt update
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
