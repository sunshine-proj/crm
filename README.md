# crm
test crm

## Current CRM Functionality

The application is a Vue 3 frontend backed by a Symfony JSON API. The current client workspace brings together client records, contacts, notes, linked projects, and a client activity timeline.

The activity timeline supports manual interaction entries and automatically records create, update, and delete events for client-linked projects and tasks. Notes attached to a client, project, or task also generate timeline entries; the note content itself is not duplicated in the timeline. These entries are written with the triggering record change, and records that cannot be associated with a client do not appear in a client timeline. Backend audit logs remain a separate operational record.

The Sales workspace currently supports a priced product catalog, quotes, quote-to-order conversion, shipment quantity tracking, order-generated draft invoices, explicit invoice issue state, and invoice payment records. Issuing an invoice and recording a customer receipt now create mapped general-ledger postings. Monetary amounts use fixed EUR/GBP/USD currency snapshots and integer minor-unit server calculations. This is an operational workflow, not tax-compliant invoicing, inventory valuation, payment processing, or statutory accounting; see [PRD.md](PRD.md) and [NOTES.md](NOTES.md) for scope and remaining requirements.

The Finance workspace adds configurable posting mappings, chart-of-account and fiscal-period setup, balanced manual and opening-balance journal posting, reversals, reasoned administrator close/reopen, automatic postings for issued invoices, customer receipts, vendor bills/payments, approved expenses, and reimbursements, plus a source-record posting check. It includes single-currency trial balance, operational P&L/balance-sheet/cash-movement summaries, and AR/AP reports. Configure active account mappings and open fiscal periods before posting. The reports are not statutory statements, external bank reconciliation, tax calculations, or tax filings.

Project Operations supports currency-scoped project budgets and billable hourly rates, per-user cost-rate snapshots on new time entries, manager/admin timesheet approval, category-tagged expense approval, administrator-recorded partial reimbursements, budget/cost/profitability summaries, and draft invoice creation from approved billable time or completed priced milestones. Task ownership, due/overdue indicators, and same-project task dependencies are available. Project details expose milestones and notes inline; client records include contacts, linked projects, activity, and search. Project documents use authenticated endpoints and local Symfony `var/` storage, limited to 10 MB and an allowed MIME list, without virus scanning or external backup. These workflows are operational estimates and do not provide payroll, FX conversion, or statutory project accounting.

The Inventory workspace supports warehouses, suppliers, product-based purchase orders, partial goods receipts, movement-derived stock balances, count adjustments, explicit dispatch of sales shipments, and bounded customer/supplier returns. Stock movements are append-only and quantity-only; dispatches and receipts do not automatically post to the general ledger. Stock valuation, transfers, bins, lots, and serial tracking are not implemented yet.

Stage 1 is not complete: organization/tenant scoping, e-mail reminder delivery, and offline storage and synchronization remain roadmap requirements (see the P0/P1 notes below for deals, client account details, detail routes, the task board, in-app reminders, and change history, which are implemented in a first version). See [NOTES.md](NOTES.md) for the staged roadmap and current implementation gaps.

## Remaining Work, Prioritized for ERP Use

### P0 — Before production or multi-organization use

- [ ] Confirm the initial deployment countries, legal/accounting scope, retention triggers, and applicable retention periods with qualified domain experts.
- [x] Keep deployments limited to one organization until organization ownership and per-organization authorization are implemented and tested; do not enable multi-organization use before then.
- [ ] Automate encrypted off-host daily backups of the database and uploaded business documents, with 35-day retention; test restores quarterly and verify the 24-hour RPO/RTO.
- [x] Define and implement recovery/versioning for business records, beyond the existing settings-history restore.
- [x] Complete a least-privilege authorization and audit review across financial and operational resources. Finance write routes and reimbursement-history access have initial role/resource checks; add regression tests for allowed and denied access.
- [ ] Apply and verify the pending finance/project schema migrations, then run backend integration tests for end-to-end ledger postings, payment concurrency, and source-to-ledger reconciliation.
- [x] Establish measured automated test coverage for production-critical calculations, authorization, migrations, and lifecycle transitions.

