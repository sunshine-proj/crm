# Product Requirements Document

## Product

**Working name:** CRM Business Support Platform
**Document purpose:** Product direction and implementation requirements for coding agents
**Status:** Initial product direction; validate business, legal, and accounting requirements before implementing regulated workflows.

## Vision

Build a dependable business system for small service-oriented organizations. Clients and projects are the core of the product: users should be able to understand each client relationship, plan and deliver its projects, and connect delivery work to invoices and financial records. Over time, the product may grow into a broader ERP, but it must first become a coherent CRM and project-management system rather than a collection of disconnected modules.

The product should let a team answer, from one place:

- Who are our clients, and what is happening in each relationship?
- What work are we doing for each client, who owns it, and what happens next?
- Is the work on schedule and within its approved budget?
- What has been invoiced, paid, spent, and earned?

## Current Baseline

The repository currently contains a Vue 3 and TypeScript single-page frontend and a Symfony 8 / PHP 8.4 JSON API using Doctrine ORM and PostgreSQL. Authentication uses JWTs. See the root and app-specific `AGENTS.md` files for engineering conventions and commands.

Implemented modules include:

- **Clients:** create, list, edit, and delete records containing a title and notes, with expandable structured contacts (name, role, email, and phone). Client and contact writes are restricted to administrators.
- **Projects:** create, list, edit, and delete records containing a title and description, linked to a client for new records, with lifecycle status, planned dates, optional budget, and optional billable hourly rate/currency.
- **Projects:** can have an optional responsible manager selected from authenticated workspace users.
- **Tasks:** create, list, edit, and delete records containing a title, description, start/end times, a project link, status, priority, optional due date, optional assignee, and same-project predecessor dependencies. Lists support search/status filtering and due/overdue indicators; notifications are not sent.
- **Inventory and procurement:** authenticated users can configure warehouses and suppliers, create purchase orders, post partial receipts, inspect movement-derived stock, adjust counts, dispatch sales shipments from stock, and record bounded inventory returns. Quantities are tracked from movements; when inventory accounts are mapped, stock is also valued at weighted-average cost (with landed costs) and receipts, dispatches, returns and adjustments post to the ledger. Purchase orders can require approval and vendor bills can be matched to goods received.
- **Project operations:** users record project/task time, project managers/admins approve or reject it, and per-user cost rates/project billing rates are snapshotted for new time entries. Expenses have categories and manager/admin decisions; administrators can record partial reimbursements. Single-currency profitability summaries and draft invoice creation from approved billable time or completed priced milestones are available.
- **Invoices and customer payments:** authenticated users can manage project-linked draft invoices, generate invoices from shipped sales orders, approved time, and completed priced milestones; issue invoices; record partial/full customer receipts; view outstanding balances; and export invoice PDFs. Amounts use integer minor units. This is an operational workflow, not legally compliant invoice issuance or payment processing.
- **Inventory and purchasing:** authenticated users can configure warehouses and suppliers, create product-based purchase orders, receive partial/full quantities into a warehouse, derive on-hand quantities from signed stock movements, post adjustments, explicitly dispatch sales shipments against warehouse stock, and record bounded customer/supplier returns. The ledger tracks quantities only and is not valued inventory.
- **Finance foundation:** authenticated users can configure accounts, fiscal periods, and administrator-managed posting mappings; post manual/opening-balance journals; reverse entries; and close/reopen periods with administrator reasons. Invoice issues/receipts, vendor bills/payments, approved expenses, and expense reimbursements generate mapped accrual postings. Currency-scoped trial balance, operational P&L/balance-sheet/cash-movement summaries, AR/AP, and source-record posting checks are available. These are not statutory reports or external-bank reconciliations.
- **Project documents:** authenticated upload/download endpoints enforce a 10 MB file-size limit and MIME allowlist. Files are local, without virus scanning or external backup.
- **News:** news-item records with user ownership behavior in the API.
- **Dashboard:** displays live client, active-project, and open-task counts from an authenticated API summary endpoint.
- **Authentication:** sign-in through `POST /api/login` and bearer-token API requests.
- **Organization Settings:** administrators can set the operating country, default currency, and a ten-year reference retention period for the single organization in an installation. Changes are audited and can be restored. Country-specific tax/accounting profiles remain unverified and no retention deletion is automated.

