import { ref } from "vue";
import { apiRequest } from "./api";

/** Module configuration of this installation, as returned by GET /api/settings/modules. The API enforces it; the UI only hides. */

export type ModuleKey =
  | "clients" | "news" | "sales_pipeline" | "projects" | "time_tracking" | "project_operations" | "sales" | "inventory"
  | "invoices" | "accounting" | "bank" | "organization_structure" | "document_management";

export type SettingDefinition = { label: string; type: "int" | "bool"; min?: number; max?: number; default: number | boolean };

export type ModuleState = {
  key: ModuleKey;
  label: string;
  description: string;
  requires: ModuleKey[];
  required_by: ModuleKey[];
  enabled: boolean;
  available: boolean;
  allowed_roles: string[];
  settings: Record<string, number | boolean>;
  settings_schema: Record<string, SettingDefinition>;
  updated_at: string | null;
  updated_by: string | null;
};

export type ModulesOverview = {
  modules: ModuleState[];
  restrictable_roles: string[];
  presets: { key: string; label: string; modules: ModuleKey[] }[];
};

/** Sidebar sections and the modules that make them useful (any one is enough). Sections not listed are core. */
export const sectionModules: Record<string, ModuleKey[]> = {
  Clients: ["clients"],
  News: ["news"],
  Deals: ["sales_pipeline"],
  Projects: ["projects"],
  "Project Management": ["projects"],
  Tasks: ["projects"],
  Timesheet: ["time_tracking"],
  "Time Report": ["time_tracking"],
  Sales: ["sales"],
  Inventory: ["inventory"],
  Operations: ["time_tracking", "project_operations"],
  Invoices: ["invoices"],
  Finance: ["accounting"],
  Bank: ["bank"],
  Documents: ["document_management"],
  Organization: ["organization_structure"],
};

export const detailRouteModules: Record<string, ModuleKey | null> = {
  client: "clients",
  project: "projects",
  document: "document_management",
  acknowledgement: "document_management",
  timeReport: "time_tracking",
};

export const roleLabels: Record<string, string> = { ROLE_FINANCE: "Finance", ROLE_INVENTORY: "Inventory", ROLE_DMS_ADMIN: "Registry clerk" };

/** Loaded module states; null until loaded (or when loading failed), in which case nothing is hidden. */
export const moduleStates = ref<ModuleState[] | null>(null);

export async function loadModules(): Promise<ModulesOverview> {
  const overview = await apiRequest<ModulesOverview>("/settings/modules", {}, "Unable to load the module configuration.");
  moduleStates.value = overview.modules;
  return overview;
}

export function resetModules(): void {
  moduleStates.value = null;
}

/** Enabled and visible to the signed-in user. Before the configuration is known everything counts as available. */
export function hasModule(key: ModuleKey, states: ModuleState[] | null = moduleStates.value): boolean {
  if (states === null) return true;
  return states.find((state) => state.key === key)?.available ?? true;
}

export function sectionVisible(section: string, states: ModuleState[] | null = moduleStates.value): boolean {
  const modules = sectionModules[section];
  return modules === undefined || modules.some((key) => hasModule(key, states));
}

/** Runs the request only when the module is available; otherwise resolves to the fallback without calling the API. */
export function whenModule<T>(key: ModuleKey, request: () => Promise<T>, fallback: T): Promise<T> {
  return hasModule(key) ? request() : Promise.resolve(fallback);
}

/** Why an administrator cannot switch the module right now, or null when the switch is allowed. */
export function toggleBlocker(module: ModuleState, states: ModuleState[]): string | null {
  const label = (key: ModuleKey) => states.find((state) => state.key === key)?.label ?? key;
  if (module.enabled) {
    const blocking = module.required_by.filter((key) => states.find((state) => state.key === key)?.enabled);
    return blocking.length ? `Turn off ${blocking.map(label).join(", ")} first.` : null;
  }
  const missing = module.requires.filter((key) => !states.find((state) => state.key === key)?.enabled);
  return missing.length ? `Turn on ${missing.map(label).join(", ")} first.` : null;
}

/** Modules a preset would turn on and off compared with the current state. */
export function presetDiff(presetModules: ModuleKey[], states: ModuleState[]): { on: string[]; off: string[] } {
  return {
    on: states.filter((state) => !state.enabled && presetModules.includes(state.key)).map((state) => state.label),
    off: states.filter((state) => state.enabled && !presetModules.includes(state.key)).map((state) => state.label),
  };
}
