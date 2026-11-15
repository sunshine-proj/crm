/** Time-tracking helpers shared by the time report, timesheet, task timer and project pages. All values are whole minutes. */

export type TimeTotals = {
  total_minutes: number;
  approved_minutes: number;
  pending_minutes: number;
  rejected_minutes: number;
  billable_minutes: number;
  approved_cost?: { currency: string; amount: string }[];
};

export type TimeReportRow = TimeTotals & {
  employee_id?: number;
  employee_email?: string;
  project_id?: number;
  project_title?: string;
  task_id?: number | null;
  task_title?: string | null;
  task_estimated_minutes?: number | null;
};

export type TimeScope = "all" | "managed" | "own";

export type TimeReport = { scope: TimeScope; group_by: string[]; rows: TimeReportRow[]; totals: TimeTotals };

export type TimeEntry = {
  id: number;
  project_id: number;
  project_title: string;
  task_id: number | null;
  task_title: string | null;
  user_id: number;
  user_email: string;
  work_date: string;
  duration_minutes: number;
  billable: boolean;
  approval_status: string;
  description: string;
};

export type TimeFilters = {
  from: string;
  to: string;
  projectId: number | null;
  userId: number | null;
  status: string;
  billable: "" | "true" | "false";
};

/** "2h 05m", "45m", "0m". */
export function formatMinutes(minutes: number): string {
  const sign = minutes < 0 ? "-" : "";
  const value = Math.abs(Math.trunc(minutes));
  const hours = Math.floor(value / 60);
  const rest = value % 60;
  if (hours === 0) return `${sign}${rest}m`;
  return `${sign}${hours}h ${String(rest).padStart(2, "0")}m`;
}

/**
 * Parses a duration typed by a person: "90" or "90m" (minutes), "1:30", "1h 30m", "2h", "1.5" or "1,25" (hours,
 * at most two decimals). Returns whole minutes, or null when the text is not a duration.
 */
export function parseDuration(text: string): number | null {
  const value = text.trim().toLowerCase();
  if (value === "") return null;
  let match = /^(\d{1,4})\s*m?$/.exec(value);
  if (match) return Number(match[1]);
  match = /^(\d{1,5}):([0-5]\d)$/.exec(value);
  if (match) return Number(match[1]) * 60 + Number(match[2]);
  match = /^(\d{1,5})\s*h(?:\s*(\d{1,2})\s*m?)?$/.exec(value);
  if (match) {
    const minutes = match[2] === undefined ? 0 : Number(match[2]);
    return minutes < 60 ? Number(match[1]) * 60 + minutes : null;
  }
  match = /^(\d{1,5})(?:[.,](\d{1,2}))?\s*h$|^(\d{1,5})[.,](\d{1,2})$/.exec(value);
  if (match) {
    // Integer arithmetic: hundredths of an hour, rounded to the nearest minute.
    const whole = match[1] ?? match[3];
    const fraction = match[2] ?? match[4] ?? "0";
    const hundredths = Number(whole) * 100 + Number(fraction.padEnd(2, "0"));
    return Math.floor((hundredths * 60 + 50) / 100);
  }
  return null;
}

