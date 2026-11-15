<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { loadModules, presetDiff, roleLabels, toggleBlocker, type ModuleKey, type ModulesOverview, type ModuleState } from "../lib/modules";

type ModuleChange = {
  id: number;
  module: ModuleKey;
  module_label: string;
  previous: { enabled: boolean; allowed_roles: string[]; settings: Record<string, number | boolean> };
  new: { enabled: boolean; allowed_roles: string[]; settings: Record<string, number | boolean> };
  actor: string;
  changed_at: string;
};
type Draft = { allowedRoles: string[]; settings: Record<string, number | boolean> };

const overview = ref<ModulesOverview | null>(null);
const drafts = ref<Record<string, Draft>>({});
const history = ref<ModuleChange[]>([]);
const preset = ref("");
const busyKey = ref<string | null>(null);
const errorMessage = ref("");
const savedMessage = ref("");
const isLoading = ref(true);

const modules = computed(() => overview.value?.modules ?? []);
const selectedPreset = computed(() => overview.value?.presets.find((item) => item.key === preset.value) ?? null);
const presetPreview = computed(() => (selectedPreset.value ? presetDiff(selectedPreset.value.modules, modules.value) : null));

function resetDrafts() {
  drafts.value = Object.fromEntries(modules.value.map((module) => [module.key, { allowedRoles: [...module.allowed_roles], settings: { ...module.settings } }]));
}

async function refresh() {
  overview.value = await loadModules();
  history.value = await apiRequest<ModuleChange[]>("/settings/modules/history");
  resetDrafts();
}

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    await refresh();
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load the modules.");
  } finally {
    isLoading.value = false;
  }
}

async function run(key: string, action: () => Promise<unknown>, success: string) {
  busyKey.value = key;
  errorMessage.value = "";
  savedMessage.value = "";
  try {
    await action();
    await refresh();
    savedMessage.value = success;
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to change the module.");
  } finally {
    busyKey.value = null;
  }
}

function toggle(module: ModuleState) {
  const enabling = !module.enabled;
  if (!enabling && !window.confirm(`Turn off ${module.label}? Its pages and API are blocked for everyone; the data stays and comes back when you turn it on again.`)) return;
  void run(module.key, () => apiRequest(`/settings/modules/${module.key}`, { method: "PUT", body: JSON.stringify({ enabled: enabling }) }), `${module.label} turned ${enabling ? "on" : "off"}.`);
}

function saveDetails(module: ModuleState) {
  const draft = drafts.value[module.key];
  void run(module.key, () => apiRequest(`/settings/modules/${module.key}`, { method: "PUT", body: JSON.stringify({ allowed_roles: draft.allowedRoles, settings: draft.settings }) }), `${module.label} saved.`);
}

function isDirty(module: ModuleState) {
  const draft = drafts.value[module.key];
  if (!draft) return false;
  return JSON.stringify([...draft.allowedRoles].sort()) !== JSON.stringify([...module.allowed_roles].sort()) || JSON.stringify(draft.settings) !== JSON.stringify(module.settings);
}

function applyPreset() {
  const chosen = selectedPreset.value;
  const preview = presetPreview.value;
  if (!chosen || !preview) return;
  const summary = [preview.on.length ? `Turn on: ${preview.on.join(", ")}.` : "", preview.off.length ? `Turn off: ${preview.off.join(", ")}.` : ""].filter(Boolean).join("\n");
  if (!window.confirm(`Apply "${chosen.label}"?\n${summary || "No change."}\nData of turned-off modules is kept.`)) return;
  void run("preset", () => apiRequest(`/settings/modules/presets/${chosen.key}`, { method: "POST" }), `"${chosen.label}" applied.`);
}

function restore(change: ModuleChange) {
  void run(`history-${change.id}`, () => apiRequest(`/settings/modules/history/${change.id}/restore`, { method: "POST" }), `${change.module_label} restored.`);
}

function describe(state: ModuleChange["new"]) {
  const roles = state.allowed_roles.length ? state.allowed_roles.map((role) => roleLabels[role] ?? role).join(", ") : "everyone";
  return `${state.enabled ? "On" : "Off"} · ${roles}`;
}

