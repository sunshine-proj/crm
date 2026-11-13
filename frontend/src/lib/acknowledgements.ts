// Acknowledgement (familiarization) display and selection helpers. The API enforces every rule (who may send to
// which units, personal confirmation, opening before confirming); these helpers only shape what the screens show.

import type { OrgUnit, Person } from "./org";

export type RecipientStatus = "pending" | "viewed" | "acknowledged" | "excused";
export type RecipientFilter = "all" | "open" | "overdue" | RecipientStatus;
export type TargetKind = "unit" | "position" | "user";

export type AckCounts = {
  total: number;
  pending: number;
  viewed: number;
  acknowledged: number;
  excused: number;
  open: number;
  overdue: number;
  late: number;
  completion_percent: number;
};

export type AckTarget = { kind: TargetKind; id: number; include_subunits: boolean; label: string };

export type AckRequest = {
  id: number;
  document: { id: number; title: string; type: string; registration_number: string | null; status: string; confidentiality: string };
  version_number: number;
  version_sha256: string;
  sender: string;
  message: string | null;
  due_on: string | null;
  require_open: boolean;
  auto_add_joiners: boolean;
  targets: AckTarget[];
  status: "open" | "closed";
  is_overdue: boolean;
  previous_id: number | null;
  created_at: string;
  closed_at: string | null;
  closed_by: string | null;
  counts: AckCounts;
  is_partial?: boolean;
};

export type AckRecipient = {
  id: number;
  user: { id: number; email: string };
  unit: { id: number | null; name: string } | null;
  position: string | null;
  source: "sent" | "added" | "joined";
  added_by: string;
  status: RecipientStatus;
  is_overdue: boolean;
  is_late: boolean;
  viewed_at: string | null;
  acknowledged_at: string | null;
  acknowledged_sha256: string | null;
  excused_at: string | null;
  excused_by: string | null;
  excuse_reason: string | null;
  last_reminded_at: string | null;
  reminder_count: number;
  created_at: string;
  can_remind: boolean;
  can_excuse: boolean;
  can_reinstate: boolean;
};

/** A row of the person's own inbox or history. */
export type AckMine = AckRecipient & {
  request: Omit<AckRequest, "counts" | "targets" | "is_overdue">;
  can_acknowledge: boolean;
  needs_opening: boolean;
};

export type AckUnitRow = { unit_id: number | null; parent_id: number | null; name: string; depth: number; own: AckCounts; total: AckCounts };

export type AckBoard = AckRequest & {
  is_partial: boolean;
  units: AckUnitRow[];
  recipients: AckRecipient[];
  permissions: { can_control: boolean; can_reissue: boolean; can_remind: boolean };
};

export type AckDocumentView = { can_send: boolean; mine: AckMine[]; requests: AckRequest[] };

export type AckAudience = {
  organization_wide: boolean;
  units: OrgUnit[];
  positions: { id: number; title: string; is_head: boolean; unit_id: number; unit_name: string; holder_count: number }[];
  people: Person[];
};

/** What the sender ticked in the picker. A unit maps to whether its sub-units are included. */
export type AckSelection = { units: Record<number, boolean>; positions: number[]; people: number[] };

export const recipientStatusLabels: Record<RecipientStatus, string> = {
  pending: "Not opened",
  viewed: "Opened",
  acknowledged: "Acknowledged",
  excused: "Excused",
};

export const filterLabels: Record<RecipientFilter, string> = {
  all: "Everyone",
  open: "Still to confirm",
  overdue: "Overdue",
  pending: "Not opened",
  viewed: "Opened",
  acknowledged: "Acknowledged",
  excused: "Excused",
};

export function emptySelection(): AckSelection {
  return { units: {}, positions: [], people: [] };
}

export function selectionSize(selection: AckSelection): number {
  return Object.keys(selection.units).length + selection.positions.length + selection.people.length;
}

/** The API's `targets` payload for a selection. */
export function targetsPayload(selection: AckSelection): { kind: TargetKind; id: number; include_subunits?: boolean }[] {
  return [
    ...Object.entries(selection.units).map(([id, includeSubunits]) => ({ kind: "unit" as const, id: Number(id), include_subunits: includeSubunits })),
    ...selection.positions.map((id) => ({ kind: "position" as const, id })),
    ...selection.people.map((id) => ({ kind: "user" as const, id })),
  ];
}

export function filterRecipients(rows: AckRecipient[], filter: RecipientFilter): AckRecipient[] {
  if (filter === "all") return rows;
  if (filter === "open") return rows.filter((row) => row.status === "pending" || row.status === "viewed");
  if (filter === "overdue") return rows.filter((row) => row.is_overdue);
  return rows.filter((row) => row.status === filter);
}

/** How many rows each filter would show, for the filter buttons. */
export function filterCounts(rows: AckRecipient[]): Record<RecipientFilter, number> {
  const result = {} as Record<RecipientFilter, number>;
  for (const filter of Object.keys(filterLabels) as RecipientFilter[]) result[filter] = filterRecipients(rows, filter).length;
  return result;
}

/** "2 of 5 acknowledged · 1 excused · 1 overdue" */
export function progressText(counts: AckCounts): string {
  const parts = [`${counts.acknowledged} of ${counts.total} acknowledged`];
  if (counts.excused) parts.push(`${counts.excused} excused`);
  if (counts.overdue) parts.push(`${counts.overdue} overdue`);
  return parts.join(" · ");
}

export function statusTone(row: Pick<AckRecipient, "status" | "is_overdue">): string {
  if (row.status === "acknowledged") return "dms-tone-done";
  if (row.status === "excused") return "dms-tone-closed";
  return row.is_overdue ? "dms-tone-attention" : "dms-tone-progress";
}

/** People in the audience matching a search over e-mail, position and unit. */
export function searchPeople(people: Person[], query: string): Person[] {
  const words = query.toLowerCase().split(/\s+/).filter(Boolean);
  if (words.length === 0) return people;
  return people.filter((person) => {
    const text = `${person.email} ${person.position_title ?? ""} ${person.unit_name ?? ""}`.toLowerCase();
    return words.every((word) => text.includes(word));
  });
}

/** Progress bar width, clamped to 0–100%. */
export function barWidth(counts: AckCounts): string {
  return `${Math.max(0, Math.min(100, counts.completion_percent))}%`;
}

/** Recipients recorded under the unit or any unit below it in the status tree; `null` selects people without a unit. */
export function recipientsInUnit(rows: AckRecipient[], units: AckUnitRow[], unitId: number | null): AckRecipient[] {
  if (unitId === null) return rows.filter((row) => !row.unit?.id);
  const ids = new Set<number>([unitId]);
  let grew = true;
  while (grew) {
    grew = false;
    for (const unit of units) {
      if (unit.unit_id !== null && unit.parent_id !== null && ids.has(unit.parent_id) && !ids.has(unit.unit_id)) {
        ids.add(unit.unit_id);
        grew = true;
      }
    }
  }
  return rows.filter((row) => row.unit?.id != null && ids.has(row.unit.id));
}
