# Roadmap

Task checklists for planned phases. Product direction and earlier phases are in [PRD.md](PRD.md).

## Phase 8: Time Tracking And Workload Reporting

Goal: management can see how much time each employee spent on each task and project. Builds on the Phase 5 time entries (`TimeEntry`), which before this phase could only be listed flat per project and summed in the browser.

Status: implemented on 2026-11-15. Verified with PHPUnit (SQLite suite; the time-tracking, operations, CRM and invoice flow tests also against PostgreSQL 17), `scripts/verify-migrations.sh`, Vitest and `npm run build`.

### Access and privacy

- [x] Decide and document who may see other employees' time: administrators and finance users (`ROLE_FINANCE`, inherited by `ROLE_ADMIN`) see everyone; the project manager (`Project.manager`) sees everyone's time on their own projects; every employee sees their own entries. The rule lives in one place, `TimeVisibility` / `TimeScope`. Finance keeps full access because invoicing and profitability need it.
- [x] Restrict `GET /api/operations/time-entries` to that rule. Entries outside the caller's scope are left out (not refused), so project pages still show one's own time; `GET /time-entries/{id}/history` answers `404` for entries the caller cannot see.
- [x] Keep cost amounts visible only to `ROLE_FINANCE`, in entries, the report and the CSV export.
- [x] Add backend tests for each role: admin, finance, project manager, unrelated user, entry author (`TimeTrackingFlowTest`).

### Time report API

- [x] Add `GET /api/operations/time-report` with filters: `from`, `to`, `project_id`, `task_id`, `user_id`, `status`, `billable`.
- [x] Support grouping with `group_by` (one to three of `employee`, `project`, `task`, in order; default `employee,project`), for example `project,task,employee`.
- [x] Aggregate in the database (Doctrine `SUM`/`GROUP BY` split by approval status and billable flag), not by loading all entries.
- [x] Return minutes split into total, approved, pending, rejected and billable; entries without a task form a "No task" group (`task_id: null`), and task groups carry the task estimate.
- [x] Add the same filters, including a date range, to the existing time-entry list; `user_id` or `mine=1` lists time across projects.
- [x] Add a CSV export, `GET /api/operations/time-report/export`, with the same filters and access rules. Cells that a spreadsheet would read as a formula are prefixed with a quote, and each export is written to the audit log.
- [x] Backend tests for aggregation, filters, grouping, ordering, empty ranges, invalid input and authorization.

### Time report page (frontend)

- [x] Add a "Time Report" section with period presets (this week, last week, this month, last month, custom) and project, employee, approval and billable filters.
- [x] Show an employee × project table with row and column totals.
- [x] Drill down from a cell to the task breakdown, and from a task to the individual entries.
- [x] Add a CSV export button that downloads the backend export.
- [x] Limit scope for users without management access: the API returns only what the viewer may see, and the page states the scope ("only your own time", "projects you manage").
- [x] Unit tests for formatting, duration parsing, date presets, query building and the matrix helper (`src/lib/timeTracking.test.ts`).

### Task estimates and actual time

- [x] Add optional `estimated_minutes` to `Task` (migration `Version20261115100000` with a range check), DTO validation and the API field; an explicit `null` clears it.
- [x] Add the estimate input to the task editor (accepts `4h`, `2:30`, `1.5`, `90m`).
- [x] Return `logged_minutes` (pending and approved), `approved_minutes` and `over_estimate` per task. These are task totals visible to everyone; per-employee figures stay behind the visibility rule.
- [x] Show logged vs. estimated time on the task board cards and the Tasks page, with a progress bar.
- [x] Highlight tasks whose logged time exceeds the estimate.
- [x] Tests for the estimate validation, clearing, and the overrun calculation; the migration passes `scripts/verify-migrations.sh`.

### Project detail page

- [x] Replace the 8-entry time preview with a "Time by employee" section.
- [x] Add a "Time by task" section with estimate and progress per task.
- [x] Add project-level estimated effort vs. time logged on tasks.
- [x] Link to the Time Report pre-filtered by the project (`#/time-report/projects/<id>`).

### Optional extensions

