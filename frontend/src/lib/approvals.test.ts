import { describe, expect, it } from "vitest";
import { actionLabel, emptyStage, stagesFromTemplate, stepTone, submitPayload, validateRoute, type DraftStage } from "./approvals";

const today = "2026-06-01";

describe("validateRoute", () => {
  it("accepts superiors for approvals and anyone for reviews", () => {
    const stages: DraftStage[] = [
      { rule: "all", steps: [{ assigneeId: 2, kind: "approve", dueOn: "" }, { assigneeId: 9, kind: "review", dueOn: "2026-06-03" }] },
      { rule: "any", steps: [{ assigneeId: 3, kind: "sign", dueOn: "" }] },
    ];
    expect(validateRoute(stages, [2, 3], today)).toEqual([]);
  });

  it("reports every kind of problem once", () => {
    const stages: DraftStage[] = [
      { rule: "all", steps: [{ assigneeId: null, kind: "approve", dueOn: "" }, { assigneeId: 9, kind: "approve", dueOn: "" }, { assigneeId: 9, kind: "review", dueOn: "2026-05-01" }] },
      { rule: "all", steps: [] },
    ];
    expect(validateRoute(stages, [2], today)).toEqual([
      "Stage 1: choose a person for every participant.",
      "Stage 1: approvals and signatures go to your superiors only.",
      "Stage 1: the same person is listed twice.",
      "Stage 1: a due date is in the past.",
      "Stage 2 has no participants.",
    ]);
    expect(validateRoute([], [], today)).toEqual(["Add at least one stage."]);
  });
});

describe("payload and template", () => {
  it("builds the API payload, leaving out empty optional values", () => {
    expect(submitPayload([{ rule: "any", steps: [{ assigneeId: 4, kind: "sign", dueOn: "" }, { assigneeId: null, kind: "review", dueOn: "" }] }], "  ")).toEqual({
      stages: [{ rule: "any", steps: [{ assignee_id: 4, kind: "sign" }] }],
    });
    expect(submitPayload([emptyStage()], "Please hurry").comment).toBe("Please hurry");
  });

  it("turns a resolved template into stages", () => {
    expect(stagesFromTemplate([
      { kind: "approve", level: 1, due_on: "2026-06-04", assignee: { id: 2, email: "b@x", position_title: null, unit_name: null } },
      { kind: "sign", level: 5, due_on: null, assignee: null },
    ])).toEqual([
      { rule: "all", steps: [{ assigneeId: 2, kind: "approve", dueOn: "2026-06-04" }] },
      { rule: "all", steps: [{ assigneeId: null, kind: "sign", dueOn: "" }] },
    ]);
  });

  it("labels actions and tones", () => {
    expect(actionLabel("approved_with_comments")).toBe("Approved with comments");
    expect(actionLabel("new_thing")).toBe("new thing");
    expect(stepTone("carried")).toBe("done");
    expect(stepTone("returned")).toBe("attention");
    expect(stepTone("skipped")).toBe("closed");
    expect(stepTone("waiting")).toBe("progress");
  });
});
