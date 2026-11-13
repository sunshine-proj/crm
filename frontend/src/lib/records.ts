// Records-management helpers: audit trail labels and filters, report shaping and duration formatting.
// The API computes every figure; these helpers only label and arrange them.

import { statusLabels, type DocumentStatus } from "./documents";

export type AuditEntry = {
  at: string;
  source: "document" | "approval";
  action: string;
  actor: string;
  on_behalf_of: string | null;
  details: Record<string, unknown>;
};

export type AuditTrail = { entries: AuditEntry[]; total: number; truncated: boolean };
export type AuditFilters = { action: string; actor: string; from: string; to: string };

export type ApprovalTiming = { decided: number; mean_hours: number; median_hours: number; max_hours: number };

export type DmsReport = {
  period: { from: string; to: string };
  documents: { by_status: Partial<Record<DocumentStatus, number>>; rows: { type: string; unit: string | null; status: DocumentStatus; count: number }[]; total: number };
  approval_time: { by_kind: (ApprovalTiming & { kind: string })[]; by_approver: (ApprovalTiming & { approver: string })[] };
  overdue_approvals: { approver: string; overdue: number; oldest_due_on: string; max_days_overdue: number }[];
  acknowledgement: { requests: number; by_unit: { unit: string; total: number; acknowledged: number; excused: number; open: number; overdue: number; completion_percent: number }[] };
};

export type Disposal = {
  id: number;
  document: { id: number; title: string; registration_number: string | null; type: string; status: string };
  status: "proposed" | "approved" | "rejected";
  proposed_by: string;
  proposed_at: string;
  reason: string;
  retain_until: string;
  decided_by: string | null;
  decided_at: string | null;
  decision_comment: string | null;
  manifest: { versions?: { number: number; file: string; sha256: string }[]; attachments?: { file: string; sha256: string }[] };
  can_decide?: boolean;
};

export type EligibleDocument = { id: number; title: string; registration_number: string | null; type: string; archived_at: string | null; retention_years: number | null; retain_until: string | null };

/** Groups of audit actions offered in the filter; the API matches the prefix. */
export const auditActionFilters: { value: string; label: string }[] = [
  { value: "", label: "All actions" },
  { value: "viewed", label: "Views" },
  { value: "downloaded", label: "Downloads" },
  { value: "version", label: "Versions" },
  { value: "readers", label: "Access changes" },
  { value: "approval", label: "Approval" },
  { value: "acknowledg", label: "Acknowledgement" },
  { value: "legal_hold", label: "Legal hold" },
  { value: "disposal", label: "Disposal" },
];

const actionWords: Record<string, string> = {
  viewed: "Viewed",
  downloaded: "Downloaded",
  version_added: "Uploaded a version",
  readers_changed: "Changed access",
  registered: "Registered",
  in_force: "Came into force",
  superseded: "Superseded",
  archived: "Archived",
  reviewed: "Reviewed",
  legal_hold_placed: "Placed a legal hold",
  legal_hold_released: "Released the legal hold",
  disposal_proposed: "Proposed disposal",
  disposal_rejected: "Disposal rejected",
  disposed: "Disposed of",
  acknowledged: "Acknowledged",
};

/** "approval_approved_with_comments" → "Approval: approved with comments"; known actions get a fixed wording. */
export function auditActionLabel(action: string): string {
  if (actionWords[action]) return actionWords[action];
  const words = action.replace(/_/g, " ");
  if (action.startsWith("approval_")) return `Approval: ${words.slice("approval ".length)}`;
  return words.charAt(0).toUpperCase() + words.slice(1);
}

/** Query string for the audit endpoint; empty filters are left out. */
export function auditQuery(filters: AuditFilters, format?: "csv"): string {
  const params = new URLSearchParams();
  for (const key of ["action", "actor", "from", "to"] as const) {
    const value = filters[key].trim();
    if (value) params.set(key, value);
  }
  if (format) params.set("format", format);
  const query = params.toString();
  return query ? `?${query}` : "";
}

/** Audit details as short "key: value" text, skipping empty values. */
export function auditDetails(details: Record<string, unknown>): string {
  return Object.entries(details)
    .filter(([, value]) => value !== null && value !== "" && value !== undefined)
    .map(([key, value]) => `${key.replace(/_/g, " ")}: ${typeof value === "object" ? JSON.stringify(value) : String(value)}`)
    .join(" · ");
}

/** 0.4 h → "24 min", 5.25 h → "5.3 h", 50 h → "2.1 days". */
export function formatDuration(hours: number): string {
  if (hours < 1) return `${Math.round(hours * 60)} min`;
  if (hours < 48) return `${Math.round(hours * 10) / 10} h`;
  return `${Math.round((hours / 24) * 10) / 10} days`;
}

/** The documents-by-type-and-status matrix: one row per type, one column per status that occurs, plus totals. */
export function statusMatrix(rows: DmsReport["documents"]["rows"]): { statuses: DocumentStatus[]; types: { type: string; counts: Partial<Record<DocumentStatus, number>>; total: number }[] } {
  const order = Object.keys(statusLabels) as DocumentStatus[];
  const present = new Set(rows.map((row) => row.status));
  const statuses = order.filter((status) => present.has(status));
  const byType = new Map<string, { type: string; counts: Partial<Record<DocumentStatus, number>>; total: number }>();
  for (const row of rows) {
    const entry = byType.get(row.type) ?? { type: row.type, counts: {}, total: 0 };
    entry.counts[row.status] = (entry.counts[row.status] ?? 0) + row.count;
    entry.total += row.count;
    byType.set(row.type, entry);
  }
  return { statuses, types: [...byType.values()].sort((a, b) => a.type.localeCompare(b.type)) };
}
