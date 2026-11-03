# crm
test crm

## Current CRM Functionality

The application is a Vue 3 frontend backed by a Symfony JSON API. The current client workspace brings together client records, contacts, notes, linked projects, and a client activity timeline.

The activity timeline supports manual interaction entries and automatically records create, update, and delete events for client-linked projects and tasks. Notes attached to a client, project, or task also generate timeline entries; the note content itself is not duplicated in the timeline. These entries are written with the triggering record change, and records that cannot be associated with a client do not appear in a client timeline. Backend audit logs remain a separate operational record.

The Sales workspace currently supports a priced product catalog, quotes, quote-to-order conversion, shipment quantity tracking, order-generated draft invoices, explicit invoice issue state, and invoice payment records. Issuing an invoice and recording a customer receipt now create mapped general-ledger postings. Monetary amounts use fixed EUR/GBP/USD currency snapshots and integer minor-unit server calculations. This is an operational workflow, not tax-compliant invoicing, inventory valuation, payment processing, or statutory accounting; see [PRD.md](PRD.md) and [NOTES.md](NOTES.md) for scope and remaining requirements.

The Finance workspace adds configurable posting mappings, chart-of-account and fiscal-period setup, balanced manual and opening-balance journal posting, reversals, reasoned administrator close/reopen, automatic postings for issued invoices, customer receipts, vendor bills/payments, approved expenses, and reimbursements, plus a source-record posting check. It includes single-currency trial balance, operational P&L/balance-sheet/cash-movement summaries, and AR/AP reports. Configure active account mappings and open fiscal periods before posting. The reports are not statutory statements, external bank reconciliation, tax calculations, or tax filings.

Project Operations supports currency-scoped project budgets and billable hourly rates, per-user cost-rate snapshots on new time entries, manager/admin timesheet approval, category-tagged expense approval, administrator-recorded partial reimbursements, budget/cost/profitability summaries, and draft invoice creation from approved billable time or completed priced milestones. Task ownership, due/overdue indicators, and same-project task dependencies are available. Project details expose milestones and notes inline; client records include contacts, linked projects, activity, and search. Project documents use authenticated endpoints and local Symfony `var/` storage, limited to 10 MB and an allowed MIME list, without virus scanning or external backup. These workflows are operational estimates and do not provide payroll, FX conversion, or statutory project accounting.

The Inventory workspace supports warehouses, suppliers, product-based purchase orders, partial goods receipts, movement-derived stock balances, count adjustments, explicit dispatch of sales shipments, and bounded customer/supplier returns. Stock movements are append-only and quantity-only; dispatches and receipts do not automatically post to the general ledger. Stock valuation, transfers, bins, lots, and serial tracking are not implemented yet.

Stage 1 is not complete: organization/tenant scoping, deals and pipelines, structured legal/account data for clients, dedicated client/project detail routes, full change/version recovery, and offline storage and synchronization remain roadmap requirements. The project-management overview includes a project/task timetable, but project-level schedule views and a separate Kanban board view for tasks are not implemented. Task due status is visible in the app, but outbound reminders/escalations are not implemented. See [NOTES.md](NOTES.md) for the staged roadmap and current implementation gaps.

## Remaining Work, Prioritized for ERP Use

### P0 — Before production or multi-organization use

- [ ] Confirm the initial deployment countries, legal/accounting scope, retention triggers, and applicable retention periods with qualified domain experts.
- [ ] Keep deployments limited to one organization until organization ownership and per-organization authorization are implemented and tested; do not enable multi-organization use before then.
- [ ] Automate encrypted off-host daily backups of the database and uploaded business documents, with 35-day retention; test restores quarterly and verify the 24-hour RPO/RTO.
- [ ] Define and implement recovery/versioning for business records, beyond the existing settings-history restore.
- [ ] Complete a least-privilege authorization and audit review across financial and operational resources. Finance write routes and reimbursement-history access have initial role/resource checks; add regression tests for allowed and denied access.
- [ ] Apply and verify the pending finance/project schema migrations, then run backend integration tests for end-to-end ledger postings, payment concurrency, and source-to-ledger reconciliation.
- [ ] Establish measured automated test coverage for production-critical calculations, authorization, migrations, and lifecycle transitions.

P0 progress (open items are listed explicitly; none of the checkboxes above is ticked until its remaining gap is closed):

