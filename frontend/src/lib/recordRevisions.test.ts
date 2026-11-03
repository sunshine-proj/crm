import { describe, expect, it } from "vitest";
import { changeSummary, restoreWarning, revisionLabel, type RecordRevision } from "./recordRevisions";

const base: RecordRevision = { id: 1, record_type: "client_contact", record_id: 7, action: "update", snapshot: { name: "Ann" }, changes: { name: ["A", "Ann"], updatedAt: ["x", "y"] }, actor: "a@b.c", created_at: "2026-11-03T10:00:00+00:00" };

describe("record revisions", () => {
  it("labels a revision with its type and best available name", () => {
    expect(revisionLabel(base)).toBe("client contact · Ann");
    expect(revisionLabel({ ...base, snapshot: {} })).toBe("client contact · #7");
  });

  it("summarises changed fields without the bookkeeping timestamp", () => {
    expect(changeSummary(base)).toBe("Changed name");
    expect(changeSummary({ ...base, action: "delete", changes: null })).toBe("Deleted");
    expect(changeSummary({ ...base, action: "create", changes: null })).toBe("Created");
  });

  it("warns that restoring a deleted record creates a new ID", () => {
    expect(restoreWarning({ ...base, action: "delete" })).toContain("new ID");
    expect(restoreWarning(base)).toContain("new revision");
  });
});