P0 progress (a box is ticked only when nothing is left that the software can do; the three open boxes each need an action outside this repository, named below):

- **Legal and accounting scope (open — needs advisers):** a sign-off worksheet is in [docs/COMPLIANCE_SCOPE.md](docs/COMPLIANCE_SCOPE.md). It needs answers from qualified advisers; the software cannot supply them.
- **Single organization (done):** the database allows exactly one `organization_settings` row (`CHECK (id = 1)`), pinned by `SingleOrganizationGuardTest`. Business records have no tenant ownership, so multi-organization use stays unsupported; real tenant isolation is a separate project touching every entity, repository and endpoint.
- **Backups (open — needs a real off-host destination):** `docker/backup/` and the opt-in `backup` compose profile implement encrypted off-host daily backups with 35-day pruning, a container health check and a restore-test script; see [docs/BACKUP.md](docs/BACKUP.md). `scripts/verify-backup-drill.sh` ran the whole cycle against a disposable PostgreSQL 17 database (backup, pruning, health check, restore, row-count comparison, refusal to restore over the live database). That first run found and fixed two defects: the retention step failed on every run, and `latest` could pick a folder that is not a backup set. Still to do by whoever operates the deployment: configure the off-host remote and key pair, then run and record the quarterly restore test against it.
- **Record versioning and recovery (done):** create/update/delete of clients, contacts, deals, projects, milestones, tasks, products, suppliers and warehouses is written to an append-only `record_revision` table. Rows that the database removes or detaches when a parent is deleted (a client's contacts and deals, a project's milestones, the client link on its projects) are now versioned too, linked to the deleted parent. Administrators can roll a record back, or recreate a deleted one (new ID) together with those related records, through `/api/records/revisions` and Settings → Record history. Invoices, journals, payments and stock movements are deliberately excluded because they are corrected through their own workflows; timeline entries, deal stage history and time entries are not brought back. Projects with time entries, expenses or documents and clients with quotes or orders can no longer be deleted (previously time entries vanished silently and the others failed with a server error). Deleting a draft invoice now frees its time entries and milestone for re-invoicing.
- **Authorization and audit (done):** roles are `ROLE_USER`, `ROLE_FINANCE` (ledger, payables, reports, invoice issue, customer receipts, reimbursements, profitability and cost visibility), `ROLE_INVENTORY` (new: purchase orders, goods receipts, dispatches, returns) and `ROLE_ADMIN`, which inherits both and alone manages accounts, mappings, periods, opening balances, reversals, cost rates, stock adjustments, warehouses, suppliers, clients, settings and record recovery. Finance and inventory are separate on purpose: whoever approves a purchase order does not receive the goods unless given both roles. Grant roles with `php bin/console app:user:roles <email> ROLE_FINANCE ROLE_INVENTORY`. **Existing users lose purchase-order, receipt, dispatch and return rights until they are given `ROLE_INVENTORY`.** Notes can be changed only by their author or an administrator. Sign-in is throttled to five failed attempts per address and e-mail in 15 minutes. Every state-changing API request and every refused request (401/403) is written to the audit log with actor, route, record ids and outcome, never the request body; reads of client tax and billing details are flagged. `ApiAccessPolicyTest` pins the role of every route and the functional suites check allowed and denied HTTP behaviour. Access model, by decision rather than omission: all signed-in staff can read all clients, projects and tasks; there is no per-client or per-project visibility. Say so if that needs to change.
- **Migrations and ledger verification (open — needs the deployed database):** all 28 migrations apply to an empty PostgreSQL 17 database, reverse completely and apply again with the schema matching the entity mappings (`scripts/verify-migrations.sh`); the earlier index-name drift is gone. `scripts/verify-ledger-e2e.sh` (invoice issue, ten concurrent receipts of which exactly three are accepted, reconciliation, balanced trial balance), `verify-inventory-e2e.sh`, `verify-jurisdiction-e2e.sh` and the new `verify-recovery-e2e.sh` pass on fresh databases. **No deployed database has been migrated.** After a backup, run `docker compose exec php bin/console doctrine:migrations:migrate` there, then `doctrine:schema:validate`.
- **Coverage (done):** backend 192 tests / 1897 assertions pass; measured line coverage with pcov is **92.1%** (5732/6222) and method coverage 83.2%, up from 63.7% at the start of this pass. The finance, operations, invoice, sales, inventory and CRM controllers are now covered by HTTP flow tests. `composer test:coverage:check` fails below 90%. Frontend: 38 Vitest tests, 98.4% line coverage of `src/lib`; Vue components are not unit-tested. The new tests found and fixed: project document upload always failed with a server error (it relied on a package that is not installed), and project and news titles could be saved blank.
- **Dependencies:** Symfony was moved from 8.0 to 8.1.11 because the 8.0 line had 18 open security advisories (two high) with no fix; `composer audit` and `npm audit` now report none.

