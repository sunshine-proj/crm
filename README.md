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
- **Time tracking and workload (Phase 8):** time entries are visible to their author, the project manager (for their projects) and finance/administrators only. The Time Report section shows an employee × project matrix with task and entry drill-down, CSV export and a weekly workload view; the Timesheet section enters a week of time in one batch; tasks carry estimates, logged vs. estimated time and a start/stop timer; `app:reminders:run` also reminds regular time loggers about a working day without time; project financial reports warn at 80% budget use. See [ROADMAP.md](ROADMAP.md).
- **Configurable modules (Phase 9):** administrators turn modules on or off per installation, restrict them to roles and adjust module settings in Settings → Modules, or with `php bin/console app:modules:set --preset=projects_invoicing`. Routes of a disabled module answer 404 (403 when hidden from the role); data stays in the database. Accounting off means invoices, payments and expenses are saved without ledger postings. See [ROADMAP.md](ROADMAP.md).
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

### P2 — Document management (DMS) module

Goal: a document workspace with registered, versioned documents. Employees can send documents for approval up their reporting line. Management can send documents to units and sections for acknowledgement and track who has confirmed. The model follows established DMS/ECM products (registration, versioning, routed approval with an approval sheet, acknowledgement lists, an audit trail, retention). Phases 1 (organization structure), 2 (document registry) and 3 (approval) are implemented; Phases 4-5 are not yet. The current `ProjectDocument` upload stays as it is. The DMS is a separate module that reuses its storage, MIME and size-limit rules, the in-app `Notification` mechanism, and the append-only decision history pattern used by purchase-order and expense approvals.

Decisions to confirm before building:

- [ ] Who counts as "management staff": heads of a unit/section acting within their own subtree only (proposed), plus a new `ROLE_DMS_ADMIN` (registry clerk / HR) acting organization-wide; `ROLE_ADMIN` inherits it.
- [ ] Approval routing rule: either (a) the submitter picks approvers from their own chain of superiors only (proposed default), or (b) routes are generated automatically from per-document-type templates (direct head → unit head → N levels up), or both.
- [ ] Whether a simple electronic confirmation (authenticated user + timestamp + content hash) is enough for approvals and acknowledgements, or whether qualified e-signatures (eIDAS) are required. Qualified signatures would be a separate integration.
- [ ] Retention periods per document type and the legal-hold policy; this links to the open P0 retention item.

**Phase 1 — Organization structure and subordination (prerequisite)** (done; see notes below)