/** Local calendar date as YYYY-MM-DD. */
export function isoDate(date: Date): string {
  const pad = (part: number) => String(part).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function fromIso(value: string): Date {
  const [year, month, day] = value.split("-").map(Number);
  return new Date(year, month - 1, day);
}

export function addDays(value: string, days: number): string {
  const date = fromIso(value);
  date.setDate(date.getDate() + days);
  return isoDate(date);
}

/** Monday of the ISO week containing the date. */
export function mondayOf(value: string): string {
  const weekday = (fromIso(value).getDay() + 6) % 7;
  return addDays(value, -weekday);
}

export function weekDays(monday: string): string[] {
  return Array.from({ length: 7 }, (_, index) => addDays(monday, index));
}

export type DatePreset = "this_week" | "last_week" | "this_month" | "last_month";

export function presetRange(preset: DatePreset, today: string): { from: string; to: string } {
  if (preset === "this_week" || preset === "last_week") {
    const monday = addDays(mondayOf(today), preset === "last_week" ? -7 : 0);
    return { from: monday, to: addDays(monday, 6) };
  }
  const date = fromIso(today);
  const first = new Date(date.getFullYear(), date.getMonth() - (preset === "last_month" ? 1 : 0), 1);
  const last = new Date(first.getFullYear(), first.getMonth() + 1, 0);
  return { from: isoDate(first), to: isoDate(last) };
}

/** Query string for the report, export and entry-list endpoints. */
export function timeQuery(filters: Partial<TimeFilters>, extra: Record<string, string | number | null | undefined> = {}): string {
  const params = new URLSearchParams();
  const set = (name: string, value: string | number | null | undefined) => {
    if (value !== null && value !== undefined && value !== "") params.set(name, String(value));
  };
  set("from", filters.from);
  set("to", filters.to);
  set("project_id", filters.projectId);
  set("user_id", filters.userId);
  set("status", filters.status);
  set("billable", filters.billable);
  for (const [name, value] of Object.entries(extra)) set(name, value);
  return params.toString();
}

export type Matrix = {
  employees: { id: number; email: string; total: number }[];
  projects: { id: number; title: string; total: number }[];
  cells: Map<string, TimeReportRow>;
  total: number;
};

export const cellKey = (employeeId: number, projectId: number) => `${employeeId}:${projectId}`;

/** Employee × project grid from a report grouped by employee and project. */
export function toMatrix(rows: TimeReportRow[]): Matrix {
  const employees = new Map<number, { id: number; email: string; total: number }>();
  const projects = new Map<number, { id: number; title: string; total: number }>();
  const cells = new Map<string, TimeReportRow>();
  let total = 0;
  for (const row of rows) {
    if (row.employee_id === undefined || row.project_id === undefined) continue;
    const employee = employees.get(row.employee_id) ?? { id: row.employee_id, email: row.employee_email ?? "", total: 0 };
    const project = projects.get(row.project_id) ?? { id: row.project_id, title: row.project_title ?? "", total: 0 };
    employee.total += row.total_minutes;
    project.total += row.total_minutes;
    total += row.total_minutes;
    employees.set(employee.id, employee);
    projects.set(project.id, project);
    cells.set(cellKey(employee.id, project.id), row);
  }
  const byText = <T>(label: (item: T) => string) => (a: T, b: T) => label(a).localeCompare(label(b));
  return {
    employees: [...employees.values()].sort(byText((item) => item.email)),
    projects: [...projects.values()].sort(byText((item) => item.title)),
    cells,
    total,
  };
}

export type EstimateProgress = { percent: number | null; over: boolean; remaining: number | null };

/** Logged vs. estimated minutes; percent is capped for display at 999. */
export function estimateProgress(estimate: number | null | undefined, logged: number): EstimateProgress {
  if (!estimate || estimate <= 0) return { percent: null, over: false, remaining: null };
  return { percent: Math.min(999, Math.floor((logged * 100) / estimate)), over: logged > estimate, remaining: estimate - logged };
}

export type TimesheetRow = { key: string; projectId: number; projectTitle: string; taskId: number | null; taskTitle: string | null; minutes: Record<string, number> };

export const timesheetKey = (projectId: number, taskId: number | null) => `${projectId}:${taskId ?? "-"}`;

/** Existing (non-rejected) entries of a week, one row per project/task with minutes per day. */
export function timesheetRows(entries: TimeEntry[], days: string[]): TimesheetRow[] {
  const rows = new Map<string, TimesheetRow>();
  for (const entry of entries) {
    if (entry.approval_status === "rejected" || !days.includes(entry.work_date)) continue;
    const key = timesheetKey(entry.project_id, entry.task_id);
    const row = rows.get(key) ?? { key, projectId: entry.project_id, projectTitle: entry.project_title, taskId: entry.task_id, taskTitle: entry.task_title, minutes: {} };
    row.minutes[entry.work_date] = (row.minutes[entry.work_date] ?? 0) + entry.duration_minutes;
    rows.set(key, row);
  }
  return [...rows.values()].sort((a, b) => `${a.projectTitle}\u0000${a.taskTitle ?? "￿"}`.localeCompare(`${b.projectTitle}\u0000${b.taskTitle ?? "￿"}`));
}

export type TimesheetDraft = { projectId: number; taskId: number | null; day: string; text: string; description: string; billable: boolean };

export type TimesheetPayload = { project_id: number; task_id: number | null; work_date: string; duration_minutes: number; description: string; billable: boolean };

/** Validates typed cells into the batch payload; returns the first problem instead when a cell is not valid. */
export function timesheetPayload(drafts: TimesheetDraft[]): { entries: TimesheetPayload[] } | { error: string } {
  const entries: TimesheetPayload[] = [];
  for (const draft of drafts) {
    if (draft.text.trim() === "") continue;
    const minutes = parseDuration(draft.text);
    if (minutes === null || minutes < 1 || minutes > 1440) return { error: `"${draft.text}" on ${draft.day} is not a duration between 1 minute and 24 hours.` };
    const description = draft.description.trim();
    if (description === "") return { error: `Add a description for the time on ${draft.day}.` };
    if (description.length > 255) return { error: "Descriptions can be at most 255 characters." };
    entries.push({ project_id: draft.projectId, task_id: draft.taskId, work_date: draft.day, duration_minutes: minutes, description, billable: draft.billable });
  }
  if (entries.length === 0) return { error: "Enter at least one duration." };
  if (entries.length > 100) return { error: "Submit at most 100 entries at once." };
  return { entries };
}

export type RunningTimer = { taskId: number; projectId: number; title: string; startedAt: number };

const TIMER_KEY = "crm_task_timer";

/** Whole minutes elapsed, at least 1 and at most 24 hours (the per-entry limit). */
export function elapsedMinutes(startedAt: number, now: number): number {
  const minutes = Math.round((now - startedAt) / 60000);
  return Math.min(1440, Math.max(1, minutes));
}

/** The timer lives in this browser only; storage may be unavailable (private mode), so failures are ignored. */
export function loadTimer(): RunningTimer | null {
  try {
    const raw = localStorage.getItem(TIMER_KEY);
    if (!raw) return null;
    const value = JSON.parse(raw) as Partial<RunningTimer>;
    if (typeof value.taskId !== "number" || typeof value.projectId !== "number" || typeof value.startedAt !== "number" || typeof value.title !== "string") return null;
    return value as RunningTimer;
  } catch {
    return null;
  }
}

export function saveTimer(timer: RunningTimer | null): void {
  try {
    if (timer === null) localStorage.removeItem(TIMER_KEY);
    else localStorage.setItem(TIMER_KEY, JSON.stringify(timer));
  } catch {
    // Without storage the timer still runs for this page view.
  }
}

export const scopeNotes: Record<TimeScope, string> = {
  all: "All employees' time.",
  managed: "Time on the projects you manage, plus your own.",
  own: "Only your own time. Project managers, finance and administrators see their teams.",
};