Country selection, retention settings, and operational finance reports do not by themselves establish legal compliance or production readiness.

### P1 — Complete core CRM, project delivery, and finance workflows

- [ ] Add structured, searchable client legal and account details, with appropriate privacy and access controls.
- [x] Add dedicated client and project detail routes that bring together contacts, activity, schedule, tasks, budget, time, expenses, and related invoices.
- [x] Add a separate project-management window/view where users can see and manage tasks on a Kanban board, alongside the existing timetable overview.
- [x] Implement deal and sales-pipeline management.
- [ ] Add task and project reminder/escalation delivery, with user-configurable timing and delivery behavior.
- [x] Define and implement auditable change/version history for important client, project, task, and commercial-record changes.
- [x] Add period-filtered project financial reporting and define correction/approval rules for submitted or approved time entries.
- [x] Reconcile operational AR/AP balances to their source records and posted ledger amounts; add external bank statement reconciliation when a bank workflow is selected.
- [ ] Define and test accounting treatment for sales and inventory transactions, where required.

These workflows extend the existing project timetable, approvals, operational ledger, and project cost/billing foundations; they do not imply statutory accounting, payroll, or tax compliance.

P1 progress (ticked items were re-verified against the code and tests; the three open ones are explained below):

- **Client legal and account details:** clients now carry legal name, registration and VAT numbers, country, billing address and e-mail, website, industry and account status, searchable and filterable. Registration/VAT numbers, billing address and billing e-mail are returned only to `ROLE_FINANCE`/`ROLE_ADMIN` (and excluded from search for other users); writes stay administrator-only. Reads that disclose those fields are flagged in the audit log. Open: field-level encryption and individual-customer or parent/child account models, which is why the box stays unticked.
- **Detail routes:** `#/clients/<id>` and `#/projects/<id>` bring together profile, contacts, activity, notes, deals, projects, tasks, milestones, time, expenses, invoices, budget, schedule and change history. The project financial report is shown to finance users only. The views assemble existing list endpoints client-side, so very large lists will need server-side pagination.
- **Task board:** Project Management has a Timetable / Task board toggle; tasks move between status columns by drag-and-drop or an accessible select, with rollback if the API rejects the move.
- **Deals and pipeline:** deals (lead, qualified, proposal, negotiation, won, lost) with value, expected close date, owner, mandatory loss reason, an append-only stage history, and a forecast using fixed stage probabilities per currency. Not done: converting a won deal into a quote or project, custom stages, and probability configuration.
- **Reminders and escalation:** `php bin/console app:reminders:run` (hourly via the `scheduler` compose service) creates idempotent in-app due-soon, overdue and escalation notifications. Each user sets task/project lead days, escalation threshold and an on/off switch; overdue work escalates to the project manager, or to administrators when the assignee is the manager. **E-mail delivery is not implemented, so the box stays unticked:** re-checked on 2026-11-13, every release of `symfony/mime` (required by `symfony/mailer`) is still blocked by Composer's security-advisory policy, and that policy was not overridden.
- **Change history:** deals join the versioned record types; any signed-in user can see who changed a record and which fields (`/api/history/<type>/<id>`), administrators see values and can restore. Records removed or detached by a parent's deletion are versioned as well. Not covered: invoices, journals and other financial records, which are corrected through their own workflows.
- **Time-entry corrections and project reporting:** authors can withdraw pending or rejected, uninvoiced time; a manager or administrator can reopen approved, uninvoiced time with a reason; invoiced time is locked. Decisions and reopenings are kept in an append-only history. Project profitability accepts a `from`/`to` period (time by work date, expenses by expense date). Invoiced time is corrected with a credit note on the issued invoice.
- **AR/AP reconciliation:** `/api/finance/reports/subledger-reconciliation?currency=` compares invoices, receipts, vendor bills, payments, approved expenses and reimbursements with their journal postings (missing, differing, orphaned) and AR/AP sub-ledgers with the mapped control accounts. Manual journals on a control account show up as differences by design. Bank statements are imported and matched to receipts and vendor payments in the Bank workspace (see P2).
- **Sales and inventory accounting:** the current treatment is written down in [docs/ACCOUNTING_TREATMENT.md](docs/ACCOUNTING_TREATMENT.md) and partly pinned by tests: revenue at invoice issue, no inventory asset or cost of sales, no credit notes. It still needs an accountant's decision and a costing method before it can be called validated.
- **Verification:** see the P0 notes above for the current test, coverage and migration results.


