export type ProjectManagementStatus =
  | "planned"
  | "in_progress"
  | "overdue"
  | "finished"
  | "canceled"
  | "stopped";

export type TaskManagementStatus =
  | "planned"
  | "in_progress"
  | "overdue"
  | "finished"
  | "canceled"
  | "blocked";

export type ProjectSchedule = {
  status: "planned" | "active" | "on_hold" | "completed" | "cancelled";
  end_date: string | null;
};

export type TaskSchedule = {
  status: "todo" | "in_progress" | "blocked" | "done" | "cancelled";
  due_date: string | null;
  end_datetime: string | null;
};

export type TimelineDomain = {
  start: string;
  end: string;
};

export type TimelinePosition = {
  left: number;
  width: number;
};

function dateKey(value: string | null): string | null {
  if (value === null) return null;
  const key = value.slice(0, 10);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(key)) return null;
  const parsed = Date.parse(`${key}T00:00:00Z`);
  return Number.isFinite(parsed) && new Date(parsed).toISOString().slice(0, 10) === key ? key : null;
}

function dayNumber(value: string): number {
  return Date.parse(`${value}T00:00:00Z`) / 86_400_000;
}

export function projectManagementStatus(
  project: ProjectSchedule,
  today: string,
): ProjectManagementStatus {
  if (project.status === "completed") return "finished";
  if (project.status === "cancelled") return "canceled";
  if (project.end_date !== null && project.end_date < today) return "overdue";
  if (project.status === "on_hold") return "stopped";
  return project.status === "planned" ? "planned" : "in_progress";
}

export function taskManagementStatus(task: TaskSchedule, today: string): TaskManagementStatus {
  if (task.status === "done") return "finished";
  if (task.status === "cancelled") return "canceled";
  const endDate = dateKey(task.end_datetime);
  const dueDate = task.due_date ?? endDate;
  if (dueDate !== null && dueDate < today) return "overdue";
  if (task.status === "blocked") return "blocked";
  return task.status === "todo" ? "planned" : "in_progress";
}

export function matchesScheduleDateRange(
  start: string | null,
  end: string | null,
  from: string,
  to: string,
): boolean {
  if (from !== "" && to !== "" && from > to) return false;
  if (from === "" && to === "") return true;
  const startKey = dateKey(start);
  const endKey = dateKey(end);
  if (startKey === null && endKey === null) return false;
  return (to === "" || startKey === null || startKey <= to)
    && (from === "" || endKey === null || endKey >= from);
}

export function timelinePosition(
  start: string | null,
  end: string | null,
  domain: TimelineDomain,
): TimelinePosition | null {
  const startKey = dateKey(start);
  const endKey = dateKey(end);
  if (startKey === null || endKey === null || endKey < startKey) return null;

  const domainStart = dayNumber(domain.start);
  const domainEnd = dayNumber(domain.end);
  const totalDays = domainEnd - domainStart + 1;
  if (totalDays < 1) return null;

  const clippedStart = Math.max(dayNumber(startKey), domainStart);
  const clippedEnd = Math.min(dayNumber(endKey), domainEnd);
  if (clippedEnd < clippedStart) return null;

  return {
    left: ((clippedStart - domainStart) / totalDays) * 100,
    width: ((clippedEnd - clippedStart + 1) / totalDays) * 100,
  };
}

export function overdueDays(end: string | null, today: string): number {
  const endKey = dateKey(end);
  return endKey === null || endKey >= today ? 0 : dayNumber(today) - dayNumber(endKey);
}
