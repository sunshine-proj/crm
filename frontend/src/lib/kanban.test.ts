import { describe, expect, it } from "vitest";
import { canMove, filterBoardTasks, groupByStatus, type BoardTask } from "./kanban";

const tasks: BoardTask[] = [
  { id: 1, status: "todo", project_id: 1, assignee_id: 7 },
  { id: 2, status: "done", project_id: 1, assignee_id: null },
  { id: 3, status: "in_progress", project_id: 2, assignee_id: 7 },
  { id: 4, status: "cancelled", project_id: 2, assignee_id: 8 },
];

describe("kanban board helpers", () => {
  it("keeps every column and groups by status in input order", () => {
    const groups = groupByStatus(tasks);
    expect(Object.keys(groups)).toEqual(["todo", "in_progress", "blocked", "done", "cancelled"]);
    expect(groups.todo.map((task) => task.id)).toEqual([1]);
    expect(groups.blocked).toEqual([]);
  });

  it("filters by project, assignee and closed state", () => {
    expect(filterBoardTasks(tasks, { projectId: 2, assigneeId: null, hideClosed: false }).map((task) => task.id)).toEqual([3, 4]);
    expect(filterBoardTasks(tasks, { projectId: null, assigneeId: 7, hideClosed: false }).map((task) => task.id)).toEqual([1, 3]);
    expect(filterBoardTasks(tasks, { projectId: null, assigneeId: null, hideClosed: true }).map((task) => task.id)).toEqual([1, 3]);
  });

  it("only allows moves to a different column", () => {
    expect(canMove({ status: "todo" }, "todo")).toBe(false);
    expect(canMove({ status: "todo" }, "blocked")).toBe(true);
  });
});