### P1/P2 — Make inventory financially meaningful

- [ ] Select and validate an inventory costing method before reporting stock value.
- [x] Implement landed-cost handling and general-ledger postings for receipts, dispatches, and returns.
- [x] Add purchasing controls such as purchase-order approvals and supplier-invoice matching.

Inventory is valued only when the inventory accounts are mapped; the valuation is an operational weighted-average figure, not a statutory inventory account.

P1/P2 progress (the costing method stays unticked until an accountant confirms it):

- **Costing method:** perpetual **weighted-average** cost per product and warehouse was chosen and implemented; the rationale and alternatives are in [docs/ACCOUNTING_TREATMENT.md](docs/ACCOUNTING_TREATMENT.md). It is a recommendation pending your accountant's confirmation, and changing it later means revaluing history. Stock value is reported at Inventory → Valuation (finance users), per currency, with average unit cost and any unvalued legacy quantity.
- **Landed costs and GL postings:** finance users can enter landed costs on a goods receipt; they are allocated to lines by value and capitalised. Receipts (Dr Inventory / Cr Goods received not invoiced), dispatches (Dr Cost of sales / Cr Inventory), customer and supplier returns, and stock adjustments now post to the ledger once the inventory, cost of sales, goods-received-not-invoiced and inventory-adjustment accounts are mapped. Without those mappings stock stays quantity-only. Not done: landed costs added after the receipt, allocation by weight or volume, credit notes (a customer return does not reverse revenue), tax, FX, and period-end write-downs.
- **Legacy stock:** quantity received before valuation was enabled must get an opening valuation before it can be issued; its offset goes to the inventory adjustment account and should be reviewed by an accountant.
- **Purchasing controls:** administrators can set an approval threshold; larger orders (and any order in a non-default currency) wait for a finance user other than the creator, with an append-only decision history, before goods can be received. Vendor bills can be linked to a purchase order for a three-way match against received value; a larger bill needs an override reason and is stored as an exception. Matched bills clear the accrual and any excess is expensed as purchase price variance. Not done: tax, bills spanning several orders, partial-line price tolerances, and multi-step or amount-banded approvals.
- **Reconciliation:** the sub-ledger report now also compares stock value and the goods-received-not-invoiced accrual with their ledger accounts.
- **Verification:** the new migration applies, reverses and re-applies on PostgreSQL 16 without schema drift; `scripts/verify-inventory-e2e.sh` (approval, receipt with landed cost, dispatch, bill matching, reconciliation) and `scripts/verify-ledger-e2e.sh` both pass on a fresh database. Scripts must run against a fresh database because remapping accounts changes which ledger accounts the controls compare.