function formatDate(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

onMounted(load);
</script>

<template>
  <section class="settings-card" aria-labelledby="modules-heading">
    <div class="settings-card-heading">
      <div>
        <p class="card-kicker">Modules</p>
        <h2 id="modules-heading">Functionality for this organization</h2>
      </div>
    </div>
    <p class="muted">Turn modules on or off and choose which roles see them. Turning a module off hides its pages and blocks its API for everyone, including administrators; its data stays in the database. Administrators always see enabled modules.</p>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="savedMessage" class="success" role="status">{{ savedMessage }}</p>
    <p v-if="isLoading" class="muted">Loading modules...</p>

    <template v-else-if="overview">
      <div class="pm-filters module-presets">
        <div>
          <label for="module-preset">Preset</label>
          <select id="module-preset" v-model="preset">
            <option value="">Choose a preset</option>
            <option v-for="item in overview.presets" :key="item.key" :value="item.key">{{ item.label }}</option>
          </select>
        </div>
        <button class="edit-button" type="button" :disabled="!preset || busyKey !== null" @click="applyPreset">Apply preset</button>
        <p v-if="presetPreview" class="muted">
          <template v-if="presetPreview.on.length">Turns on {{ presetPreview.on.join(", ") }}. </template>
          <template v-if="presetPreview.off.length">Turns off {{ presetPreview.off.join(", ") }}.</template>
          <template v-if="!presetPreview.on.length && !presetPreview.off.length">Matches the current setup.</template>
        </p>
      </div>

      <ul class="module-list">
        <li v-for="module in modules" :key="module.key" :class="{ 'is-off': !module.enabled }">
          <div class="module-header">
            <div>
              <h3>{{ module.label }}</h3>
              <p class="muted">{{ module.description }}</p>
              <p v-if="module.requires.length" class="module-meta">Needs {{ module.requires.map((key) => modules.find((item) => item.key === key)?.label ?? key).join(", ") }}</p>
            </div>
            <button
              class="module-switch"
              type="button"
              role="switch"
              :aria-checked="module.enabled"
              :aria-label="`${module.label} ${module.enabled ? 'on' : 'off'}`"
              :aria-describedby="toggleBlocker(module, modules) ? `module-blocker-${module.key}` : undefined"
              :disabled="busyKey !== null || toggleBlocker(module, modules) !== null"
              @click="toggle(module)"
            >
              <span aria-hidden="true"></span>{{ module.enabled ? "On" : "Off" }}
            </button>
          </div>
          <p v-if="toggleBlocker(module, modules)" :id="`module-blocker-${module.key}`" class="module-meta">{{ toggleBlocker(module, modules) }}</p>

          <fieldset v-if="drafts[module.key]" class="module-details" :disabled="busyKey !== null">
            <legend>Visible to</legend>
            <label v-for="role in overview.restrictable_roles" :key="role">
              <input v-model="drafts[module.key].allowedRoles" type="checkbox" :value="role" /> {{ roleLabels[role] ?? role }}
            </label>
            <p class="module-meta">{{ drafts[module.key].allowedRoles.length ? "Only these roles and administrators." : "Everyone who can sign in." }}</p>

            <div v-for="(definition, name) in module.settings_schema" :key="name" class="module-setting">
              <label v-if="definition.type === 'bool'">
                <input v-model="drafts[module.key].settings[name]" type="checkbox" /> {{ definition.label }}
              </label>
              <template v-else>
                <label :for="`module-${module.key}-${name}`">{{ definition.label }}</label>
                <input :id="`module-${module.key}-${name}`" v-model.number="drafts[module.key].settings[name]" type="number" :min="definition.min" :max="definition.max" step="1" />
              </template>
            </div>
            <button v-if="isDirty(module)" class="edit-button" type="button" @click="saveDetails(module)">{{ busyKey === module.key ? "Saving..." : "Save changes" }}</button>
          </fieldset>
          <p v-if="module.updated_by" class="module-meta">Last changed {{ formatDate(module.updated_at!) }} by {{ module.updated_by }}</p>
        </li>
      </ul>

      <h3>Change history</h3>
      <p v-if="history.length === 0" class="muted">No module changes have been recorded. Every module runs with its defaults.</p>
      <ol v-else class="settings-history">
        <li v-for="change in history" :key="change.id">
          <div>
            <strong>{{ change.module_label }}: {{ describe(change.previous) }} → {{ describe(change.new) }}</strong>
            <span>{{ formatDate(change.changed_at) }} by {{ change.actor }}</span>
          </div>
          <button class="settings-restore" type="button" :disabled="busyKey !== null" @click="restore(change)">Restore previous</button>
        </li>
      </ol>
    </template>
  </section>
</template>