New projects must be associated with a client, and new tasks must be associated with a project. Existing legacy rows may remain unlinked and are displayed as such. Client contacts are separate records managed through a client-scoped API. Client/project search, inline context, milestones, task assignments/dependencies, due indicators, approval workflows, basic cost-to-billing flows, and a project-management overview with a project/task timetable and status/overdue statistics are implemented. Clients carry structured legal and account details (tax identifiers visible to finance users and administrators only), dedicated client/project detail views exist, tasks can be managed on a board, deals move through a sales pipeline, and users receive in-app reminders and escalations configured per user (no e-mail delivery). Individual-customer and parent/child account models remain open. Finance postings and reports are operational foundations only: mappings must be configured, cross-currency accounting and external bank reconciliation are absent, and no jurisdiction-specific compliance is claimed. The latest migrations have been applied to a disposable PostgreSQL database, and an end-to-end script verifies ledger postings, concurrent receipts, and the posting-presence check, but they are not yet verified on a deployed database. Application roles are `ROLE_USER`, `ROLE_FINANCE`, and `ROLE_ADMIN`; changes to core business records are versioned and administrator-recoverable.

## Product Principles

1. **Client and project first.** New capabilities should strengthen the client relationship or improve project delivery before adding unrelated ERP modules.
2. **Connected workflows.** A client should lead naturally to its contacts, projects, activities, and financial history; a project should connect planning, work, costs, and billing.
3. **One reliable record.** Persist business data once and use it consistently across lists, detail views, dashboards, reports, and exports.
4. **Clear ownership and status.** Important work and financial records must have explicit owners, statuses, dates, and audit history where appropriate.
5. **Financial correctness over convenience.** Money, tax, invoice numbering, rounding, and payment state require explicit rules, currency-aware calculations, immutable issued records, and tests before release.
6. **Incremental ERP evolution.** Keep the domain modular and avoid building accounting, inventory, payroll, or other broad ERP scope ahead of validated needs.
7. **Secure by default.** Enforce permissions in the API, validate all inputs on the server, protect sensitive financial data, and record security-relevant changes.

## Users

- **Business owner / administrator:** manages clients, team access, commercial decisions, and overall business health.
- **Client manager:** maintains client context, contacts, communications, and follow-up work.
- **Project manager:** scopes and plans projects, assigns work, tracks risks, dates, and budgets.
- **Team member:** sees assigned work, updates progress, and records time or delivery notes as enabled.
- **Finance user / bookkeeper:** prepares invoices, records payments and expenses, and reviews financial activity.

These are product personas, not a statement of current role support. Design authorization by capability and record ownership; do not assume current application roles already implement this model.

## Product Goals

- Give each client a useful, searchable account record with contacts, interaction history, and linked projects.
- Make projects the operational center for scope, planning, tasks, people, schedule, budget, and delivery status.
- Connect completed or billable work to accurate invoices and payment status.
- Provide a traceable path from invoices and expenses to project and client profitability.
- Make key business status visible through accurate dashboards and reports derived from persisted data.
- Keep core client and project workflows easy to use for small teams without requiring ERP expertise.

## Non-Goals For The Initial Product

- Full general-ledger or statutory accounting, tax filing, payroll, inventory, procurement, or manufacturing.
- Replacing a dedicated accounting package before jurisdiction-specific compliance needs are understood.
- Building a complex sales-automation suite before client and project records are reliable.
- Adding dashboard metrics that are not backed by defined, persisted data and calculations.
- Allowing client, project, task, invoice, or financial changes solely through frontend authorization.

## Product Scope And Phases

Phases describe intended order, not a commitment to dates. Each phase should be broken into small, independently verifiable deliverables.

### Foundation: Reliable Core