### P2 — Add jurisdiction-specific finance operations

- [ ] For each supported country, confirm and implement validated tax calculation, rates, rounding, and required accounting configuration.
- [ ] Implement jurisdiction-compliant invoice numbering, corrections, and document output before claiming tax or invoicing compliance.
- [ ] Select and implement required bank/payment integrations and reconciliation workflows.
- [ ] Implement foreign-exchange rates and conversion only if multi-currency reporting is required, with explicit rate sources and historical-rate rules.

P2 progress (the software cannot validate tax law; every checkbox stays unticked until the named advisers have signed off):

- **Tax calculation and rounding:** integer, basis-point tax (half-up) with two rounding methods (once per rate, or per line), line tax codes, a per-rate breakdown frozen on each invoice, output-tax posting to a mapped tax-payable account, and tax reversed by credit notes. It is **off for a country until an administrator records a named adviser's tax sign-off** (including the rounding method) in Settings → Jurisdiction. Seed standard rates (LV 21%, LT 21%, EE 24%, DE 19%, GB 20%) are unverified starting values. Not done: input VAT on purchases, VAT settlement/returns, reduced rates and exemptions beyond manually created tax codes, reverse charge, and the tax-in-local-currency rule.
- **Numbering, corrections and document output:** a numbering sign-off turns on gapless `INV-<CC>-<year>-nnnnnn` / `CN-<CC>-<year>-nnnnnn` numbers allocated at issue under a row lock (verified with 8 concurrent issues, including the first of a year). Issued invoices are never edited; credit notes are the correction path (capped by the balance still owed and per-rate net). PDFs show the frozen seller and customer details, tax breakdown and payment instructions. Not done: e-invoicing formats (XRechnung, Peppol), customer-credit/refund flows, debit notes, and any claim that the output is compliant.
- **Bank workflow:** selected and implemented as provider-agnostic **statement import** (camt.053 and CSV) with exact-amount matching to recorded receipts and vendor payments, reference-based auto-match, ignore-with-reason, and a period report of unmatched items either way; see [docs/PAYMENTS_AND_BANKING.md](docs/PAYMENTS_AND_BANKING.md). Not done: bank feeds/open banking, payment initiation, card or direct-debit providers, posting of bank fees, and a ledger-cash-versus-bank-balance comparison.
- **Foreign exchange:** deliberately not implemented; the decision and what would trigger it are in [docs/ACCOUNTING_TREATMENT.md](docs/ACCOUNTING_TREATMENT.md).
- **Verification:** the new migrations apply, reverse and re-apply on PostgreSQL 16 without schema drift; `scripts/verify-jurisdiction-e2e.sh` (sign-offs, tax, numbering under concurrency, credit note, settlement, bank import and matching), `verify-ledger-e2e.sh` and `verify-inventory-e2e.sh` pass on fresh databases. Current test and coverage figures are in the P0 notes.

Without a recorded sign-off, invoices are untaxed and keep ID-based numbers. Invoices are never claimed to be legally compliant, and currencies are not converted.

### Later — Validate demand before expanding ERP scope

- [ ] Confirm user demand and scope before starting manufacturing/MRP, payroll, advanced planning, BI, or other broad ERP modules.
- [ ] Decide whether offline storage and synchronization are required, then define conflict resolution, data ownership, and security behavior before implementation.

The migrations apply cleanly to a throwaway PostgreSQL 17 database but have not been applied to any deployed database. Frontend build/type-check and unit tests pass, and the backend PHPUnit suite passes in the `crm/php-1.1` image once `composer install` has been run in `src/` (coverage needs an image built from `docker/php/Dockerfile`, which adds pcov). See [PRD.md](PRD.md) and [NOTES.md](NOTES.md) for detailed scope and limitations.

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
