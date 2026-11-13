import { describe, expect, it } from "vitest";
import { documentQuery, emptyFilters, eventLabel, fieldLabel, formatBytes, parseTags, statusTone } from "./documents";

describe("documentQuery", () => {
  it("leaves out empty filters and always pages", () => {
    expect(documentQuery(emptyFilters(), 50, 0)).toBe("limit=50&offset=0");
  });

  it("encodes every filter", () => {
    const query = new URLSearchParams(documentQuery({ q: "  price list ", typeId: 3, status: "in_force", ownerUnitId: 7, tag: " Q3 ", mine: true, from: "2026-01-01", to: "2026-12-31" }, 25, 50));
    expect(Object.fromEntries(query)).toEqual({ q: "price list", type_id: "3", status: "in_force", owner_unit_id: "7", tag: "q3", mine: "1", from: "2026-01-01", to: "2026-12-31", limit: "25", offset: "50" });
  });
});

describe("labels and formatting", () => {
  it("formats sizes, tones, events and fields", () => {
    expect(formatBytes(512)).toBe("512 B");
    expect(formatBytes(1536)).toBe("1.5 KB");
    expect(formatBytes(10485760)).toBe("10 MB");
    expect(statusTone("in_force")).toBe("done");
    expect(statusTone("rejected")).toBe("attention");
    expect(statusTone("archived")).toBe("closed");
    expect(statusTone("draft")).toBe("progress");
    expect(eventLabel("version_added")).toBe("New version");
    expect(eventLabel("something_new")).toBe("something new");
    expect(fieldLabel("owner_unit")).toBe("Owner unit");
    expect(fieldLabel("other")).toBe("other");
  });

  it("parses comma-separated tags", () => {
    expect(parseTags(" Finance, q3,,finance ")).toEqual(["finance", "q3"]);
    expect(parseTags("")).toEqual([]);
  });
});
