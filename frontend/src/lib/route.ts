export type DetailRoute = { kind: "client" | "project" | "document" | "acknowledgement"; id: number };

const patterns: { kind: DetailRoute["kind"]; pattern: RegExp }[] = [
  { kind: "client", pattern: /^#\/clients\/(\d+)$/ },
  { kind: "project", pattern: /^#\/projects\/(\d+)$/ },
  { kind: "document", pattern: /^#\/documents\/(\d+)$/ },
  { kind: "acknowledgement", pattern: /^#\/acknowledgements\/(\d+)$/ },
];

/** Parses `#/clients/12`, `#/projects/5`, `#/documents/7` and `#/acknowledgements/3`; anything else is the normal section view. */
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
  const segments: Record<DetailRoute["kind"], string> = { client: "clients", project: "projects", document: "documents", acknowledgement: "acknowledgements" };
  return `#/${segments[kind]}/${id}`;
}

/** The sidebar section a detail route belongs to. */
export function sectionForRoute(route: DetailRoute): string {
  return route.kind === "client" ? "Clients" : route.kind === "project" ? "Projects" : "Documents";
}