- Establish automated backend and frontend tests, a test database, and coverage reporting.
- Define shared error, validation, permission, audit, date/time, currency, and API response conventions.
- Provide organization Settings where an administrator selects the country of operation and default currency; use that country to select the applicable tax jurisdiction and accounting configuration only when validated country-specific rules are available.
- Replace placeholder dashboard values with persisted calculations or remove them until implemented.
- Define supported user roles and record-level access rules before expanding sensitive features.

### Phase 1: Client And Project Operations

The initial relational planning slice is implemented: clients can manage structured contacts and see linked projects in the client list; new projects link to clients and have lifecycle status/planned dates; new tasks link to projects and have status, priority, and optional due date; the dashboard uses live counts. Legacy records remain readable. The following Phase 1 requirements are still outstanding:

- Expand client records with structured organization/account details while retaining a useful client overview.
- Add dedicated client and project detail views with activity history and related records.
- Add project owner and budget/currency where applicable.
- Add task assignee and complete task scheduling/ownership workflows.
- Provide client and project detail pages with clear summaries, recent activity, related work, and next actions.
- Support search, filtering, sorting, and pagination as record volumes grow.

### Phase 2: Planning And Delivery Management

- **Implemented MVP:** provide a project-management overview with a timetable of active projects by default, an option to include every lifecycle status, separate project/task schedule bars, and status-based colors with a visible legend.
- **Implemented MVP:** show date-range-filtered project and task status statistics and separate overdue project and task tables.
- Continue improving project scope, milestones, dependencies, schedule interaction, and historical change visibility to fit validated team workflows.
- Support task estimates and actual time entries with author, date, duration, and project/task attribution.
- Add defined risk, workload, and budget-versus-actual calculations; overdue project/task counts and lists are available in the management overview.
- Preserve a change history for meaningful project and task updates.

### Phase 3: Invoicing And Payments

- **Implemented MVP:** create/edit/delete project-linked draft invoices with project-task line items and additional costs; snapshot client/project/manager labels; select EUR, GBP, or USD; assign ID-based invoice identifiers; filter by client, project, project manager, invoice date range, currency, and total range; export invoice PDFs from the API.
- **Implemented MVP:** calculate invoice totals on the server using integer minor units; explicitly issue draft invoices, lock issued records from editing/deletion, record partial/full customer receipts, reject overpayments, and derive paid/balance state. Invoice numbering and PDF output are not claims of legal compliance.
- Create draft invoices from a client and optionally from approved project work, time, or fixed-fee milestones.
- Support invoice line items, currency, issue/due dates, tax configuration, totals, notes, and lifecycle status.
- Allow invoice review before issue; issued invoices must not be silently rewritten. Corrections should use explicit void, credit, or replacement flows appropriate to validated business rules.
- Record full or partial payments, payment dates, references, and allocation to invoices; calculate outstanding balances consistently. Current payment capture is manual and operational, not payment-provider processing or bank reconciliation.
- Provide printable/exportable invoice output and a clear client/project invoice history.
- Confirm local invoice numbering, tax, retention, and document requirements before releasing invoices for real-world use.
- Add payment records, partial-payment allocation, derived outstanding balances, immutable issuance, audit history, taxes/discounts, and jurisdiction-validated invoice numbering before invoices are used operationally.
- Consider PDF branding and client billing address/contact snapshots, configurable payment instructions, and email delivery after legal and business requirements are confirmed.

### Stage 2: Sales Workflow

The sales workflow is operational and links to a separate quantity-only inventory dispatch step. It does not claim tax compliance, accounting, or payment processing.