- [x] Start/stop timer on a task that creates a pending time entry when stopped. The running timer is kept in this browser's storage only, so it does not follow the person to another device.
- [x] Weekly timesheet ("Timesheet" section): project/task rows × days, showing saved time and accepting new durations; `POST /api/operations/time-entries/batch` saves up to 100 entries atomically.
- [x] Reminders to employees who logged time in the previous four weeks but none on the previous working day (`MissingTimeReminder`, run by `app:reminders:run`, one per person and day, respects the reminder on/off preference). Leave and public holidays are not modelled.
- [x] Budget consumption on the project financial report: `budget_consumed_percent` and a warning once approved cost reaches 80% of the budget.
- [x] Weekly workload view (`GET /api/operations/time-report/workload`, Time Report → Weekly workload): logged time plus the remaining estimate of open assigned tasks in their due week, flagged when above a 40-hour week.

### Known limits

- The 40-hour weekly capacity and 80% budget warning are fixed constants (`WorkloadService::WEEKLY_CAPACITY_MINUTES`, `OperationsController::BUDGET_WARNING_PERCENT`), not per-person or per-project settings.
- Planned work in the workload view is placed entirely in the due week; it is not spread across the task's schedule.
- Missing-time reminders do not know about leave, part-time schedules or holidays.

## Phase 9: Configurable Modules Per Organization

Goal: each customer organization that runs the CRM can switch functional modules on or off and adjust module settings, so a deployment shows and allows only what that organization uses (for example project management and invoices without inventory or document management).

Decisions (2026-11-16): one organization per installation; administrators switch modules in Settings → Modules; data of a disabled module stays in the database (hidden and blocked while off, back when switched on); module visibility can additionally be limited to roles. No vendor or licence lock is planned: administrators of the installation decide. Clients is a switchable module too (2026-11-16).

Status: first implementation on 2026-11-16. Verified with PHPUnit (full SQLite suite; module and time-tracking flows also on PostgreSQL 17), `scripts/verify-migrations.sh`, Vitest and `npm run build`. Not yet clicked through in a running browser session.

### Module catalog and dependencies

