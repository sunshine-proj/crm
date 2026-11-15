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