- **Implemented MVP:** maintain products with unique SKUs, active state, fixed EUR/GBP/USD currency, and server-validated unit prices stored as integer minor units.
- **Implemented MVP:** create client quotes from active catalog products; snapshot line descriptions, SKU, quantity, unit price, currency, and total; transition draft quotes to sent/accepted/rejected; reject acceptance after expiry.
- **Implemented MVP:** convert an accepted quote to a linked sales order once, preserving the original quote and pricing snapshot.
- **Implemented MVP:** record partial or complete shipments by product quantity, date, and reference; prevent shipping beyond ordered quantity and expose shipment history.
- **Implemented MVP:** create one linked draft invoice from a fully shipped order. Use the existing invoice PDF generation and amount calculation boundary.
- **Implemented MVP:** explicitly issue a draft invoice; lock issued invoices from editing/deletion; accept partial/full payment records; reject overpayment; derive invoice paid total, balance due, and paid state from payment records.
- Keep payment entry manual and represent it only as an operational record. Do not imply card processing, bank reconciliation, or external payment confirmation.
- A sales shipment remains distinct from stock until an explicit warehouse dispatch. Dispatch records stock movements and cannot permit negative stock.
- Future requirements: tax rules, discounts, price lists, client-specific pricing, quote delivery/signature, order amendments/cancellations, financial sales returns/credit notes/refunds, payment-provider/bank integration, audit-grade issued document immutability, and jurisdiction-approved numbering and tax compliance. Inventory dispatch is available in the Stage 3 workflow; it does not imply valuation or automatic accounting.
- Before production financial use, validate supported jurisdictions, invoice legal requirements, tax/rounding rules, document retention, permissions, and accounting integration. Tenant isolation is also a release blocker for a multi-organization service.

### Phase 4: Inventory And Procurement

- **Implemented MVP:** configure warehouses and suppliers with unique codes and active state; retain supplier contact details.
- **Implemented MVP:** create purchase orders from active products, snapshot supplier/product identifiers, quantities, currency, unit costs, and totals, and track open/partially-received/received state.
- **Implemented MVP:** post partial/full goods receipts into a selected warehouse; cumulative receipt quantities cannot exceed order quantities; receipt and inbound stock movements persist atomically.
- **Implemented MVP:** calculate warehouse/product on-hand from append-only signed stock movements; expose recent movement history; support reasoned positive/negative adjustments while preventing negative stock.
- **Implemented MVP:** explicitly dispatch existing sales shipments from a selected warehouse once; verify stock and create outbound movements.
- **Implemented MVP:** record customer returns only against dispatched shipments and supplier returns only against received purchase-order quantities; cap returns to original quantities and record corresponding stock movements.
- Keep sales shipment recording and warehouse dispatch as separate steps: a sales shipment alone does not reduce inventory.
- **Not implemented:** inter-warehouse transfers, stock reservation, bin locations, lots/serials/expiry dates, stock valuation, cost layers, landed costs, purchasing approvals, PO editing/cancellation, three-way matching, carrier integrations, or automated replenishment.
- **Implemented foundation:** weighted-average valuation per product and warehouse, landed costs entered on goods receipts, opening valuation of legacy stock, and mapped ledger postings for receipts, dispatches, returns and adjustments; administrator-set purchase-order approval threshold; three-way matching of vendor bills to purchase orders with exceptions and price variance. Operational figures only.
- **Not implemented here:** credit notes, tax, FX, late landed costs, period-end write-downs, and accountant validation of the costing method.

### Phase 5: Project Operations

- **Implemented MVP:** optional project budget stored in integer minor units with an explicit EUR/GBP/USD currency.
- **Implemented MVP:** time entries with project, optional same-project task, authenticated author, date, duration in minutes, billable flag, description, approval state, and immutable cost/billing rate snapshots.
- **Implemented MVP:** administrator-configured per-user cost rates and project billing rates for new time entries; rates use integer minor-unit arithmetic and an explicit currency.
- **Implemented MVP:** project expense submission with explicit currency, category, and decimal-safe amount; each new expense enters `pending_approval`.
- **Implemented MVP:** manager/admin approval or rejection with append-only actor/date/comment history. A non-admin submitter cannot approve their own expense.
- **Implemented MVP:** administrators record partial expense reimbursements, which post against mapped expense-payable and cash accounts.
- **Implemented MVP:** single-currency operational budget/cost/profitability summaries and draft invoice creation from approved billable time or completed priced milestones.
- **Implemented MVP:** project milestones, same-project task dependencies with cycle prevention, task assignment, and due/overdue indicators. No outbound reminders or schedule/Gantt view exists.
- **Implemented MVP:** project document upload/list/download/delete, generated storage keys, server MIME validation, a 10 MB limit, and authenticated access. Stored files are in local Symfony `var/` storage and are not virus-scanned or externally backed up.
- **Not implemented:** payroll, expense tax handling, configurable multi-step approvals, reminders/escalations, period-filtered project financial analysis, document versioning/virus scanning, or object-storage backup.
- Distinguish project cost estimates from payroll; do not treat local document storage as a managed records-retention system.