- [x] Module catalog in one backend class (`App\Service\Module\ModuleCatalog`) with key, label, description, owned route prefixes and dependencies:
  - Core, always on: sign-in, users and roles, organization settings and modules, notes (hidden with their record's module), notifications, record history and recovery, dashboard, the shared user picker (`/api/projects/managers`) and currency list.
  - `clients`: clients, contacts and the client activity timeline. Needed by deals, projects, sales and invoices, so in practice it can be turned off only in setups without them, such as document management only; documents keep their optional client link, hidden while the module is off.
  - `news`; `sales_pipeline` (deals; needs `clients`); `projects` (projects, milestones, tasks, timetable, board; needs `clients`); `time_tracking` (needs `projects`); `project_operations` (expenses, reimbursements, project documents, profitability; needs `projects`); `sales` (products, quotes, orders, shipments; needs `clients`); `inventory` (needs `sales`); `invoices` (invoices, credit notes, payments; needs `clients`); `accounting` (ledger, periods, journals, payables, tax, jurisdiction, reports); `bank` (needs `invoices` and `accounting`, because bank accounts are linked to ledger accounts); `organization_structure` (units, positions, employments, delegations); `document_management` (needs `organization_structure`).
- [x] Cross-module links: invoice from approved time also needs `time_tracking`, from a milestone and manual invoices need `projects` (manual invoices are project-linked), invoicing a sales order needs `invoices` (`ROUTE_REQUIREMENTS`); automatic ledger postings are skipped while `accounting` is off; stock dispatch is an `inventory` route, so it is blocked with the module.
- [x] Unit test (`ModuleCatalogTest`): no dependency cycles, every dependency exists, every API route belongs to core or a module, every prefix matches a route, presets contain their dependencies, setting defaults are in range.

### Storage and administration API

- [x] `organization_module` (enabled, allowed roles, settings, updated by/at) and append-only `organization_module_change`, migration `Version20261116100000`. No rows are seeded: a module without a row is on for everyone with defaults, so existing installations keep their behavior.
- [x] `GET /api/settings/modules` (all users): catalog, enabled, `available` for the caller, allowed roles, settings and their schema, presets.
- [x] `PUT /api/settings/modules/{key}` (`ROLE_ADMIN`): enable/disable, allowed roles, settings. Enabling needs the dependencies on; disabling is refused with `409` while an enabled module needs it, naming the blocking modules.
- [x] History (`GET /api/settings/modules/history`) and restore of the state before a change (`POST /api/settings/modules/history/{id}/restore`); every change is also written to the audit log.
- [x] New routes classified in `ApiAccessPolicyTest`.

### Enforcement in the API

- [x] `ModuleAccessSubscriber` runs after the firewall and before controllers: `404` for routes of a disabled module, `403` for a module hidden from the caller's roles. Core routes are never blocked.
- [x] Route-to-module mapping pinned by `ModuleCatalogTest`: a new route fails until it is assigned.
- [x] Cross-module side effects: no ledger posting while `accounting` is off (source records are saved; the existing posting checks list them as unposted once accounting is on).
- [ ] Remaining cross-module checks inside endpoints that stay open: for example "dispatch on sales shipment" lists in the Sales page when `inventory` is off, and project document vs. DMS document links.
- [x] Scheduled work follows modules and roles: task/project reminders (`projects`), DMS approval/acknowledgement/review reminders, joiners and `app:dms:lifecycle` (`document_management`), missing-time reminders (`time_tracking` and its setting). Nobody is notified about a module their role cannot open.
- [x] Hidden from cross-cutting reads: `/api/history/{type}/{id}` for record types of unavailable modules, notes on clients, projects and tasks, notifications (list and unread count) about tasks/projects, documents and timesheets.
- [ ] Global search and dashboard summary counts filtered on the server (the dashboard hides the tiles in the UI).
- [x] Data of a disabled module is kept and inaccessible until it is switched back on (documented in Settings → Modules and the PRD).
- [x] Functional tests (`ModuleFlowTest`): routes `404` when off and back when on, dependency rules both ways, role restrictions, settings validation and effect, history and restore, presets, issuing and paying an invoice with accounting off, hidden history and notifications; integration tests for reminders and the console command.

### Per-module settings

- [x] Settings with today's values as defaults and server-side validation: `time_tracking.weekly_capacity_hours` (40), `time_tracking.missing_time_reminders` (on), `project_operations.budget_warning_percent` (80). The former constants now read `ModuleGate::setting()`.
- [ ] Further settings: time must be linked to a task, timer on/off, invoice sources and default payment terms, purchase approval threshold and document retention moved from `OrganizationSettings`, ClamAV required or optional.

### Frontend

- [x] Module configuration loaded after sign-in (`src/lib/modules.ts`, Vitest-covered); if it cannot be loaded nothing is hidden and the API still enforces it.
- [x] Sidebar built from available modules combined with the existing role checks.
- [x] Panels and calls of unavailable modules are skipped (`whenModule`) or hidden: client page projects/deals/invoices, project page time/expenses/invoices/financial report, dashboard tiles, task timer and logged time, milestone invoicing, invoice from time, Operations tabs, invoice/sales/finance/document page lookups.
- [x] "Not available" page for sections and detail links of unavailable modules.
- [x] Settings → Modules for administrators: switches with dependency explanations, role restriction, settings forms, presets with a preview, change history with restore.

### Packaging and onboarding

- [x] Presets: Everything, Projects and invoicing, Sales and inventory, Document management only (`POST /api/settings/modules/presets/{key}`).
- [x] Console command `app:modules:set --preset=… --enable=… --disable=…` with the same rules and history.
- [ ] Seed a new installation from an environment variable during setup.
- [x] README, PRD, `src/src/AGENTS.md` and `frontend/AGENTS.md` updated.

### Verification

- [x] Backend tests (SQLite) and module flows on PostgreSQL; `scripts/verify-migrations.sh`.
- [ ] `scripts/verify-*-e2e.sh` with document management on and off against a running API.
- [ ] Manual browser pass with a minimal preset (Document management only) and Everything.

### Resolved decisions

- No vendor or licence lock: the installation's administrators switch every module.
- Clients is a switchable module; the "Document management only" preset turns it off.

### Follow-up phase: several organizations in one installation

Out of scope here and a prerequisite for hosting many customers on shared infrastructure: an organization (tenant) entity, a tenant key on every business table, tenant-scoped queries and uniqueness rules, per-tenant numbering sequences, file storage and backups, tenant-aware users and JWTs, cross-tenant isolation tests, and per-tenant module configuration built on this phase.
