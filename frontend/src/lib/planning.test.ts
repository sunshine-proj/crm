import { describe, expect, it } from "vitest";
import { isValidDateRange, isValidDateTimeRange } from "./planning";

describe("project planned dates", () => {
  it("allows missing dates and same-day ranges", () => {
    expect(isValidDateRange(null, "2026-10-01")).toBe(true);
    expect(isValidDateRange("2026-10-01", "2026-10-01")).toBe(true);
  });

  it("rejects an end date before the start date", () => {
    expect(isValidDateRange("2026-10-02", "2026-10-01")).toBe(false);
  });

  it("rejects malformed date values", () => {
    expect(isValidDateRange("not-a-date", "2026-10-01")).toBe(false);
  });
});

describe("task scheduled dates", () => {
  it("allows equal start and end times", () => {
    expect(isValidDateTimeRange("2026-10-01T09:00", "2026-10-01T09:00")).toBe(true);
  });

  it("rejects an end time before the start time", () => {
    expect(isValidDateTimeRange("2026-10-01T10:00", "2026-10-01T09:00")).toBe(false);
  });

  it("rejects malformed date-time values", () => {
    expect(isValidDateTimeRange("invalid", "2026-10-01T09:00")).toBe(false);
  });
});