### Phase 6: Finance And Bookkeeping

- **Implemented foundation:** maintain chart-of-account codes/types and active state; create non-overlapping fiscal periods; post manual and opening-balance journal entries only when the date is in an open period, each line is one-sided and uses integer minor units, and total debits equal total credits.
- **Implemented foundation:** posted manual journal entries cannot be updated or deleted through the API. Reports return a currency-scoped trial balance with date range and basis.
- **Implemented foundation:** show open AR based on issued invoices and customer receipts; capture vendor bills and partial/full AP payments; prevent duplicate vendor references and overpayment; report balances in one selected currency.
- **Implemented foundation:** configure account mappings and automatically post invoice issues/receipts, vendor bills/payments, approved expenses, and expense reimbursements. Source-record posting checks identify records without a journal reference.
- **Implemented foundation:** reasoned administrator close/reopen and journal reversals, plus single-currency operational P&L, balance-sheet, and cash-movement summaries.
- **Implemented foundation:** configure tax-code percentages stored as basis points and a finite EUR/GBP/USD currency list. Tax is not applied to invoices/bills; currencies are not converted.
- **Implemented foundation:** sub-ledger-to-ledger reconciliation (AR, AP, inventory, goods received not invoiced, output tax); bank statement import (camt.053/CSV) with matching to recorded receipts and payments; credit notes as the correction path for issued invoices; and, behind a recorded adviser sign-off per country and scope, server-side tax on invoices and gapless invoice/credit-note numbering.
- **Not implemented:** FX rates/revaluation (decided against for now); payment-provider or open-banking integration and payment initiation; input VAT, VAT returns and filing; e-invoicing formats; refunds of money already received; and audit-grade retention controls. No document is represented as legally compliant.
- Automatic postings require active account mappings and an open fiscal period. Source-record posting checks do not compare amounts or prove full accounting reconciliation; statements are operational summaries rather than statutory financial statements.
- Validate accounting, tax, access, retention, audit, and document rules for the target jurisdictions before treating this operational foundation as a production ledger or statutory finance product.
- Add integrations or broader ERP capabilities only after user research, jurisdiction review, and a separate scoped PRD.

## Functional Requirements

### Organization Settings And Localization

- **ORG-1:** An administrator can select and update the organization's country of operation in Settings. Initial supported choices are Latvia (LV), Lithuania (LT), Estonia (EE), Germany (DE), and the United Kingdom (GB). The selected country determines the applicable tax jurisdiction and country-specific accounting properties, defaults, and required financial configuration only when those rules have been validated and implemented.
- **ORG-2:** Country selection must use an explicit supported-country and jurisdiction configuration; it must not imply that tax or accounting rules are complete for a country until those rules have been validated and implemented.
- **ORG-3:** Changing the country of operation must not silently rewrite or recalculate historical invoices, payments, bills, journal entries, or reports. Any migration or treatment of existing records requires explicit, auditable rules.
- **ORG-4:** The initial deployment model is one organization per installation. Do not expose an installation to multiple organizations until organization ownership and per-organization authorization are implemented and tested.
- **ORG-5:** Settings record a default currency for new records and a ten-year reference retention period. Retention does not automatically delete data; the retention trigger and jurisdiction-specific legal requirements must be validated before production use.
- **ORG-6:** Organization-setting changes preserve an append-only actor/time/value history. Administrators may restore an earlier settings snapshot; restoration itself creates a new history entry.
- **ORG-7:** The deployment backup policy is encrypted off-host daily backups of the database and uploaded business documents, 35-day retention, quarterly restore tests, and 24-hour RPO/RTO. This policy is not considered operational until the deployment implements and verifies it.

