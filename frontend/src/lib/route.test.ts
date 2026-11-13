import { describe, expect, it } from "vitest";
import { detailHref, parseDetailRoute, sectionForRoute } from "./route";

describe("detail routes", () => {
  it("parses client and project detail hashes", () => {
    expect(parseDetailRoute("#/clients/12")).toEqual({ kind: "client", id: 12 });
    expect(parseDetailRoute("#/projects/5")).toEqual({ kind: "project", id: 5 });
    expect(parseDetailRoute("#/documents/7")).toEqual({ kind: "document", id: 7 });
  });

  it("ignores everything else", () => {
    for (const hash of ["", "#", "#/clients", "#/clients/0", "#/clients/abc", "#/clients/1/edit", "#/tasks/1", "#/clients/99999999999999999999"]) {
      expect(parseDetailRoute(hash)).toBeNull();
    }
  });

  it("builds hrefs and maps routes to sidebar sections", () => {
    expect(detailHref("client", 3)).toBe("#/clients/3");
    expect(parseDetailRoute(detailHref("project", 8))).toEqual({ kind: "project", id: 8 });
    expect(sectionForRoute({ kind: "project", id: 1 })).toBe("Projects");
    expect(sectionForRoute({ kind: "client", id: 1 })).toBe("Clients");
    expect(detailHref("document", 4)).toBe("#/documents/4");
    expect(sectionForRoute({ kind: "document", id: 4 })).toBe("Documents");
    expect(detailHref("acknowledgement", 6)).toBe("#/acknowledgements/6");
    expect(parseDetailRoute("#/acknowledgements/6")).toEqual({ kind: "acknowledgement", id: 6 });
    expect(sectionForRoute({ kind: "acknowledgement", id: 6 })).toBe("Documents");
  });
});
