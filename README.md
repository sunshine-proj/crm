# crm
test crm

## Current CRM Functionality

The application is a Vue 3 frontend backed by a Symfony JSON API. The current client workspace brings together client records, contacts, notes, linked projects, and a client activity timeline.

The activity timeline supports manual interaction entries and automatically records create, update, and delete events for client-linked projects and tasks. Notes attached to a client, project, or task also generate timeline entries; the note content itself is not duplicated in the timeline. These entries are written with the triggering record change, and records that cannot be associated with a client do not appear in a client timeline. Backend audit logs remain a separate operational record.

The Sales workspace currently supports a priced product catalog, quotes, quote-to-order conversion, shipment quantity tracking, order-generated draft invoices, explicit invoice issue state, and invoice payment records. Monetary amounts use fixed EUR/GBP/USD currency snapshots and integer minor-unit server calculations. This is an operational workflow, not a tax-compliant invoicing, inventory, payment-processing, or accounting system; see [PRD.md](PRD.md) and [NOTES.md](NOTES.md) for scope and remaining requirements.

The Finance workspace adds chart-of-account and fiscal-period setup, balanced manual journal posting, a single-currency trial balance, tax-code reference data, vendor bills and partial/full AP payments, and operational AR/AP balance reports. General-ledger postings are not automatically reconciled with invoice receipts, AP payments, sales, or configured tax codes. These are not statutory statements or tax filings.

The Operations workspace adds optional project budgets, project/task time entries, expenses with project-manager/admin approval history, and authenticated project-document uploads/downloads. Expenses do not initiate reimbursement or accounting postings. Project files are stored locally under ignored Symfony `var/` storage, limited to 10 MB and an allowed MIME list, and are not virus-scanned or externally backed up.

The Inventory workspace supports warehouses, suppliers, product-based purchase orders, partial goods receipts, movement-derived stock balances, count adjustments, explicit dispatch of sales shipments, and bounded customer/supplier returns. Stock movements are append-only and quantity-only; dispatches and receipts do not automatically post to the general ledger. Stock valuation, transfers, bins, lots, and serial tracking are not implemented yet.

The Operations workspace adds optional project budgets, project/task time entries, project expenses with manager/admin approval history, and protected project-document uploads. Uploads are limited to 10 MB and an allowlist of common document/image formats; files are stored locally under ignored Symfony `var/` storage without virus scanning or external backup. Expense approval does not initiate reimbursement or ledger posting.

Stage 1 is not complete: organization/tenant scoping, deals and pipelines, a dedicated client detail route, append-only/soft-delete/version recovery, and offline storage and synchronization remain roadmap requirements. See [NOTES.md](NOTES.md) for the staged roadmap and current implementation gaps.

## Remaining Work, Prioritized for ERP Use

| Priority | Remaining work | Why it matters |
|---|---|---|
| **P0 — Before production or multi-organization use** | Initial deployment is one organization per installation; initial countries are Latvia, Lithuania, Estonia, Germany, and the UK. Settings now captures country, default currency, and a 10-year retention period, with audited settings history and restore. Do not serve multiple organizations: tenant isolation is not implemented. Country tax/accounting profiles remain unverified, finance roles remain unchanged, and the accepted encrypted off-host daily backup policy (35-day retention, quarterly restore tests, 24-hour RPO/RTO) is not automated or verified. General business-record recovery and measured automated test coverage are not yet in place. | Country selection is not legal or tax compliance. Retention start rules, finance-specific access, backup implementation and restore verification, and jurisdiction review remain production blockers. |
| **P1 — Connect finance** | Post invoices and customer receipts, vendor bills and payments, sales, and approved expenses to the general ledger; add subledger-to-GL reconciliation. Add opening balances, corrections/reversals, controlled close/reopen, and financial statements. | The general ledger is manual while AR/AP are separate operational subledgers, so the reports are not yet a coherent, reconciled accounting picture. |
| **P1 — Complete the CRM and delivery loop** | Add dedicated client/project detail views, structured organization details, practical search and filtering, task ownership and reminders, milestones/dependencies, schedule views, and meaningful change history. Bring client activity, project work, and financial history together. | Client and project workflows are the product core, but these documented gaps make the existing modules harder to use as one workspace. |
| **P1 — Link project costs to billing** | Add timesheet approval and cost rates, expense categories and reimbursement/accounting linkage, budget-versus-actual and profitability reports, and invoice creation from approved time or milestones. | Time, budgets, and expense approvals exist, but do not yet provide payroll, reimbursement, accounting postings, or profitability reporting. |
| **P1/P2 — Make inventory financially meaningful** | When inventory is material to the business, implement a validated stock-costing method, landed costs, GL postings for receipts/dispatches/returns, and purchasing controls such as PO approvals and supplier-invoice matching. | Current inventory is quantity-only and is not a valued accounting asset. |
| **P2 — Add jurisdiction-specific finance operations** | After confirming requirements, implement country-specific tax calculation, compliant invoice numbering and correction flows, bank/payment integrations and reconciliation, and FX where needed. | Configured tax codes are not applied, invoice output is not claimed to be legally compliant, and currencies are not converted. |
| **Later — Validate demand first** | Consider manufacturing, MRP, payroll, advanced planning, BI, and other broad ERP modules only when supported by user and market research. | These are later-stage, market-dependent capabilities, not prerequisites for a coherent CRM and project-focused ERP. |

**Recommended next milestone:** deliver one auditable end-to-end flow: client/project → approved work and expenses → invoice and payment → GL posting → reconciliation and report.

This table summarizes documented roadmap gaps; it is not an independent verification of implementation status. See [PRD.md](PRD.md) and [NOTES.md](NOTES.md) for detailed scope and limitations.

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