- **Legal and accounting scope:** a sign-off worksheet is in [docs/COMPLIANCE_SCOPE.md](docs/COMPLIANCE_SCOPE.md). It needs answers from qualified advisers; the software cannot supply them.
- **Single organization:** `SingleOrganizationGuardTest` pins the one-row `organization_settings` rule. Business records have no tenant ownership, so multi-organization use stays unsupported; real tenant isolation is a separate project touching every entity, repository and endpoint.
- **Backups:** `docker/backup/` and the opt-in `backup` compose profile implement encrypted off-host daily backups with 35-day pruning and a restore-test script; see [docs/BACKUP.md](docs/BACKUP.md). The scripts have not been run against a real off-host remote, and no restore test has been recorded.
- **Record versioning and recovery:** create/update/delete of clients, contacts, projects, milestones, tasks, products, suppliers and warehouses is written to an append-only `record_revision` table. Administrators can list revisions and roll a record back or recreate a deleted one (new ID) through `/api/records/revisions` and Settings → Record history. Rows removed by database cascades (for example a client's projects) are not captured, and invoices, journals, payments and stock movements are deliberately excluded because they are corrected through their own workflows.
- **Authorization:** `ROLE_FINANCE` (new) covers ledger, payables, reports, invoice issue, customer receipts, reimbursements, profitability and cost visibility. `ROLE_ADMIN` inherits it and alone configures accounts, mappings and periods, posts opening balances and reversals, manages cost rates, stock adjustments, clients, settings and record recovery. Grant roles with `php bin/console app:user:roles <email> ROLE_FINANCE`. `ApiAccessPolicyTest` pins the role of every route and `ApiAuthorizationTest` checks allowed/denied HTTP behaviour. Still open: warehouse/supplier setup, purchase orders, receipts, dispatch and returns are available to every authenticated user; there is no per-client/project record-level access; audit-log coverage was not reviewed.
- **Migrations and ledger verification:** all 19 migrations were applied to a fresh PostgreSQL 16 database. `doctrine:schema:validate` still reports pre-existing index-name drift between migrations and entity mappings (it does not involve the new table). `scripts/verify-ledger-e2e.sh` passed against that database: invoice issue, ten concurrent receipts (exactly three of 30.00 accepted against a 100.00 invoice), reconciliation and a balanced trial balance. The production database has not been migrated.
- **Coverage:** backend 58 tests / 486 assertions pass; measured line coverage with pcov is 26.8% (1037/3876) and method coverage 35.6%, with most controllers still uncovered. `composer test:coverage` and `npm run test:coverage` (Vitest, `src/lib` only) are configured. Production-critical areas (invoice and expense workflows, inventory, sales) still lack tests.

Country selection, retention settings, and operational finance reports do not by themselves establish legal compliance or production readiness.

### P1 — Complete core CRM, project delivery, and finance workflows

- [ ] Add structured, searchable client legal and account details, with appropriate privacy and access controls.
- [ ] Add dedicated client and project detail routes that bring together contacts, activity, schedule, tasks, budget, time, expenses, and related invoices.
- [ ] Add a separate project-management window/view where users can see and manage tasks on a Kanban board, alongside the existing timetable overview.
- [ ] Implement deal and sales-pipeline management.
- [ ] Add task and project reminder/escalation delivery, with user-configurable timing and delivery behavior.
- [ ] Define and implement auditable change/version history for important client, project, task, and commercial-record changes.
- [ ] Add period-filtered project financial reporting and define correction/approval rules for submitted or approved time entries.
- [ ] Reconcile operational AR/AP balances to their source records and posted ledger amounts; add external bank statement reconciliation when a bank workflow is selected.
- [ ] Define and test accounting treatment for sales and inventory transactions, where required.

These workflows extend the existing project timetable, approvals, operational ledger, and project cost/billing foundations; they do not imply statutory accounting, payroll, or tax compliance.

### P1/P2 — Make inventory financially meaningful

- [ ] Select and validate an inventory costing method before reporting stock value.
- [ ] Implement landed-cost handling and general-ledger postings for receipts, dispatches, and returns.
- [ ] Add purchasing controls such as purchase-order approvals and supplier-invoice matching.

Inventory currently tracks quantities and movements only; it is not a valued accounting asset.

### P2 — Add jurisdiction-specific finance operations

- [ ] For each supported country, confirm and implement validated tax calculation, rates, rounding, and required accounting configuration.
- [ ] Implement jurisdiction-compliant invoice numbering, corrections, and document output before claiming tax or invoicing compliance.
- [ ] Select and implement required bank/payment integrations and reconciliation workflows.
- [ ] Implement foreign-exchange rates and conversion only if multi-currency reporting is required, with explicit rate sources and historical-rate rules.

Configured tax codes are not applied to documents, invoices are not claimed to be legally compliant, and currencies are not converted.

### Later — Validate demand before expanding ERP scope

- [ ] Confirm user demand and scope before starting manufacturing/MRP, payroll, advanced planning, BI, or other broad ERP modules.
- [ ] Decide whether offline storage and synchronization are required, then define conflict resolution, data ownership, and security behavior before implementation.

The pending finance/project migrations apply cleanly to a throwaway PostgreSQL database but have not been applied to any deployed database. Frontend build/type-check and unit tests pass, and the backend PHPUnit suite passes when run in the `karamele/php-fpm` image with Composer dependencies installed. See [PRD.md](PRD.md) and [NOTES.md](NOTES.md) for detailed scope and limitations.

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
