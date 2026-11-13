import { describe, expect, it } from "vitest";
import { moveProblem, personLabel, subtreeIds, treeRows, unitPath, type OrgUnit } from "./org";

function unit(id: number, parent_id: number | null, name: string, depth: number, extra: Partial<OrgUnit> = {}): OrgUnit {
  return { id, parent_id, code: name.toUpperCase(), name, kind: parent_id === null ? "organization" : "unit", depth, head: null, member_count: 0, archived_at: null, ...extra };
}

const units = [
  unit(1, null, "Acme", 1),
  unit(3, 1, "Sales", 2),
  unit(2, 1, "IT", 2),
  unit(4, 3, "East", 3, { kind: "section" }),
  unit(5, 3, "West", 3, { kind: "section", archived_at: "2026-01-01T00:00:00+00:00" }),
];

describe("treeRows", () => {
  it("lists units depth-first by name with levels", () => {
    expect(treeRows(units).map((row) => [row.unit.name, row.level, row.hasChildren])).toEqual([
      ["Acme", 0, true],
      ["IT", 1, false],
      ["Sales", 1, true],
      ["East", 2, false],
    ]);
  });

  it("includes archived units on request and shows orphans as roots", () => {
    expect(treeRows(units, true).map((row) => row.unit.name)).toContain("West");
    expect(treeRows([unit(9, 42, "Orphan", 2)]).map((row) => [row.unit.name, row.level])).toEqual([["Orphan", 0]]);
  });
});

describe("moveProblem", () => {
  it("accepts a valid move", () => {
    expect(moveProblem(units, 4, 2)).toBeNull();
  });

  it("refuses cycles, archived targets, the root, no-op moves and excess depth", () => {
    expect(moveProblem(units, 3, 4)).toMatch(/itself/);
    expect(moveProblem(units, 3, 3)).toMatch(/itself/);
    expect(moveProblem(units, 4, 5)).toMatch(/archived/);
    expect(moveProblem(units, 1, 2)).toMatch(/top/);
    expect(moveProblem(units, 4, 3)).toMatch(/already/);
    expect(moveProblem(units, 3, 2, 3)).toMatch(/limited to 3/);
    expect(moveProblem(units, 99, 2)).toMatch(/not found/);
  });
});

describe("subtreeIds and unitPath", () => {
  it("collects descendants and builds the breadcrumb", () => {
    expect([...subtreeIds(units, 3)].sort()).toEqual([3, 4, 5]);
    expect(unitPath(units, 4).map((item) => item.name)).toEqual(["Acme", "Sales", "East"]);
    expect(unitPath(units, 99)).toEqual([]);
  });
});

describe("personLabel", () => {
  it("adds the position when known", () => {
    expect(personLabel({ email: "a@x.test", position_title: "Head" })).toBe("a@x.test · Head");
    expect(personLabel({ email: "a@x.test", position_title: null })).toBe("a@x.test");
  });
});