- [x] Add `OrgUnit` as a tree (organization → unit → section → sub-section, depth-limited, no cycles) with code, name, parent, head position, and active/archived state.
- [x] Add `Position` (title, unit, is-head flag) and `Employment` (user, position, primary flag, valid from/to) so a user can hold a primary and an acting position.
- [x] Derive reporting lines: a user's direct superior is the head of their unit, or the head of the parent unit if the user is the head. Allow an optional explicit `reportsTo` override per employment.
- [x] Add a `SubordinationService` that returns a user's chain of superiors, direct and transitive subordinates, and the units under a manager. Cover it with unit tests for cycles, vacancies (head position empty, so skip to the next level up), and acting positions.
- [x] Add substitution/delegation: a user can name a deputy for a date range, and approval and acknowledgement tasks are redirected to the deputy with both names recorded. (Deputies are resolved by `OrgChart::deputyFor()`; approvals are redirected since Phase 3. Acknowledgements deliberately stay personal: a deputy cannot confirm that someone else has read a document, so managers mark absent people excused instead; see Phase 4.)
- [x] Add admin APIs and a Vue org-chart editor (tree view, drag to re-parent, assign heads/employees), plus a minimal selector endpoint like the existing manager selector.
- [x] Add a Doctrine migration, version org changes in record history, and make sure historical approvals keep the snapshot of approver/unit names that applied at the time. (Approval steps snapshot the assignee's position and unit since Phase 3.)

Phase 1 notes:

- **Model:** `org_unit` (one top-level organization; units and sections below it, at most 8 levels, unique codes), `org_position` (at most one active head post per unit), `employment` (dated; at most one primary post per person at a time, one holder per head post, optional `reports_to` override, acting/secondary flags) and `delegation` (deputy for a date range; revoked, never edited). Units and positions that have been used are archived rather than deleted. Employments are ended with an end date; deleting one is only for correcting mistakes. Unit, position and employment changes are versioned in `/api/history`.
- **Subordination rules** (`App\Service\Org\OrgChart`, built per day by `SubordinationService`): a person's direct superior is their `reports_to` override, otherwise the head of their unit, or of the parent unit when they head their own. Vacant head posts are skipped. Chains stop at reporting cycles, and the API refuses overrides that would create one. A manager's subtree is every active unit below the units they head, including as acting head. Deputy chains are followed up to five hops.
- **API:** `GET /api/org/units|positions|employments|people|me`, `/api/org/units/{id}/members?include_subunits=1` and `/api/org/users/{id}/reporting-line` are open to every signed-in user. Writes under `/api/org` need `ROLE_ADMIN`. `/api/delegations` lets users name and revoke their own deputies; administrators can act for anyone and list everything.
- **UI:** a new **Organization** section with the user's reporting line, deputies, a structure tree (drag a unit onto another to move it; there is also a parent selector in the edit form), unit details, positions, employees and change history.
- **Not yet:** user display names (people are identified by e-mail and position), bulk import of an existing org chart, and a date picker for viewing the structure on a past day (the API computes it per day internally).
- **Verification:** backend suite 213 tests / 2,225 assertions (21 new: entity rules, `OrgChart` subordination cases, HTTP flows for access, invariants and delegations), frontend 44 tests including `lib/org.ts`, `npm run build` passes, and `scripts/verify-migrations.sh` passes on PostgreSQL 17 (29 migrations). Not applied to any deployed database.

**Phase 2 — Document registry, storage and versioning** (done except the two open items; see notes below)

- [x] Add `DocumentType` (e.g. order, policy, instruction, memo, contract, regulation) with a registration-number pattern, required metadata fields, default approval route template, default retention period, and confidentiality level. (The route template, automatic registration and resubmission mode were added in Phase 3.)
- [x] Add a `Document` entity: type, title, summary, author, owner unit, status (`draft` → `in_approval` → `approved` / `rejected` / `withdrawn` → `registered` → `in_force` → `superseded` / `archived`), registration number and date, effective date, expiry/review date, confidentiality, tags, and links to client/project/deal records.
- [x] Number registered documents gaplessly per type and year under a row lock, reusing the `NumberSequence` approach used for invoices.
- [x] Add `DocumentVersion` records holding file, content SHA-256, uploader, and change comment. Versions are immutable. Editing an approved document creates a new draft version and invalidates earlier approvals for it.
- [x] Add check-out/check-in locking so two people cannot overwrite each other's draft, with an admin override that is logged.
- [x] Add attachments (supporting files) separate from the main version, and related-document links (replaces, amends, refers to).
- [ ] Render a PDF of each version for in-browser preview. Add a server-generated approval sheet / acknowledgement sheet PDF (`application/pdf`) with a hash and registration stamp. (Done: in-browser preview for PDF, image and text versions, and a registration card PDF with number, versions and their SHA-256, and history. The acknowledgement sheet PDF was added in Phase 4. Open: converting Word/Excel/OpenDocument files to PDF needs a converter such as LibreOffice in the image, and there is no approval sheet PDF yet; the approval history is shown on the document page.)
- [x] Apply access control on the backend: author, approvers, recipients, owner-unit managers and `ROLE_DMS_ADMIN` can read; confidential documents are visible only to named readers. Flag confidential reads in the audit log. Add allowed/denied regression tests for every route. (Approval participants were added in Phase 3 and acknowledgement recipients in Phase 4.)
- [ ] Add full-text and metadata search (PostgreSQL `tsvector` over title, summary, number and extracted PDF/DOCX text) with filters by type, status, unit, author, and date range. (Done: word-by-word search over number, title, summary, tags, type and text extracted from text, DOCX, XLSX and ODT files, plus every filter listed. Open: `tsvector` ranking and stemming, which would need native SQL that the application's ORM-only rule does not allow yet, and PDF text extraction, which needs a parser library.)

Phase 2 notes:

- **Roles and access:** the new `ROLE_DMS_ADMIN` (registry clerk; assign it with `app:user:roles`) maintains document types, registers and archives documents, and can read and manage every document. `ROLE_ADMIN` inherits it. Everyone else sees:
  - documents they wrote;
  - documents where they are a named reader;
  - general drafts owned by a unit they head (or a unit below it);
  - every registered general document.

  Confidential documents are visible only to the author, named readers and clerks, and every read of one is written to the audit log. Documents a user cannot read answer `404`, so their existence is not disclosed.
- **Lifecycle:** a draft gets versions (immutable, SHA-256, text extracted for search), attachments, links, readers and metadata while it is editable and not checked out by someone else. Registration needs a clerk, at least one version, no check-out, the type's required fields, and approval when the type requires it (approval arrives in Phase 3). It takes the next `{CODE}-{YYYY}-{SEQ:n}`-style number, and the document is `in_force` at once or `registered` until its effective date. Registered documents cannot be edited; they are archived, and unregistered drafts can be deleted with their files. A new version on an approved document returns it to draft. Six starting types are seeded by the migration (Order, Policy, Instruction, Regulation, Contract, Memo).
- **API:** `/api/dms/types` (list for everyone, writes for clerks) and `/api/dms/documents`: list with search and filters, create, show, update, delete, `versions` (multipart upload and file download, `inline=1` preview for safe types), `checkout` (`force` for clerks), `checkin`, `register`, `archive`, `readers`, `attachments`, `links`, `card.pdf`. Every action is recorded in the document's history, including downloads.
- **Storage:** files live in `src/var/project-documents/dms`, inside the folder the daily backup already archives (see [docs/BACKUP.md](docs/BACKUP.md)). They use the same 10 MB limit, content-detected type checks and random storage names as project documents, plus OpenDocument text. Since Phase 5 uploads are scanned by ClamAV when `DMS_CLAMAV_DSN` is set.
- **UI:** a new **Documents** section with search and filters, a new-draft form (with optional first file), a document page (actions, details, versions with preview and download, attachments, related documents, access, history) at `#/documents/<id>`, and a **Document types** tab for clerks.
- **Later phases:** approval and acknowledgement were added in Phases 3 and 4; supersession and the scheduled `registered` → `in_force` switch in Phase 5.
- **Verification:** backend suite 229 tests / 2,728 assertions (16 new: entity rules, text extraction, and HTTP flows for lifecycle, numbering, visibility, confidentiality, check-out, attachments, links, deletion and validation). `scripts/verify-migrations.sh` passes on PostgreSQL 17 (30 migrations). The new `scripts/verify-dms-e2e.sh` passes against a throwaway API on PostgreSQL 17 (13 checks, including eight concurrent registrations receiving consecutive numbers); its first run found and fixed a search-escaping defect. Frontend: 49 tests, type-check and build pass. The new pages were not checked in a browser. Not applied to any deployed database.

**Phase 3 — Approval workflow (by subordination)** (done; see notes below)

- [x] Add `ApprovalRoute` and `ApprovalStep` records with sequential and parallel stages, step types (review / approve / sign), a per-step due date, and a rule for whether a step needs all approvers or any one of them.
- [x] When a document is submitted, validate approvers against the `SubordinationService`. A user may send only to people in their own chain of superiors (or to the route template's resolved approvers). Self-approval is impossible, and the server rejects out-of-hierarchy approvers.
- [x] Add approver actions: approve, approve with comments, reject (reason required), return for revision (back to the author with comments), and delegate/forward to a subordinate for review (the delegating approver still owes the decision). Store every action as append-only history with actor, on-behalf-of, timestamp, comment and version hash.
- [x] Add author actions: withdraw before the final decision, resubmit a revised version (route restarts or continues from the rejecting step, per type setting), and add a reviewer.
- [x] Add inline comments/remarks per version and a side-by-side comparison of two versions (text diff for extracted text).
- [x] Handle deadlines: in-app reminders before the due date and escalation to the approver's superior when overdue, via the existing `app:reminders:run` scheduler and `ReminderPreference`. E-mail delivery stays blocked as described in P1.
- [x] Send a final approved document to registration automatically, or for the registry clerk to register, per type.
- [x] Add Vue screens: "My documents", "Awaiting my approval" inbox with counters, a document card with a route timeline (who, status, when, comments), and a route builder that offers only eligible superiors.

Phase 3 notes:

- **Who can be on a route:**
  - **Approve and sign steps** go only to the author's superiors; the API rejects anyone else with `409`.
  - **Review steps** are advisory endorsements by peers (for example legal or finance). They may go to any colleague who holds a position.
  - **Exclusions:** the author can never be a participant.
  - **Forwarding:** an approver can forward a step to their own subordinates only, as a non-blocking "requested review"; the decision stays with the approver.
  - **Deputies:** while a delegation is active, the deputy sees the step in their inbox and decides it. The step and the log record both the deputy and the person they acted for. This completes the Phase 1 delegation item.
- **Route mechanics:**
  - **Stages:** stages run in order. Within a stage everyone is asked at once, and the stage completes when everyone agrees (`all`) or on the first approval (`any`).
  - **Ending:** a rejection or a return ends the round. Rejected documents become `rejected`; returned ones go back to `draft`.
  - **Pinned version:** each round is pinned to the submitted version's number and SHA-256, and the document cannot change while it is in approval.
  - **Snapshots:** each step keeps the assignee's position and unit as they were when the step was created. This completes the Phase 1 snapshot item.
  - **Locking:** decisions lock the route row, so parallel approvals are counted once.
  - **Concurrent submissions:** a second simultaneous submission answers `409`.
- **Document type settings:**
  - **Default route:** stages at levels up the author's reporting line, with optional days to decide. The seeded Order type is approve by the direct superior, then sign one level up; Policy, Instruction, Regulation and Contract are approve by the direct superior.
  - **Automatic registration** after the final approval. If it fails, for example because a required field is missing, the document stays `approved` and the failure is logged.
  - **Resubmission mode:** `restart` runs every stage again; `continue` keeps stages approved in the previous round.
- **Comments and comparison:**
  - **Comments** belong to a version and may quote a passage. They are resolved, not deleted, by the writer, the author or a clerk.
  - **Comparison** aligns the extracted text of two versions sentence by sentence and lists added and removed passages. It is not a positional annotation on the rendered page, and it needs extractable text, so PDFs and images cannot be compared yet.
- **Reminders:** `app:reminders:run` reminds approvers of steps that are due (using the task lead time), marks them overdue, and escalates to the assignee's direct superior after the escalation threshold (to administrators if there is none). Notifications link to the document.
- **Access:** participants, and their deputies while they act, can read the document, including confidential ones. This is added to both `DocumentAccess` and the list query.
- **API:**
  - `GET /api/dms/approvals/inbox` and `/counts`.
  - `POST /api/dms/approvals/steps/{id}/decision` with `approve`, `reject`, `return` or `review`.
  - `POST /api/dms/approvals/steps/{id}/forward` and `GET .../forward-candidates`.
  - Under `/api/dms/documents/{id}/approval`: `GET` (all rounds), `GET candidates`, `POST submit`, `POST withdraw`, `POST reviewers`.
  - Under `/api/dms/documents/{id}`: `GET`/`POST comments`, `POST comments/{id}/resolve`, `GET versions/compare?from=&to=`.
- **UI:**
  - **Documents section:** an "Awaiting me" tab with a counter.
  - **Document page:** an Approval panel with the route builder, which offers superiors for approve and sign steps and colleagues for reviews; the stage and step timeline with decisions, comments and deputies; decision, forwarding, withdraw and add-reviewer controls; a log and earlier rounds. There is also a Comments panel with version comparison.
  - **Document types:** clerks edit the default route, automatic registration and the resubmission mode.
  - **Notifications:** the bell links document notifications. "My documents" is the registry's "Only mine" filter.
- **Verification:**
  - **Backend:** 247 tests / 3,239 assertions (18 new: the route aggregate, comparison and template validation, plus HTTP flows for parallel and sequential stages with automatic registration, subordination rules, templates, rejection, return, withdrawal and both resubmission modes, deputies and forwarding, added reviewers, comments and comparison, and reminders with escalation).
  - **Migrations:** `scripts/verify-migrations.sh` passes on PostgreSQL 17 (31 migrations).
  - **End-to-end:** `scripts/verify-dms-e2e.sh` with the new optional `APPROVERS` checks passes against a throwaway API (19 checks: two simultaneous submissions create one round, and three simultaneous approvals of an `all` stage complete it once and register exactly one number). A run with the row lock temporarily removed also passed, so that check is a smoke test of the concurrent path, not proof the lock is needed; the lock remains in place.
  - **Frontend:** 54 tests, type-check and build pass. The new screens were not checked in a browser. Not applied to any deployed database.

**Phase 4 — Acknowledgement (familiarization) campaigns** (done; see notes below)

- [x] Add an `AcknowledgementRequest` for a document version: sender, message, due date, whether opening the document is required before confirming, and whether people who join later are added automatically.
- [x] Let recipients be chosen by unit/section (with an "include sub-units" toggle), by position, or by individual employee. Senders can target only units in their own management subtree; `ROLE_DMS_ADMIN` can target the whole organization.
- [x] Snapshot the recipient list at send time (user, unit, position) as `AcknowledgementRecipient` rows, so later org changes do not rewrite who was asked. Add employees who join later to open requests when that option is on.
- [x] Track per-recipient state: `pending` → `viewed` (first open of the document, timestamp) → `acknowledged` (explicit "I have read and understood" confirmation with timestamp and version hash). Add `overdue` past the due date, and `excused` (manager marks leave or absence, with a reason).
- [x] Add a status dashboard for the sender and managers of affected units: completion % per unit/section tree, drill-down to employees, filters (pending / overdue / acknowledged), CSV export, and a printable acknowledgement sheet PDF.
- [x] Add actions: remind all pending, remind one, extend the deadline, and add recipients. Reminders and escalation to the employee's direct superior go through the scheduler.
- [x] When a document is re-issued as a new version, optionally launch a re-acknowledgement campaign for the new version only. (Registered documents cannot take new versions, so a new edition is normally a new document linked as replacing or amending the old one; re-issuing works for both cases.)
- [x] Add an employee "To acknowledge" inbox with counters on the dashboard, plus each employee's history of acknowledged documents.

Phase 4 notes:

- **Who can send:**
  - **Unit and section heads** (including acting heads) can address the units they head and everything below them: whole units (optionally with sub-units), positions in those units, and people employed there. Anything outside answers `409`.
  - **Registry clerks** (`ROLE_DMS_ADMIN`) can address the whole organization, including people without a position.
  - **Documents:** only registered documents that are `registered` or `in_force`. The request is pinned to the current version's number and SHA-256. The sender is never on their own list.
- **Who was asked:**
  - **Snapshot:** each person is recorded with the unit and position that put them on the list. Later moves, renames and departures do not change the record; the status tree rolls those units up the current structure.
  - **Joiners:** with "add people who join" on, someone who starts in a targeted unit or position is added when the employment is recorded, and again by `app:reminders:run` for employments that start on a later date. Individually named people are not re-resolved.
- **States:**
  - **Viewed** is set the first time the person downloads or previews the requested version's file. Opening only the document page does not count.
  - **Acknowledged** is the person's own confirmation, with time and the version's SHA-256. When the request requires opening (the default), confirming before opening answers `409`. Simultaneous confirmations count once (row lock).
  - **Overdue** means still open after the deadline. A confirmation after the deadline is accepted and marked late.
  - **Excused** is set by the sender, a clerk or a head of the person's recorded unit, with a reason. It can be undone ("ask again"), and people cannot excuse themselves. Excused people do not count against completion.
  - **Deputies:** acknowledgements are personal, so deputies do not confirm on someone's behalf. This replaces the Phase 1 plan; managers excuse absent people instead.
- **Roles on a request:**
  - **Sender and clerks** add recipients, move the deadline (only later), close and re-issue it.
  - **Heads of affected units** see the part of the board for the units they manage and can remind and excuse those people.
  - **Recipients** may read the document, including confidential ones.
  - **Everyone else** gets `404`.
- **Status board** (`#/acknowledgements/<id>`):
  - **Summary:** completion % and counts.
  - **Units:** a unit tree with completion per unit and its sub-units. It starts at the first unit that has people of its own, so a request to one department does not show the whole organization above it. Select a unit to drill down.
  - **People:** filters (still to confirm, overdue, not opened, opened, acknowledged, excused) and per-person remind, excuse and ask-again.
  - **Exports:** CSV export (with a spreadsheet formula guard) and a printable acknowledgement sheet PDF with the version's SHA-256 and a blank signature column.
- **Reminders:**
  - **Manual:** "remind everyone pending" and "remind one", at most one manual reminder per person per day.
  - **Scheduled:** `app:reminders:run` sends due-soon and overdue reminders for requests with a deadline (task lead time), and escalates to the person's direct superior after the escalation threshold, or to the sender when there is none.
  - **Completion:** the sender is told once when nobody owes a confirmation any more.
- **Re-issue:** from the board, the same targets are asked again for the document's newer version or for a document linked as replacing or amending it. The old request is closed and linked as "previous". Archiving a document closes its open requests.
- **API:**
  - **Per document:** `GET`/`POST /api/dms/documents/{id}/acknowledgements` and `GET .../acknowledgements/audience`.
  - **Personal:** `GET /api/dms/acknowledgements/inbox`, `/counts` and `/history`.
  - **Requests:** `GET /api/dms/acknowledgements?status=open|closed` and `GET /api/dms/acknowledgements/{id}` (`?status=` filters people), `export.csv`, `sheet.pdf`, and `POST remind` (optional `recipient_ids`), `deadline`, `recipients`, `close`, `reissue`.
  - **Per person:** `POST /api/dms/acknowledgements/recipients/{id}/acknowledge`, and `POST`/`DELETE .../excuse`.
- **UI:**
  - **Document page:** an Acknowledgement panel with the person's own request ("Open v1", then "I have read and understood"), the send form with a unit/position/people picker, and the requests with progress.
  - **Documents section:** "To acknowledge" (with counter and "My history") and "Acknowledgement status" tabs.
  - **Elsewhere:** the status board page, dashboard counters for documents to acknowledge and approvals awaiting the user, and an "Acknowledgement" label in the bell.
- **Not yet:** e-mail delivery (blocked as described in P1), a per-person deadline (the deadline is per request), and acknowledging on paper with a scanned signature; the PDF only has a blank signature column.
- **Verification:**
  - **Backend:** 255 tests / 3,625 assertions (8 new: recipient and request rules, completion counts and the unit tree, plus HTTP flows for sending scope, opening before confirming, partial boards, excusing, exports and completion; clerk scope and confidential access; reminders, scheduler escalation, deadlines, added recipients, joiners and closing; re-issue for a replacing document and closing on archive).
  - **Migrations:** `scripts/verify-migrations.sh` passes on PostgreSQL 17 (32 migrations).
  - **End-to-end:** `scripts/verify-dms-e2e.sh` with `APPROVERS` passes against a throwaway API on PostgreSQL 17. It has 32 checks, 13 of them new: subtree scope, the board on PostgreSQL, opening before confirming, two simultaneous confirmations counting once, simultaneous "remind all" giving exactly one reminder, the partial board, CSV and PDF. As with Phase 3, the concurrency checks are smoke tests of the concurrent path, not proof that the locks are needed.
  - **Frontend:** 59 tests (5 new), type-check and build pass. The new screens were not checked in a browser. Not applied to any deployed database.

**Phase 5 — Records lifecycle, audit and hardening** (done except the two open items; see notes below)

- [x] Handle the lifecycle: make a document effective on its effective date, send review-date reminders to the owner, mark it superseded when a replacing document comes into force, and archive it on expiry.
- [x] Add retention per type, legal hold (blocks deletion), and an admin-approved disposal step that leaves a tombstone record. No hard deletes of registered documents.
- [x] Add an audit trail covering view, download, version upload, every route action, acknowledgement, and access change, with filtering per document.
- [x] Add reports: documents by status/type/unit, average approval time per step, overdue approvals per approver, and acknowledgement completion per unit.
- [ ] Before production, run virus scanning on upload, verify that DMS files are included in the P0 off-host backup, and move to object storage if volume requires it. (Done: ClamAV scanning of every DMS upload when `DMS_CLAMAV_DSN` is set, failing closed with `503` when the scanner is unreachable; the backup drill now proves DMS files and tables restore byte for byte. Open, because they are deployment steps: run a clamd service and set the variable in production, and repeat the restore test against the real off-host destination. Object storage is not needed at the current volume, 10 MB per file on local disk; revisit when the `dms/` folder approaches the backup window or disk limits.)
- [ ] Add backend PHPUnit tests (subordination, routing validation, concurrency of numbering and decisions, access denial), frontend unit tests for the inbox, route builder and status dashboard, and an e2e script in `scripts/` (submit → approve → register → acknowledgement campaign → status report). (Done: the backend tests across Phases 1–5, frontend unit tests for the route builder (`lib/approvals.ts`), the acknowledgement status board (`lib/acknowledgements.ts`) and the reports (`lib/records.ts`), and `scripts/verify-dms-e2e.sh`, which now ends with the status report. Open: component-level tests of the inbox screens, which need `@vue/test-utils` and a DOM environment that are not installed; the inboxes have no logic outside the tested libraries.)
- [x] Update [PRD.md](PRD.md) and [NOTES.md](NOTES.md) with the DMS requirements, decisions and remaining gaps.

Phase 5 notes:

- **Lifecycle** (`app:dms:lifecycle`, run hourly by the `scheduler` service before the reminders; safe to repeat):
  - **In force:** a `registered` document becomes `in_force` on its effective date.
  - **Superseded:** a document linked as "replaces" another supersedes it as soon as it is in force, on registration or on its effective date. The replaced document's open acknowledgement requests are closed and its author is notified.
  - **Expired:** the day after its expiry date a registered, in-force or superseded document is archived. Changes are recorded as `automatic (lifecycle)`.
  - **Review dates:** `app:reminders:run` reminds the author before a registered document's review date (project lead time), marks it overdue, and escalates to the head of the owner unit. The author or a clerk records the review ("Record review") and sets the next date. This is the only field that changes on a registered document.
- **Retention and disposal:**
  - **Retention:** the type's retention years, counted from the archive date. Types without one keep documents indefinitely.
  - **Legal hold:** clerks place it with a reason and release it. It blocks deleting a draft and disposing of a document, and placing it rejects an open disposal proposal.
  - **Disposal** is four-eyes. A clerk proposes an archived document past retention, the administrators are notified, and an administrator other than the proposer approves or rejects (a reason is required to reject). Approval destroys the version and attachment files, extracted text, comments, summary, tags and named readers. It keeps the document as a `disposed` tombstone: number, title, type, dates, version and attachment names with SHA-256, the full history, and the disposal record with its manifest. Files of disposed versions answer `410`. Registered documents are still never deleted.
- **Audit trail:**
  - **Views:** opening the document page is recorded once per person and day.
  - **Access changes:** they name who gained and who lost access.
  - **Trail:** `GET /api/dms/documents/{id}/audit` merges the document's events with every approval route action, filtered by action group, person and dates, with `format=csv`. Only the author and clerks can read it; reading it is itself written to the audit log.
- **Reports** (`GET /api/dms/reports?from=&to=`, clerks):
  - **Documents:** counts by type, owner unit and status, as of today.
  - **Approval time:** per step kind and per approver (opened → decided, mean, median, longest) for steps decided in the period.
  - **Overdue approvals:** open approval steps past their due date, per approver.
  - **Acknowledgement completion:** per recorded unit, for requests sent in the period.
- **Virus scanning:** `ClamAvScanner` streams each upload to clamd (`INSTREAM`) before it is stored. Infected files are refused with `400` and audit-logged. With `DMS_CLAMAV_DSN` empty (the default) scanning is off, which is acceptable only for development. Project documents outside the registry are not scanned yet.
- **API:**
  - **Legal hold:** `POST`/`DELETE /api/dms/documents/{id}/legal-hold`.
  - **Per document:** `POST .../review`, `GET .../audit`, and `POST .../disposal` (propose).
  - **Disposals:** `GET /api/dms/disposals` and `/eligible`, and `POST /api/dms/disposals/{id}/approve|reject` (`ROLE_ADMIN`).
  - **Reports:** `GET /api/dms/reports`.
- **UI:**
  - **Document page:** a Records management panel (next review, retention end, legal hold, disposal state and actions) and an Audit trail panel with filters and CSV. Destroyed files are marked on versions and attachments.
  - **Clerk tabs:** "Retention" (documents due for disposal, proposals and decisions) and "Reports".
  - **Notifications:** "Document status" and "Disposal".
- **Not yet:** a per-document retention override, retention triggers other than the archive date (PRD open decision), scanning of project documents, and object storage.
- **Verification:**
  - **Backend:** 271 tests / 3,921 assertions (16 new: lifecycle, review, hold and disposal rules, and the four-eyes record; the ClamAV protocol against a fake daemon and storage refusing infected files; HTTP flows for lifecycle and supersession, review reminders and escalation, legal hold and disposal with the tombstone, the audit trail and the reports).
  - **Migrations:** `scripts/verify-migrations.sh` passes on PostgreSQL 17 (33 migrations).
  - **End-to-end:** `scripts/verify-dms-e2e.sh` passes against a throwaway API on PostgreSQL 17 (43 checks, 11 new: audit, legal hold, reports, and the approval and acknowledgement figures in the status report). The lifecycle and reminder commands, and a full disposal with a second administrator, were also run by hand against the same database, with dates moved by SQL. The extended `scripts/verify-backup-drill.sh` passed against it.
  - **Frontend:** 63 tests (4 new), type-check and build pass. The new screens were not checked in a browser. Not applied to any deployed database.

Out of scope for this phase: qualified e-signatures, external (inter-organization) correspondence and incoming/outgoing mail registration, OCR of scanned paper, a WYSIWYG document editor or co-authoring, and e-mail delivery while `symfony/mime` remains blocked.

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
