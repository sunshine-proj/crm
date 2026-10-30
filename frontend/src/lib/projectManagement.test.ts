import { describe, expect, it } from "vitest";
import {
  matchesScheduleDateRange,
  overdueDays,
  projectManagementStatus,
  taskManagementStatus,
  timelinePosition,
} from "./projectManagement";

describe("project management statuses", () => {
  it("maps project lifecycle values and marks unfinished past-end projects overdue", () => {
    expect(projectManagementStatus({ status: "planned", end_date: null }, "2026-10-30")).toBe("planned");
    expect(projectManagementStatus({ status: "active", end_date: "2026-10-29" }, "2026-10-30")).toBe("overdue");
    expect(projectManagementStatus({ status: "on_hold", end_date: null }, "2026-10-30")).toBe("stopped");
    expect(projectManagementStatus({ status: "on_hold", end_date: "2026-10-29" }, "2026-10-30")).toBe("overdue");
    expect(projectManagementStatus({ status: "completed", end_date: null }, "2026-10-30")).toBe("finished");
    expect(projectManagementStatus({ status: "cancelled", end_date: null }, "2026-10-30")).toBe("canceled");
  });

  it("maps task lifecycle, deadline, and blocked states", () => {
    expect(taskManagementStatus({ status: "todo", due_date: null, end_datetime: "2026-10-29T12:00:00+00:00" }, "2026-10-30")).toBe("overdue");
    expect(taskManagementStatus({ status: "blocked", due_date: null, end_datetime: "2026-10-31T12:00:00+00:00" }, "2026-10-30")).toBe("blocked");
    expect(taskManagementStatus({ status: "done", due_date: "2026-10-29", end_datetime: "2026-10-29T12:00:00+00:00" }, "2026-10-30")).toBe("finished");
    expect(taskManagementStatus({ status: "cancelled", due_date: null, end_datetime: "2026-10-31T12:00:00+00:00" }, "2026-10-30")).toBe("canceled");
  });
});

describe("project management date calculations", () => {
  it("matches schedules that overlap a selected date range", () => {
    expect(matchesScheduleDateRange("2026-10-01", "2026-10-04", "2026-10-04", "2026-10-10")).toBe(true);
    expect(matchesScheduleDateRange("2026-10-01", "2026-10-03", "2026-10-04", "2026-10-10")).toBe(false);
    expect(matchesScheduleDateRange(null, null, "2026-10-04", "2026-10-10")).toBe(false);
    expect(matchesScheduleDateRange("2026-10-01", "2026-10-10", "2026-10-10", "2026-10-01")).toBe(false);
  });

  it("calculates inclusive and clipped timeline bars", () => {
    expect(timelinePosition("2026-10-03", "2026-10-04", { start: "2026-10-01", end: "2026-10-10" })).toEqual({
      left: 20,
      width: 20,
    });
    expect(timelinePosition("2026-09-30", "2026-10-02", { start: "2026-10-01", end: "2026-10-10" })).toEqual({
      left: 0,
      width: 20,
    });
    expect(timelinePosition("2026-10-03", null, { start: "2026-10-01", end: "2026-10-10" })).toBeNull();
  });

  it("calculates overdue days without counting the due date itself", () => {
    expect(overdueDays("2026-10-29", "2026-10-30")).toBe(1);
    expect(overdueDays("2026-10-30", "2026-10-30")).toBe(0);
  });
});
