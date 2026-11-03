export type DetailRoute = { kind: "client" | "project"; id: number };

const patterns: { kind: DetailRoute["kind"]; pattern: RegExp }[] = [
  { kind: "client", pattern: /^#\/clients\/(\d+)$/ },
  { kind: "project", pattern: /^#\/projects\/(\d+)$/ },
];

/** Parses `#/clients/12` and `#/projects/5`; anything else is the normal section view. */
export function parseDetailRoute(hash: string): DetailRoute | null {
  for (const { kind, pattern } of patterns) {
    const match = pattern.exec(hash);
    if (match) {
      const id = Number(match[1]);
      if (Number.isSafeInteger(id) && id > 0) return { kind, id };
    }
  }
  return null;
}

export function detailHref(kind: DetailRoute["kind"], id: number): string {
  return `#/${kind === "client" ? "clients" : "projects"}/${id}`;
}

/** The sidebar section a detail route belongs to. */
export function sectionForRoute(route: DetailRoute): string {
  return route.kind === "client" ? "Clients" : "Projects";
}
