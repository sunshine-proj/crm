import { describe, expect, it } from "vitest";
import { barWidth, emptySelection, filterCounts, filterRecipients, progressText, recipientsInUnit, searchPeople, selectionSize, statusTone, targetsPayload, type AckCounts, type AckRecipient, type AckUnitRow } from "./acknowledgements";

const counts = (overrides: Partial<AckCounts> = {}): AckCounts => ({ total: 5, pending: 1, viewed: 1, acknowledged: 2, excused: 1, open: 2, overdue: 1, late: 0, completion_percent: 50, ...overrides });

function row(id: number, status: AckRecipient["status"], isOverdue = false): AckRecipient {
  return {
    id, user: { id, email: `p${id}@example.test` }, unit: null, position: null, source: "sent", added_by: "boss", status, is_overdue: isOverdue, is_late: false,
    viewed_at: null, acknowledged_at: null, acknowledged_sha256: null, excused_at: null, excused_by: null, excuse_reason: null, last_reminded_at: null,
    reminder_count: 0, created_at: "2026-11-13T10:00:00+00:00", can_remind: true, can_excuse: true, can_reinstate: false,
  };
}

describe("acknowledgement helpers", () => {
  it("turns a picker selection into API targets", () => {
    const selection = emptySelection();
    expect(selectionSize(selection)).toBe(0);
    selection.units[3] = true;
    selection.units[9] = false;
    selection.positions.push(4);
    selection.people.push(11, 12);
    expect(selectionSize(selection)).toBe(5);
    expect(targetsPayload(selection)).toEqual([
      { kind: "unit", id: 3, include_subunits: true },
      { kind: "unit", id: 9, include_subunits: false },
      { kind: "position", id: 4 },
      { kind: "user", id: 11 },
      { kind: "user", id: 12 },
    ]);
  });

  it("filters recipients and counts each filter", () => {
    const rows = [row(1, "pending", true), row(2, "viewed"), row(3, "acknowledged"), row(4, "excused")];
    expect(filterRecipients(rows, "open").map((item) => item.id)).toEqual([1, 2]);
    expect(filterRecipients(rows, "overdue").map((item) => item.id)).toEqual([1]);
    expect(filterRecipients(rows, "excused").map((item) => item.id)).toEqual([4]);
    expect(filterRecipients(rows, "all")).toHaveLength(4);
    expect(filterCounts(rows)).toEqual({ all: 4, open: 2, overdue: 1, pending: 1, viewed: 1, acknowledged: 1, excused: 1 });
  });

  it("describes progress and tones", () => {
    expect(progressText(counts())).toBe("2 of 5 acknowledged · 1 excused · 1 overdue");
    expect(progressText(counts({ excused: 0, overdue: 0 }))).toBe("2 of 5 acknowledged");
    expect(barWidth(counts({ completion_percent: 140 }))).toBe("100%");
    expect(statusTone({ status: "viewed", is_overdue: true })).toBe("dms-tone-attention");
    expect(statusTone({ status: "pending", is_overdue: false })).toBe("dms-tone-progress");
    expect(statusTone({ status: "acknowledged", is_overdue: false })).toBe("dms-tone-done");
  });

  it("searches people by e-mail, position and unit words", () => {
    const people = [
      { id: 1, email: "ann@example.test", position_title: "Accountant", unit_id: 1, unit_name: "Finance" },
      { id: 2, email: "bob@example.test", position_title: "Driver", unit_id: 2, unit_name: "Logistics" },
    ];
    expect(searchPeople(people, "")).toHaveLength(2);
    expect(searchPeople(people, "logistics BOB").map((person) => person.id)).toEqual([2]);
    expect(searchPeople(people, "ann driver")).toEqual([]);
  });

  it("drills down into a unit and everything below it", () => {
    const unitRow = (unitId: number, parentId: number | null): AckUnitRow => ({ unit_id: unitId, parent_id: parentId, name: `U${unitId}`, depth: 0, own: counts(), total: counts() });
    const units = [unitRow(1, null), unitRow(2, 1), unitRow(3, 2), unitRow(4, null)];
    const rows: AckRecipient[] = [1, 2, 3, 4].map((unitId) => ({ ...row(unitId, "pending"), unit: { id: unitId, name: `U${unitId}` } }));
    rows.push({ ...row(9, "pending"), unit: null });
    expect(recipientsInUnit(rows, units, 2).map((item) => item.id)).toEqual([2, 3]);
    expect(recipientsInUnit(rows, units, 1).map((item) => item.id)).toEqual([1, 2, 3]);
    expect(recipientsInUnit(rows, units, null).map((item) => item.id)).toEqual([9]);
  });
});
