export type BoardStatus = "todo" | "in_progress" | "blocked" | "done" | "cancelled";

export const boardColumns: { status: BoardStatus; label: string }[] = [
  { status: "todo", label: "To do" },
  { status: "in_progress", label: "In progress" },
  { status: "blocked", label: "Blocked" },
  { status: "done", label: "Done" },
  { status: "cancelled", label: "Canceled" },
];

export type BoardTask = { id: number; status: BoardStatus; project_id: number | null; assignee_id?: number | null };

/** Groups tasks by status, keeping every column present and the input order within a column. */
export function groupByStatus<T extends { status: BoardStatus }>(tasks: T[]): Record<BoardStatus, T[]> {
  const groups: Record<BoardStatus, T[]> = { todo: [], in_progress: [], blocked: [], done: [], cancelled: [] };
  for (const task of tasks) groups[task.status]?.push(task);
  return groups;
}

export function filterBoardTasks<T extends BoardTask>(tasks: T[], filters: { projectId: number | null; assigneeId: number | null; hideClosed: boolean }): T[] {
  return tasks.filter(
    (task) =>
      (filters.projectId === null || task.project_id === filters.projectId) &&
      (filters.assigneeId === null || task.assignee_id === filters.assigneeId) &&
      (!filters.hideClosed || (task.status !== "done" && task.status !== "cancelled")),
  );
}

/** Moving to the current column is a no-op; every other move is allowed (the API validates dependencies). */
export function canMove(task: { status: BoardStatus }, target: BoardStatus): boolean {
  return task.status !== target;
}
