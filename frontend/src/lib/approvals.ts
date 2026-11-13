// Approval display and route-builder helpers. The API enforces every rule (subordination, self-approval, dates);
// validateRoute only gives early feedback in the builder.

export type StepKind = "review" | "approve" | "sign" | "consult";
export type StepStatus = "waiting" | "pending" | "approved" | "rejected" | "returned" | "reviewed" | "skipped" | "cancelled" | "carried";
export type StageRule = "all" | "any";

export type ApprovalStep = {
  id: number;
  parent_id: number | null;
  kind: StepKind;
  status: StepStatus;
  assignee: { id: number; email: string; position: string | null; unit: string | null };
  due_on: string | null;
  is_overdue: boolean;
  opened_at: string | null;
  decided_at: string | null;
  decided_by: string | null;
  on_behalf_of: string | null;
  comment: string | null;
  added_by: string;
  can_act: boolean;
  acting_for: string | null;
};

export type ApprovalStage = { id: number; position: number; rule: StageRule; status: string; activated_at: string | null; completed_at: string | null; steps: ApprovalStep[] };

export type ApprovalRound = {
  id: number;
  round: number;
  status: "active" | "approved" | "rejected" | "returned" | "withdrawn";
  version_number: number;
  version_sha256: string;
  created_by: string;
  created_at: string;
  completed_at: string | null;
  stages: ApprovalStage[];
  actions: { id: number; action: string; actor: string; on_behalf_of: string | null; comment: string | null; step_id: number | null; version_number: number; created_at: string }[];
};

export type ApprovalHistory = { document_id: number; document_status: string; permissions: Record<string, boolean>; rounds: ApprovalRound[] };

export type InboxRow = ApprovalStep & {
  document: { id: number; title: string; type: string; author: string; confidentiality: string };
  round: number;
  stage_position: number;
  stage_count: number;
  requested_by: string | null;
};

export type CandidatePerson = { id: number; email: string; position_title: string | null; unit_name: string | null; level?: number };

export type ApprovalCandidates = {
  superiors: (CandidatePerson & { level: number })[];
  colleagues: CandidatePerson[];
  template: { kind: StepKind; level: number; due_on: string | null; assignee: CandidatePerson | null }[];
};

export type DraftStep = { assigneeId: number | null; kind: Exclude<StepKind, "consult">; dueOn: string };
export type DraftStage = { rule: StageRule; steps: DraftStep[] };

export const stepKindLabels: Record<StepKind, string> = { review: "Review", approve: "Approve", sign: "Sign", consult: "Requested review" };

export const stepStatusLabels: Record<StepStatus, string> = {
  waiting: "Waiting",
  pending: "Pending",
  approved: "Approved",
  rejected: "Rejected",
  returned: "Returned",
  reviewed: "Reviewed",
  skipped: "Not needed",
  cancelled: "Cancelled",
  carried: "Approved earlier",
};

export const roundStatusLabels: Record<ApprovalRound["status"], string> = {
  active: "In progress",
  approved: "Approved",
  rejected: "Rejected",
  returned: "Returned for revision",
  withdrawn: "Withdrawn",
};

const actionLabels: Record<string, string> = {
  submitted: "Sent for approval",
  resubmitted: "Sent again",
  approved: "Approved",
  approved_with_comments: "Approved with comments",
  rejected: "Rejected",
  returned: "Returned for revision",
  forwarded: "Asked for a review",
  reviewed: "Gave a review",
  reviewer_added: "Added a reviewer",
  withdrawn: "Withdrawn",
};

export function actionLabel(action: string): string {
  return actionLabels[action] ?? action.replace(/_/g, " ");
}

/** Tone used for step pills, matching the document status tones. */
export function stepTone(status: StepStatus): "progress" | "done" | "attention" | "closed" {
  if (status === "approved" || status === "reviewed" || status === "carried") return "done";
  if (status === "rejected" || status === "returned") return "attention";
  if (status === "pending" || status === "waiting") return "progress";
  return "closed";
}

export function emptyStage(): DraftStage {
  return { rule: "all", steps: [{ assigneeId: null, kind: "approve", dueOn: "" }] };
}

/** Builder stages from the type's resolved template (entries without an assignee are left for the author to fill). */
export function stagesFromTemplate(template: ApprovalCandidates["template"]): DraftStage[] {
  return template.map((entry) => ({ rule: "all", steps: [{ assigneeId: entry.assignee?.id ?? null, kind: entry.kind === "consult" ? "review" : entry.kind, dueOn: entry.due_on ?? "" }] }));
}

/** Problems to fix before sending; empty when the route looks valid. */
export function validateRoute(stages: DraftStage[], superiorIds: number[], today: string): string[] {
  const problems: string[] = [];
  if (stages.length === 0) problems.push("Add at least one stage.");
  stages.forEach((stage, index) => {
    const label = `Stage ${index + 1}`;
    if (stage.steps.length === 0) problems.push(`${label} has no participants.`);
    const seen = new Set<number>();
    for (const step of stage.steps) {
      if (step.assigneeId === null) {
        problems.push(`${label}: choose a person for every participant.`);
        continue;
      }
      if (seen.has(step.assigneeId)) problems.push(`${label}: the same person is listed twice.`);
      seen.add(step.assigneeId);
      if (step.kind !== "review" && !superiorIds.includes(step.assigneeId)) problems.push(`${label}: approvals and signatures go to your superiors only.`);
      if (step.dueOn && step.dueOn < today) problems.push(`${label}: a due date is in the past.`);
    }
  });
  return [...new Set(problems)];
}

export function submitPayload(stages: DraftStage[], comment: string): { stages: { rule: StageRule; steps: { assignee_id: number; kind: string; due_on?: string }[] }[]; comment?: string } {
  return {
    stages: stages.map((stage) => ({
      rule: stage.rule,
      steps: stage.steps
        .filter((step): step is DraftStep & { assigneeId: number } => step.assigneeId !== null)
        .map((step) => ({ assignee_id: step.assigneeId, kind: step.kind, ...(step.dueOn ? { due_on: step.dueOn } : {}) })),
    })),
    ...(comment.trim() ? { comment: comment.trim() } : {}),
  };
}
