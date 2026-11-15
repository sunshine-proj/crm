import { afterEach, describe, expect, it, vi } from "vitest";
import { hasModule, moduleStates, presetDiff, resetModules, sectionVisible, toggleBlocker, whenModule, type ModuleKey, type ModuleState } from "./modules";

function state(key: ModuleKey, fields: Partial<ModuleState> = {}): ModuleState {
  return { key, label: key, description: "", requires: [], required_by: [], enabled: true, available: true, allowed_roles: [], settings: {}, settings_schema: {}, updated_at: null, updated_by: null, ...fields };
}

const states = [
  state("projects", { required_by: ["time_tracking", "project_operations"] }),
  state("time_tracking", { requires: ["projects"] }),
  state("project_operations", { requires: ["projects"], enabled: false, available: false }),
  state("accounting", { available: false, allowed_roles: ["ROLE_FINANCE"] }),
];

describe("module visibility", () => {
  afterEach(() => resetModules());

  it("treats everything as available until the configuration is loaded", () => {
    expect(hasModule("accounting", null)).toBe(true);
    expect(sectionVisible("Finance", null)).toBe(true);
  });

  it("follows availability, which already combines the switch and role restrictions", () => {
    expect(hasModule("projects", states)).toBe(true);
    expect(hasModule("project_operations", states)).toBe(false);
    expect(hasModule("accounting", states)).toBe(false);
    expect(sectionVisible("Finance", states)).toBe(false);
    expect(sectionVisible("Operations", states), "any one module is enough").toBe(true);
    expect(sectionVisible("Settings", states), "core sections are always shown").toBe(true);
    expect(sectionVisible("Clients", [state("clients", { enabled: false, available: false })])).toBe(false);
  });

  it("skips requests to unavailable modules", async () => {
    moduleStates.value = states;
    const request = vi.fn(() => Promise.resolve(["called"]));
    expect(await whenModule("accounting", request, [])).toEqual([]);
    expect(request).not.toHaveBeenCalled();
    expect(await whenModule("projects", request, [])).toEqual(["called"]);
  });
});

describe("administration helpers", () => {
  it("explains why a switch is blocked by dependencies", () => {
    expect(toggleBlocker(states[0], states)).toBe("Turn off time_tracking first.");
    expect(toggleBlocker(states[1], states)).toBeNull();
    expect(toggleBlocker(state("time_tracking", { enabled: false, requires: ["projects"] }), [state("projects", { enabled: false })])).toBe("Turn on projects first.");
  });

  it("previews a preset", () => {
    expect(presetDiff(["projects", "project_operations"], states)).toEqual({ on: ["project_operations"], off: ["time_tracking", "accounting"] });
  });
});
