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

- **Clients:** create, list, edit, and delete records containing a title and notes. Client writes are restricted to administrators.
- **Projects:** create, list, edit, and delete records containing a title and description.
- **Tasks:** create, list, edit, and delete records containing a title, description, start time, and end time.
- **News:** news-item records with user ownership behavior in the API.
- **Dashboard:** a basic welcome view; its displayed open-task count is currently a placeholder, not a live metric.
- **Authentication:** sign-in through `POST /api/login` and bearer-token API requests.

Clients, projects, and tasks are currently separate records; projects do not belong to clients, and tasks do not belong to projects. The current client record is not yet a full contact/account profile. There is no implemented project planning, invoicing, payment tracking, expense management, bookkeeping, or financial reporting. Treat these as roadmap requirements, not existing features.

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
- Replace placeholder dashboard values with persisted calculations or remove them until implemented.
- Define supported user roles and record-level access rules before expanding sensitive features.

### Phase 1: Client And Project Operations

- Expand client records with organization/account details and one or more contacts, while retaining a useful client overview.
- Associate each project with a client and show projects from both client and project views.
- Add project lifecycle status, owner, planned start/end dates, and budget/currency where applicable.
- Associate tasks with projects; add task status, assignee, priority, and due dates. Preserve existing task scheduling where it remains useful.
- Provide client and project detail pages with clear summaries, recent activity, related work, and next actions.
- Support search, filtering, sorting, and pagination as record volumes grow.

### Phase 2: Planning And Delivery Management

- Support project scope, milestones, dependencies, and a schedule view appropriate to the team's workflow.
- Support task estimates and actual time entries with author, date, duration, and project/task attribution.
- Surface risks, overdue work, workload, and budget-versus-actual status using defined calculations.
- Preserve a change history for meaningful project and task updates.

### Phase 3: Invoicing And Payments

- Create draft invoices from a client and optionally from approved project work, time, or fixed-fee milestones.
- Support invoice line items, currency, issue/due dates, tax configuration, totals, notes, and lifecycle status.
- Allow invoice review before issue; issued invoices must not be silently rewritten. Corrections should use explicit void, credit, or replacement flows appropriate to validated business rules.
- Record full or partial payments, payment dates, references, and allocation to invoices; calculate outstanding balances consistently.
- Provide printable/exportable invoice output and a clear client/project invoice history.
- Confirm local invoice numbering, tax, retention, and document requirements before releasing invoices for real-world use.

### Phase 4: Bookkeeping And Broader ERP Direction

- Track business expenses with date, payee, category, amount, currency, tax details where applicable, attachments/references, and optional client/project allocation.
- Reconcile invoice receipts and expenses into useful cash-flow and project profitability views.
- Decide whether bookkeeping means operational cash tracking, integration with an external accounting system, or a compliant double-entry ledger before selecting a data model.
- Add integrations or further ERP capabilities (for example purchasing, inventory, or payroll) only after user research, jurisdiction review, and a separate scoped PRD.

## Functional Requirements

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

### Financial Operations

- **FN-1:** Financial records identify the client and, when relevant, the project to which they belong.
- **FN-2:** Invoice and expense totals are calculated on the server using explicit currency precision and tax/rounding rules.
- **FN-3:** Invoice status and outstanding balance are derived consistently from invoice and payment records; payment changes are traceable.
- **FN-4:** Users can distinguish estimates, drafts, issued documents, payments, expenses, and accounting entries; a draft is not represented as received revenue.
- **FN-5:** Financial reports disclose their date range, currency, and calculation basis.
- **FN-6:** Bookkeeping scope and compliance claims require confirmation for the target jurisdictions; do not represent operational summaries as statutory accounts.

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

- Which organization types, countries, tax regimes, currencies, and invoice formats are first-class targets?
- Is a client a company/account only, or must the product support individual customers and parent-child organizations?
- Which project pricing models are required first: fixed fee, time and materials, recurring, or a combination?
- Should users track billable time in the product, import it, or integrate with an external time-tracking tool?
- What does bookkeeping mean for the first target users: expense tracking and cash summaries, accounting-system integration, or a general ledger?
- Which roles need access to client data, project budgets, costs, invoices, and payment details?
- What audit, retention, export, backup, and deletion policies apply to customer and financial data?