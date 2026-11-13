export type RecordRevision = {
  id: number;
  record_type: string;
  record_id: number;
  action: "create" | "update" | "delete";
  snapshot: Record<string, unknown>;
  changes: Record<string, [unknown, unknown]> | null;
  actor: string;
  created_at: string;
  cause?: { record_type: string; record_id: number } | null;
};

export function revisionLabel(revision: RecordRevision): string {
  const name = String(revision.snapshot.title ?? revision.snapshot.name ?? revision.snapshot.sku ?? `#${revision.record_id}`);
  return `${revision.record_type.replaceAll("_", " ")} · ${name}`;
}

export function changeSummary(revision: RecordRevision): string {
  if (revision.action === "create") return "Created";
  const cause = revision.cause ? `${revision.cause.record_type.replaceAll("_", " ")} #${revision.cause.record_id}` : null;
  if (revision.action === "delete") return cause ? `Deleted with ${cause}` : "Deleted";
  if (cause) return `Detached when ${cause} was deleted`;
  const fields = Object.keys(revision.changes ?? {}).filter((field) => field !== "updatedAt");
  return fields.length ? `Changed ${fields.join(", ")}` : "Updated";
}

export function restoreWarning(revision: RecordRevision): string {
  return revision.action === "delete"
    ? "Recreate this deleted record from its last saved state? It will receive a new ID. Contacts, deals, milestones and links that were removed with it are brought back too; activity, time entries and documents are not."
    : "Roll this record back to the state saved by this revision? The rollback is recorded as a new revision.";
}

export function restoreNotice(result: { record_id: number; recreated: boolean; related_restored?: number }): string {
  if (!result.recreated) return `Record #${result.record_id} rolled back.`;
  const related = result.related_restored ?? 0;
  return related > 0
    ? `Record recreated as #${result.record_id}, with ${related} related record${related === 1 ? "" : "s"} restored.`
    : `Record recreated as #${result.record_id}.`;
}
