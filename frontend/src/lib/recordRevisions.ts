export type RecordRevision = {
  id: number;
  record_type: string;
  record_id: number;
  action: "create" | "update" | "delete";
  snapshot: Record<string, unknown>;
  changes: Record<string, [unknown, unknown]> | null;
  actor: string;
  created_at: string;
};

export function revisionLabel(revision: RecordRevision): string {
  const name = String(revision.snapshot.title ?? revision.snapshot.name ?? revision.snapshot.sku ?? `#${revision.record_id}`);
  return `${revision.record_type.replaceAll("_", " ")} · ${name}`;
}

export function changeSummary(revision: RecordRevision): string {
  if (revision.action === "create") return "Created";
  if (revision.action === "delete") return "Deleted";
  const fields = Object.keys(revision.changes ?? {}).filter((field) => field !== "updatedAt");
  return fields.length ? `Changed ${fields.join(", ")}` : "Updated";
}

export function restoreWarning(revision: RecordRevision): string {
  return revision.action === "delete"
    ? "Recreate this deleted record from its last saved state? It will receive a new ID, and records that were deleted along with it are not restored."
    : "Roll this record back to the state saved by this revision? The rollback is recorded as a new revision.";
}
