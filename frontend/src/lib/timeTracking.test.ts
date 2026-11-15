import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  addDays,
  cellKey,
  elapsedMinutes,
  estimateProgress,
  formatMinutes,
  loadTimer,
  mondayOf,
  parseDuration,
  presetRange,
  saveTimer,
  timeQuery,
  timesheetPayload,
  timesheetRows,
  toMatrix,
  weekDays,
  type TimeEntry,
  type TimeReportRow,
} from "./timeTracking";

const totals = (total: number) => ({ total_minutes: total, approved_minutes: 0, pending_minutes: total, rejected_minutes: 0, billable_minutes: 0 });

describe("formatMinutes", () => {
  it("formats hours and padded minutes", () => {
    expect(formatMinutes(0)).toBe("0m");
    expect(formatMinutes(45)).toBe("45m");
    expect(formatMinutes(125)).toBe("2h 05m");
    expect(formatMinutes(-90)).toBe("-1h 30m");
  });
});

describe("parseDuration", () => {
  it.each([
    ["90", 90], ["90m", 90], ["1:30", 90], ["2h", 120], ["1h 15m", 75], ["1h15", 75], ["1.5", 90], ["1,25", 75], ["0.1", 6], ["7.75h", 465], ["120h", 7200], ["100:00", 6000], ["2h 05m", 125],
  ])("reads %s as %i minutes", (text, minutes) => {
    expect(parseDuration(text)).toBe(minutes);
  });

  it.each(["", "abc", "1:75", "1h 90m", "1.234", "-5"])("rejects %s", (text) => {
    expect(parseDuration(text)).toBeNull();
  });
});

describe("dates", () => {
  it("finds the Monday of a week and lists its days", () => {
    expect(mondayOf("2026-11-15")).toBe("2026-11-09");
    expect(mondayOf("2026-11-09")).toBe("2026-11-09");
    expect(weekDays("2026-11-09")).toEqual(["2026-11-09", "2026-11-10", "2026-11-11", "2026-11-12", "2026-11-13", "2026-11-14", "2026-11-15"]);
    expect(addDays("2026-02-28", 1)).toBe("2026-03-01");
  });

  it("computes presets", () => {
    expect(presetRange("this_week", "2026-11-15")).toEqual({ from: "2026-11-09", to: "2026-11-15" });
    expect(presetRange("last_week", "2026-11-15")).toEqual({ from: "2026-11-02", to: "2026-11-08" });
    expect(presetRange("this_month", "2026-02-10")).toEqual({ from: "2026-02-01", to: "2026-02-28" });
    expect(presetRange("last_month", "2026-01-10")).toEqual({ from: "2025-12-01", to: "2025-12-31" });
  });
});

describe("timeQuery", () => {
  it("drops empty filters and appends extras", () => {
    expect(timeQuery({ from: "2026-11-01", to: "", projectId: 3, userId: null, status: "", billable: "true" }, { group_by: "employee,project" }))
      .toBe("from=2026-11-01&project_id=3&billable=true&group_by=employee%2Cproject");
  });
});

describe("toMatrix", () => {
  it("builds an employee x project grid with totals", () => {
    const rows: TimeReportRow[] = [
      { employee_id: 2, employee_email: "zed@x", project_id: 1, project_title: "Beta", ...totals(30) },
      { employee_id: 1, employee_email: "amy@x", project_id: 1, project_title: "Beta", ...totals(60) },
      { employee_id: 1, employee_email: "amy@x", project_id: 5, project_title: "Alpha", ...totals(15) },
    ];
    const matrix = toMatrix(rows);
    expect(matrix.employees.map((item) => [item.email, item.total])).toEqual([["amy@x", 75], ["zed@x", 30]]);
    expect(matrix.projects.map((item) => [item.title, item.total])).toEqual([["Alpha", 15], ["Beta", 90]]);
    expect(matrix.cells.get(cellKey(1, 5))?.total_minutes).toBe(15);
    expect(matrix.cells.has(cellKey(2, 5))).toBe(false);
    expect(matrix.total).toBe(105);
  });
});