### Clients

- **CL-1:** An authorized user can create, view, update, and archive or delete a client according to a documented retention policy.
- **CL-2:** A client has a stable identity and structured organization details; notes must not be the only place for searchable business data.
- **CL-3:** A client can have multiple contacts with roles and contact details, subject to privacy and access controls.
- **CL-4:** A client detail view shows related projects, relevant activity, and invoices when those modules are available.
- **CL-5:** Client lists support practical search and filtering; empty, loading, error, and no-results states are clear.

### Projects And Work

- **PR-1:** Every project is associated with a client, unless an explicitly supported internal-project type is selected.
- **PR-2:** A project records its purpose/scope, lifecycle status, accountable owner, and relevant planned dates.
- **PR-3:** Users can plan work using project milestones and tasks; tasks belong to a project and have clear status and ownership.
- **PR-4:** Project status and schedule summaries are calculated from defined persisted values, never hard-coded.
- **PR-5:** A project view provides access to its client, tasks, schedule, budget, time, invoices, and expenses as those capabilities are released.
- **PR-6:** Changes to project scope, dates, status, and commercial values preserve an auditable history appropriate to their impact.

### Project Management Overview

- **PM-1:** The application provides an overall project-management section for reviewing project schedules and delivery status.
- **PM-2:** The timetable shows active projects by default. A clearly labeled “show all projects” option includes projects in every lifecycle state: planned, active, stopped/on hold, finished/completed, and canceled.
- **PM-3:** A project schedule bar is calculated from its planned start and end dates. Each associated task/subtask is shown on a separate row with its own bar calculated from its scheduled start and end dates.
- **PM-4:** Project and task status colors distinguish planned, in-progress, overdue, finished, and canceled work. The existing project `on_hold` value is presented as stopped; completed is presented as finished. Blocked tasks remain visually distinct. A visible text legend and status labels ensure status is not communicated by color alone.
- **PM-5:** Overdue project status is derived when the planned end date is before the current date and the project is not finished or canceled. An unfinished task is overdue when its due date, or scheduled end date when no due date exists, is before the current date. Finished/canceled work is never counted as overdue.
- **PM-6:** The overview provides date-range filters and displays project and task counts grouped by status for the selected project scope and schedule range. Date-range overlap is inclusive of both endpoints; unscheduled records are included when no date filter is set and excluded when a date filter is applied.
- **PM-7:** Overdue projects and overdue tasks have separate statistics tables identifying the work, its client/project, responsible manager/assignee, due/end date, and overdue days. Tables also show a clear empty state.
- **PM-8:** Records without both schedule endpoints remain visible in the overview with an explicit unscheduled indication, but do not receive a misleading timetable bar.
- **PM-9:** Tasks may be canceled; cancellation is terminal for overdue/open-task calculations, while existing task statuses remain supported.

### Project Operations

- **OPS-1:** A project may have an optional approved budget with an explicit currency; budget amounts use integer minor units on the server.
- **OPS-2:** Time entries identify project, optional task, authenticated author, work date, integer duration, billable state, and description. A linked task must belong to the same project.
- **OPS-3:** Expenses identify project, submitter, date, currency, description, and positive decimal-safe amount; new expenses enter a pending-approval state.
- **OPS-4:** Only a project manager or administrator may approve/reject an expense. A non-admin submitter cannot approve their own expense; decisions retain actor, timestamp, and comment history and cannot be repeated after a terminal decision.
- **OPS-5:** Project documents are accessible only through authenticated endpoints, use server-generated storage keys, enforce an allowlisted MIME type and file-size limit, and do not accept client paths as storage locations.
- **OPS-6:** Project operations pages expose visible project selection, loading/empty/error states, and clarify that logged time does not create payroll or ledger postings. Expense approval is separate from reimbursement; recorded reimbursements create mapped operational ledger postings.
- **OPS-7:** Local document storage requires backup, retention, access-control, and malware-scanning decisions before production use.
- **OPS-8:** Time entries have an explicit approval decision and retain the deciding actor, timestamp, and comment. Approved billable time may be selected to create a draft invoice; approval alone does not issue or post an invoice.
- **OPS-9:** New time entries snapshot configured user cost and project billing rates in integer minor units and an explicit currency; later rate changes do not rewrite historical snapshots.
- **OPS-10:** Project expenses support configured categories and administrators can record partial reimbursements without reimbursing more than the approved amount.
- **OPS-11:** Projects support priced milestones and draft invoice creation from completed milestones; tasks support same-project dependencies, cycle prevention, assignees, and due/overdue indicators.
- **OPS-12:** Project profitability summaries disclose their currency and are operational estimates, not payroll or statutory project accounts.

