// Organization structure helpers shared by the Organization page (and later the document workflows).
// The API is authoritative for every rule; these helpers only shape data and pre-check moves for UI feedback.

export type OrgUnitKind = "organization" | "unit" | "section";

export type OrgUnit = {
  id: number;
  parent_id: number | null;
  code: string;
  name: string;
  kind: OrgUnitKind;
  depth: number;
  head: { id: number; email: string } | null;
  member_count: number;
  archived_at: string | null;
};

export type Person = { id: number; email: string; position_title: string | null; unit_id: number | null; unit_name: string | null };

export type OrgTreeRow = { unit: OrgUnit; level: number; hasChildren: boolean };

export const unitKinds: { kind: OrgUnitKind; label: string }[] = [
  { kind: "organization", label: "Organization" },
  { kind: "unit", label: "Unit" },
  { kind: "section", label: "Section" },
];

function byName(a: OrgUnit, b: OrgUnit): number {
  return a.name.localeCompare(b.name) || a.id - b.id;
}

/** Flattens units into depth-first order with indentation levels; orphans (missing parent) are shown as roots. */
export function treeRows(units: OrgUnit[], includeArchived = false): OrgTreeRow[] {
  const visible = units.filter((unit) => includeArchived || !unit.archived_at);
  const ids = new Set(visible.map((unit) => unit.id));
  const children = new Map<number | null, OrgUnit[]>();
  for (const unit of visible) {
    const parent = unit.parent_id !== null && ids.has(unit.parent_id) ? unit.parent_id : null;
    children.set(parent, [...(children.get(parent) ?? []), unit]);
  }
  const rows: OrgTreeRow[] = [];
  const seen = new Set<number>();
  const walk = (parent: number | null, level: number) => {
    for (const unit of [...(children.get(parent) ?? [])].sort(byName)) {
      if (seen.has(unit.id)) continue;
      seen.add(unit.id);
      rows.push({ unit, level, hasChildren: (children.get(unit.id) ?? []).length > 0 });
      walk(unit.id, level + 1);
    }
  };
  walk(null, 0);
  return rows;
}

/** Ids of the unit and every unit below it. */
export function subtreeIds(units: OrgUnit[], unitId: number): Set<number> {
  const result = new Set<number>([unitId]);
  let grew = true;
  while (grew) {
    grew = false;
    for (const unit of units) {
      if (unit.parent_id !== null && result.has(unit.parent_id) && !result.has(unit.id)) {
        result.add(unit.id);
        grew = true;
      }
    }
  }
  return result;
}

/** Why a unit cannot be placed under the target, or null when the move looks valid (the API re-checks). */
export function moveProblem(units: OrgUnit[], unitId: number, targetId: number, maxDepth = 8): string | null {
  const unit = units.find((candidate) => candidate.id === unitId);
  const target = units.find((candidate) => candidate.id === targetId);
  if (!unit || !target) return "Unit not found.";
  if (unit.kind === "organization") return "The organization stays at the top.";
  if (subtreeIds(units, unitId).has(targetId)) return "A unit cannot be moved under itself or its own sub-units.";
  if (target.archived_at) return "A unit cannot be placed under an archived unit.";
  if (unit.parent_id === targetId) return "The unit is already there.";
  const subtree = subtreeIds(units, unitId);
  const height = Math.max(...units.filter((candidate) => subtree.has(candidate.id)).map((candidate) => candidate.depth)) - unit.depth + 1;
  if (target.depth + height > maxDepth) return `The structure is limited to ${maxDepth} levels.`;
  return null;
}

/** Breadcrumb from the top of the structure down to the unit. */
export function unitPath(units: OrgUnit[], unitId: number): OrgUnit[] {
  const byId = new Map(units.map((unit) => [unit.id, unit]));
  const path: OrgUnit[] = [];
  for (let current = byId.get(unitId); current && path.length <= units.length; current = current.parent_id === null ? undefined : byId.get(current.parent_id)) {
    path.unshift(current);
  }
  return path;
}

export function personLabel(person: Pick<Person, "email" | "position_title">): string {
  return person.position_title ? `${person.email} · ${person.position_title}` : person.email;
}