describe("estimateProgress", () => {
  it("compares logged time with the estimate", () => {
    expect(estimateProgress(null, 30)).toEqual({ percent: null, over: false, remaining: null });
    expect(estimateProgress(120, 90)).toEqual({ percent: 75, over: false, remaining: 30 });
    expect(estimateProgress(60, 90)).toEqual({ percent: 150, over: true, remaining: -30 });
    expect(estimateProgress(1, 100000).percent).toBe(999);
  });
});

describe("timesheet", () => {
  const entry = (fields: Partial<TimeEntry>): TimeEntry => ({ id: 1, project_id: 1, project_title: "Site", task_id: null, task_title: null, user_id: 1, user_email: "a@x", work_date: "2026-11-09", duration_minutes: 60, billable: false, approval_status: "pending_approval", description: "w", ...fields });

  it("groups existing week entries by project and task, ignoring rejected time", () => {
    const days = weekDays("2026-11-09");
    const rows = timesheetRows([
      entry({}),
      entry({ id: 2, duration_minutes: 30 }),
      entry({ id: 3, task_id: 7, task_title: "Design", work_date: "2026-11-10" }),
      entry({ id: 4, approval_status: "rejected", duration_minutes: 500 }),
      entry({ id: 5, work_date: "2026-11-20" }),
    ], days);
    expect(rows.map((row) => [row.key, row.minutes])).toEqual([["1:7", { "2026-11-10": 60 }], ["1:-", { "2026-11-09": 90 }]]);
  });

  it("validates typed cells into a batch", () => {
    const base = { projectId: 1, taskId: null, day: "2026-11-09", description: "Build", billable: false };
    expect(timesheetPayload([{ ...base, text: "1:30" }, { ...base, text: "  " }])).toEqual({ entries: [{ project_id: 1, task_id: null, work_date: "2026-11-09", duration_minutes: 90, description: "Build", billable: false }] });
    expect(timesheetPayload([{ ...base, text: "" }])).toEqual({ error: "Enter at least one duration." });
    expect(timesheetPayload([{ ...base, text: "25h" }])).toHaveProperty("error");
    expect(timesheetPayload([{ ...base, text: "lots" }])).toHaveProperty("error");
    expect(timesheetPayload([{ ...base, text: "1h", description: " " }])).toEqual({ error: "Add a description for the time on 2026-11-09." });
  });
});

describe("timer", () => {
  beforeEach(() => {
    const store = new Map<string, string>();
    vi.stubGlobal("localStorage", { getItem: (key: string) => store.get(key) ?? null, setItem: (key: string, value: string) => store.set(key, value), removeItem: (key: string) => store.delete(key) });
  });
  afterEach(() => vi.unstubAllGlobals());

  it("rounds elapsed time to whole minutes within the entry limit", () => {
    expect(elapsedMinutes(0, 10_000)).toBe(1);
    expect(elapsedMinutes(0, 90 * 60_000 + 29_000)).toBe(90);
    expect(elapsedMinutes(0, 30 * 3_600_000)).toBe(1440);
  });

  it("persists the running timer and ignores malformed storage", () => {
    expect(loadTimer()).toBeNull();
    saveTimer({ taskId: 3, projectId: 1, title: "Design", startedAt: 1000 });
    expect(loadTimer()).toEqual({ taskId: 3, projectId: 1, title: "Design", startedAt: 1000 });
    saveTimer(null);
    expect(loadTimer()).toBeNull();
    localStorage.setItem("crm_task_timer", "{not json");
    expect(loadTimer()).toBeNull();
    vi.stubGlobal("localStorage", { getItem: () => { throw new Error("blocked"); }, setItem: () => { throw new Error("blocked"); } });
    expect(loadTimer()).toBeNull();
    expect(() => saveTimer({ taskId: 1, projectId: 1, title: "x", startedAt: 1 })).not.toThrow();
  });
});