### Financial Operations

- **FN-1:** Financial records identify the client and, when relevant, the project to which they belong.
- **FN-2:** Invoice and expense totals are calculated on the server using explicit currency precision and tax/rounding rules.
- **FN-3:** Invoice status and outstanding balance are derived consistently from invoice and payment records; payment changes are traceable.
- **FN-4:** Users can distinguish estimates, drafts, issued documents, payments, expenses, and accounting entries; a draft is not represented as received revenue.
- **FN-5:** Financial reports disclose their date range, currency, and calculation basis.
- **FN-6:** Bookkeeping scope and compliance claims require confirmation for the target jurisdictions; do not represent operational summaries as statutory accounts.
- **FN-7:** Draft invoice PDF exports must be marked as drafts; ID-based identifiers are not a substitute for jurisdiction-approved invoice numbering.
- **FN-8:** Invoice list amount filters must be currency-scoped; totals in different currencies must never be compared as if they shared a currency.
- **FN-9:** A manual posted journal entry has at least two lines; each line references an active ledger account and contains either a positive debit or a positive credit, never both; debit and credit totals balance exactly in integer minor units.
- **FN-10:** Journal entry date must be within exactly one open fiscal period; date ranges cannot overlap. A closed period cannot accept new postings.
- **FN-11:** Posted entries are immutable through ordinary update/delete APIs; corrections require a reversal/replacement workflow.
- **FN-12:** Trial balance and AR/AP reports must be limited to one explicit currency unless a validated FX conversion layer is implemented; report output must disclose date range and calculation basis.
- **FN-13:** Tax is applied to documents only while a recorded adviser sign-off exists for the organisation's country (tax scope, including the rounding method); the sign-off is a stored claim, not verification, and the product never asserts tax compliance. Rounding behaviour is covered by tests.
- **FN-14:** Operational AR/AP subledgers must not be presented as reconciled GL balances without comparing source balances and journal amounts; posting-reference checks alone are not reconciliation.
- **FN-15:** Automatic postings use configured active account mappings, integer minor-unit amounts, an open fiscal period, and idempotent source references; a failed posting must not leave the source transaction and journal in a partially committed state.
- **FN-16:** Corrections to posted entries use reversal entries. Opening balances and period close/reopen actions retain administrator identity, timestamp, and required reason.
- **FN-17:** Operational P&L, balance-sheet, and cash-movement summaries disclose currency and date range and must not be described as statutory statements.
- **INV-1:** On-hand is derived from immutable movements per product and warehouse; corrections use offsetting movements, not edits or deletes.
- **INV-2:** Goods receipts reference a purchase order and warehouse; cumulative received quantities cannot exceed ordered quantities.
- **INV-3:** Warehouse dispatch references a sales shipment, is idempotent, and requires sufficient on-hand stock.
- **INV-4:** Customer/supplier returns reference the originating shipment/order and cannot exceed original shipped/received quantity less previous returns.
- **INV-5:** Stock value is reported only from valued movements (weighted average) and only when the inventory accounts are mapped; unvalued legacy quantity is shown separately and cannot be issued until an opening valuation exists.

### Access, Privacy, And Audit

