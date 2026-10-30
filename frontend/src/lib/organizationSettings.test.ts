import { afterEach, describe, expect, it, vi } from "vitest";
import { getOrganizationDefaultCurrency, setOrganizationDefaultCurrency } from "./organizationSettings";

describe("organization currency defaults", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("uses a supported saved default currency", () => {
    vi.stubGlobal("localStorage", {
      getItem: vi.fn(() => "GBP"),
      setItem: vi.fn(),
      removeItem: vi.fn(),
    });

    expect(getOrganizationDefaultCurrency()).toBe("GBP");
  });

  it("falls back to EUR and removes unsupported configured currencies", () => {
    const storage = {
      getItem: vi.fn(() => "JPY"),
      setItem: vi.fn(),
      removeItem: vi.fn(),
    };
    vi.stubGlobal("localStorage", storage);

    expect(getOrganizationDefaultCurrency()).toBe("EUR");
    setOrganizationDefaultCurrency("JPY");
    expect(storage.removeItem).toHaveBeenCalledWith("crm_default_currency");
  });
});
