import { describe, expect, it } from "vitest";
import { auditActionLabel, auditDetails, auditQuery, formatDuration, statusMatrix } from "./records";

describe("records helpers", () => {
  it("labels audit actions", () => {
    expect(auditActionLabel("viewed")).toBe("Viewed");
    expect(auditActionLabel("approval_approved_with_comments")).toBe("Approval: approved with comments");
    expect(auditActionLabel("acknowledgement_reminded")).toBe("Acknowledgement reminded");
  });

  it("builds the audit query and shows details", () => {
    expect(auditQuery({ action: "", actor: " ", from: "", to: "" })).toBe("");
    expect(auditQuery({ action: "readers", actor: "ann", from: "2026-01-01", to: "" }, "csv")).toBe("?action=readers&actor=ann&from=2026-01-01&format=csv");
    expect(auditDetails({ added: "a@x", removed: null, count: 2, empty: "" })).toBe("added: a@x · count: 2");
  });

  it("formats approval durations", () => {
    expect(formatDuration(0.4)).toBe("24 min");
    expect(formatDuration(5.25)).toBe("5.3 h");
    expect(formatDuration(50)).toBe("2.1 days");
  });

  it("pivots documents by type and status in status order", () => {
    const matrix = statusMatrix([
      { type: "Policy", unit: "Sales", status: "in_force", count: 2 },
      { type: "Policy", unit: null, status: "draft", count: 1 },
      { type: "Order", unit: "Sales", status: "in_force", count: 3 },
      { type: "Policy", unit: "IT", status: "in_force", count: 1 },
    ]);
    expect(matrix.statuses).toEqual(["draft", "in_force"]);
    expect(matrix.types).toEqual([
      { type: "Order", counts: { in_force: 3 }, total: 3 },
      { type: "Policy", counts: { in_force: 3, draft: 1 }, total: 4 },
    ]);
  });
});