- **SEC-1:** The API enforces authentication, authorization, validation, and ownership rules for every read and write operation.
- **SEC-2:** Role and permission changes are explicit and tested; frontend visibility is not a security boundary.
- **SEC-3:** Sensitive client and financial actions are logged without recording passwords, tokens, or unnecessary personal data.
- **SEC-4:** Data export, archival, retention, deletion, and backup expectations are defined before handling production financial records.

## Quality Requirements

- **Correctness:** All totals, date ranges, statuses, and dashboard figures have documented definitions and automated tests.
- **Reliability:** Failed requests provide actionable errors without losing unsaved user input; destructive actions are deliberate and recoverable where business rules require it.
- **Security:** Validate on the server, apply least privilege, protect credentials and secrets, and test denied as well as allowed access.
- **Usability:** Core client and project workflows are usable on desktop and mobile, accessible by keyboard, and communicate loading, empty, error, and success states.
- **Performance:** Lists and detail views remain responsive as business data grows; use server-side pagination/filtering when client-side loading is no longer appropriate.
- **Maintainability:** Keep domain responsibilities clear, version schema changes, use DTOs at application/API boundaries, and avoid coupling financial rules to UI code.
- **Testability:** Production behavior must be covered by automated unit and integration/API tests with coverage reports. Financial calculations, permissions, and lifecycle transitions require particularly strong tests.

## Success Measures

Instrument and establish baselines before setting numeric targets. Candidate measures include:

- Percentage of active projects linked to a client and with an owner, status, and next milestone.
- Time required to find a client's active work and latest relevant activity.
- Percentage of tasks with an owner and due date; overdue-task rate.
- On-time project milestone rate and variance between planned and actual effort/budget.
- Time from billable milestone or approved time entry to invoice issue.
- Invoice aging, overdue balance, and payment recording latency.
- Project profitability visibility and reconciliation completeness once cost tracking exists.
- Defect rate for financial calculations and authorization regressions.

Do not present a candidate measure as a live KPI until its source data and calculation are implemented and validated.

## Domain And Technical Guardrails For Coding Agents

- Treat this PRD as product direction. Existing code and migration history define current behavior; do not claim roadmap items exist until implemented.
- Keep client, contact, project, task, time-entry, invoice, payment, expense, and accounting concepts distinct. Introduce relationships and lifecycle rules through deliberate domain changes and Doctrine migrations.
- Follow the OOP, Doctrine-only persistence, DTO-boundary, authorization, and testing requirements in `src/src/AGENTS.md`; follow Vue conventions in `frontend/AGENTS.md`.
- Keep frontend and backend API contracts synchronized. Financial values require decimal-safe representations and explicit currencies; do not use binary floating-point arithmetic for money.
- Add automated tests with each feature and regression fix. Do not ship financial workflows without tests for rounding, taxes, partial payments, status transitions, permissions, and failure cases.
- Before adding a major module or external integration, confirm the user problem, data ownership, permissions, failure/retry behavior, migration impact, and any compliance requirements.

## Open Product Decisions

- Which legal entity types, tax regimes, accounting standards, and invoice formats apply in the initial countries (LV, LT, EE, DE, and GB), and who validates the rules?
- What event starts the ten-year retention period, and are different periods required for client data, financial records, audit logs, and uploaded documents?
- Which country-specific accounting properties and tax rules must be configured for each initial country?
- Is a client a company/account only, or must the product support individual customers and parent-child organizations?
- Which project pricing models are required first: fixed fee, time and materials, recurring, or a combination?
- Should users track billable time in the product, import it, or integrate with an external time-tracking tool?
- What does bookkeeping mean for the first target users: expense tracking and cash summaries, accounting-system integration, or a general ledger?
- Which roles need access to client data, project budgets, costs, invoices, and payment details?
- What audit, export, and deletion controls are required in addition to the initial ten-year reference period and backup policy?
- Which currencies beyond EUR, GBP, and USD, tax rules, billing addresses, payment instructions, and document templates are required for invoice drafts?