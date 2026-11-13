<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { isAdminToken } from "../lib/session";
import { moveProblem, personLabel, treeRows, unitKinds, unitPath, type OrgUnit, type OrgUnitKind, type Person } from "../lib/org";
import RecordChangeHistory from "./RecordChangeHistory.vue";

type Holder = { employment_id: number; user_id: number; email: string; is_acting: boolean };
type Position = { id: number; unit_id: number; title: string; is_head: boolean; holders: Holder[]; archived_at: string | null };
type Employment = {
  id: number;
  user_id: number;
  user_email: string;
  position_id: number;
  position_title: string;
  is_head: boolean;
  is_primary: boolean;
  is_acting: boolean;
  starts_on: string;
  ends_on: string | null;
  reports_to_id: number | null;
  reports_to_email: string | null;
  is_active: boolean;
};
type ReportingLine = {
  user: Person;
  unit_path: { id: number; name: string; kind: OrgUnitKind }[];
  superiors: Person[];
  direct_subordinates: Person[];
  subordinate_count: number;
  managed_unit_ids: number[];
  deputy: Person | null;
  acting_for: Person[];
};
type Delegation = {
  id: number;
  principal_id: number;
  principal_email: string;
  deputy_id: number;
  deputy_email: string;
  starts_on: string;
  ends_on: string;
  reason: string | null;
  is_active: boolean;
  revoked_at: string | null;
};

const isAdmin = isAdminToken(localStorage.getItem("crm_token"));
const today = new Date().toISOString().slice(0, 10);

const units = ref<OrgUnit[]>([]);
const people = ref<Person[]>([]);
const me = ref<ReportingLine | null>(null);
const delegations = ref<Delegation[]>([]);
const positions = ref<Position[]>([]);
const employments = ref<Employment[]>([]);
const selectedUnitId = ref<number | null>(null);
const showArchived = ref(false);
const showEnded = ref(false);
const isLoading = ref(true);
const isUnitLoading = ref(false);
const isSaving = ref(false);
const errorMessage = ref("");
const notice = ref("");
const draggingId = ref<number | null>(null);
const dropTargetId = ref<number | null>(null);

// Unit form: editing the selected unit or creating a sub-unit (or the top-level organization).
const unitFormMode = ref<"create" | "edit" | null>(null);
const unitCode = ref("");
const unitName = ref("");
const unitKind = ref<OrgUnitKind>("unit");
const unitParentId = ref<number | null>(null);

const positionFormOpen = ref(false);
const positionTitle = ref("");
const positionIsHead = ref(false);

const employmentFormOpen = ref(false);
const employmentUserId = ref<number | null>(null);
const employmentPositionId = ref<number | null>(null);
const employmentStartsOn = ref(today);
const employmentIsPrimary = ref(true);
const employmentIsActing = ref(false);
const employmentReportsToId = ref<number | null>(null);

const deputyId = ref<number | null>(null);
const delegationStartsOn = ref(today);
const delegationEndsOn = ref(today);
const delegationReason = ref("");

const rows = computed(() => treeRows(units.value, showArchived.value));
const selectedUnit = computed(() => units.value.find((unit) => unit.id === selectedUnitId.value) ?? null);
const breadcrumb = computed(() => (selectedUnit.value ? unitPath(units.value, selectedUnit.value.id) : []));
const childUnits = computed(() => units.value.filter((unit) => unit.parent_id === selectedUnitId.value && (showArchived.value || !unit.archived_at)));
const activePositions = computed(() => positions.value.filter((position) => !position.archived_at));
const hasHeadPosition = computed(() => activePositions.value.some((position) => position.is_head));
const parentOptions = computed(() => {
  if (unitFormMode.value !== "edit" || !selectedUnit.value) return units.value.filter((unit) => !unit.archived_at);
  const editing = selectedUnit.value;
  return units.value.filter((unit) => unit.id === editing.parent_id || moveProblem(units.value, editing.id, unit.id) === null);
});
const myDelegations = computed(() => delegations.value.filter((delegation) => delegation.principal_id === me.value?.user.id));
const receivedDelegations = computed(() => delegations.value.filter((delegation) => delegation.deputy_id === me.value?.user.id));
const managedUnits = computed(() => units.value.filter((unit) => me.value?.managed_unit_ids.includes(unit.id)));

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    [units.value, people.value, me.value, delegations.value] = await Promise.all([
      apiRequest<OrgUnit[]>("/org/units"),
      apiRequest<Person[]>("/org/people"),
      apiRequest<ReportingLine>("/org/me"),
      apiRequest<Delegation[]>("/delegations"),
    ]);
    if (selectedUnitId.value === null || !units.value.some((unit) => unit.id === selectedUnitId.value)) {
      selectedUnitId.value = me.value?.unit_path.at(-1)?.id ?? rows.value[0]?.unit.id ?? null;
    }
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load the organization structure.");
  } finally {
    isLoading.value = false;
  }
}

async function loadUnit() {
  if (selectedUnitId.value === null) {
    positions.value = [];
    employments.value = [];
    return;
  }
  isUnitLoading.value = true;
  try {
    const id = selectedUnitId.value;
    [positions.value, employments.value] = await Promise.all([
      apiRequest<Position[]>(`/org/positions?unit_id=${id}`),
      apiRequest<Employment[]>(`/org/employments?unit_id=${id}${showEnded.value ? "&include_ended=1" : ""}`),
    ]);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load the unit.");
  } finally {
    isUnitLoading.value = false;
  }
}

watch([selectedUnitId, showEnded], () => {
  unitFormMode.value = null;
  positionFormOpen.value = false;
  employmentFormOpen.value = false;
  void loadUnit();
});

/** Runs a change, reports success, and reloads the structure (heads, counts and reporting lines all derive from it). */
async function save(action: () => Promise<unknown>, success: string, fallback: string) {
  isSaving.value = true;
  errorMessage.value = "";
  notice.value = "";
  try {
    await action();
    notice.value = success;
    await Promise.all([load(), loadUnit()]);
    return true;
  } catch (error) {
    errorMessage.value = errorText(error, fallback);
    return false;
  } finally {
    isSaving.value = false;
  }
}

function openUnitForm(mode: "create" | "edit") {
  unitFormMode.value = mode;
  const unit = selectedUnit.value;
  if (mode === "edit" && unit) {
    unitCode.value = unit.code;
    unitName.value = unit.name;
    unitKind.value = unit.kind;
    unitParentId.value = unit.parent_id;
  } else {
    unitCode.value = "";
    unitName.value = "";
    unitKind.value = units.value.length === 0 ? "organization" : unit?.kind === "organization" ? "unit" : "section";
    unitParentId.value = units.value.length === 0 ? null : (unit?.id ?? null);
  }
}

async function submitUnit() {
  const body: Record<string, unknown> = { code: unitCode.value.trim(), name: unitName.value.trim(), kind: unitKind.value };
  if (unitFormMode.value === "create") {
    if (unitParentId.value !== null) body.parent_id = unitParentId.value;
    let createdId: number | null = null;
    const ok = await save(async () => {
      createdId = (await apiRequest<OrgUnit>("/org/units", { method: "POST", body: JSON.stringify(body) })).id;
    }, "Unit created.", "Unable to create the unit.");
    if (ok && createdId !== null) selectedUnitId.value = createdId;
    if (ok) unitFormMode.value = null;
    return;
  }
  if (!selectedUnit.value) return;
  if (unitParentId.value === null) body.clear_parent = true;
  else body.parent_id = unitParentId.value;
  const id = selectedUnit.value.id;
  if (await save(() => apiRequest(`/org/units/${id}`, { method: "PATCH", body: JSON.stringify(body) }), "Unit saved.", "Unable to save the unit.")) {
    unitFormMode.value = null;
  }
}

async function unitAction(action: "archive" | "restore" | "delete") {
  const unit = selectedUnit.value;
  if (!unit) return;
  if (action === "delete" && !window.confirm(`Delete "${unit.name}"? Only unused units can be deleted; archive the others.`)) return;
  const parent = unit.parent_id;
  const ok = await save(
    () => (action === "delete" ? apiRequest(`/org/units/${unit.id}`, { method: "DELETE" }) : apiRequest(`/org/units/${unit.id}/${action}`, { method: "POST" })),
    action === "delete" ? "Unit deleted." : action === "archive" ? "Unit archived." : "Unit restored.",
    `Unable to ${action} the unit.`,
  );
  if (ok && action === "delete") selectedUnitId.value = parent;
  if (ok && action === "archive") showArchived.value = true;
}

function onDragStart(unit: OrgUnit, event: DragEvent) {
  if (!isAdmin || unit.kind === "organization" || unit.archived_at) return;
  draggingId.value = unit.id;
  event.dataTransfer?.setData("text/plain", String(unit.id));
}

function onDragOver(target: OrgUnit, event: DragEvent) {
  if (draggingId.value === null || moveProblem(units.value, draggingId.value, target.id) !== null) return;
  event.preventDefault();
  dropTargetId.value = target.id;
}

async function onDrop(target: OrgUnit) {
  const movedId = draggingId.value;
  draggingId.value = dropTargetId.value = null;
  if (movedId === null) return;
  const problem = moveProblem(units.value, movedId, target.id);
  if (problem) {
    errorMessage.value = problem;
    return;
  }
  const moved = units.value.find((unit) => unit.id === movedId);
  if (!moved || !window.confirm(`Move "${moved.name}" under "${target.name}"? Reporting lines follow the new structure.`)) return;
  await save(() => apiRequest(`/org/units/${movedId}`, { method: "PATCH", body: JSON.stringify({ parent_id: target.id }) }), `"${moved.name}" moved under "${target.name}".`, "Unable to move the unit.");
}

function openPositionForm() {
  positionTitle.value = "";
  positionIsHead.value = !hasHeadPosition.value;
  positionFormOpen.value = true;
}

async function submitPosition() {
  if (!selectedUnit.value) return;
  const body = { unit_id: selectedUnit.value.id, title: positionTitle.value.trim(), is_head: positionIsHead.value };
  if (await save(() => apiRequest("/org/positions", { method: "POST", body: JSON.stringify(body) }), "Position added.", "Unable to add the position.")) {
    positionFormOpen.value = false;
  }
}

async function positionAction(position: Position, action: "archive" | "delete" | "head") {
  if (action === "delete" && !window.confirm(`Delete the position "${position.title}"?`)) return;
  await save(
    () =>
      action === "delete"
        ? apiRequest(`/org/positions/${position.id}`, { method: "DELETE" })
        : action === "archive"
          ? apiRequest(`/org/positions/${position.id}/archive`, { method: "POST" })
          : apiRequest(`/org/positions/${position.id}`, { method: "PATCH", body: JSON.stringify({ is_head: !position.is_head }) }),
    action === "head" ? "Position updated." : action === "archive" ? "Position archived." : "Position deleted.",
    "Unable to change the position.",
  );
}

function openEmploymentForm(position?: Position) {
  employmentUserId.value = null;
  employmentPositionId.value = position?.id ?? activePositions.value[0]?.id ?? null;
  employmentStartsOn.value = today;
  employmentIsPrimary.value = true;
  employmentIsActing.value = false;
  employmentReportsToId.value = null;
  employmentFormOpen.value = true;
}

async function submitEmployment() {
  if (employmentUserId.value === null || employmentPositionId.value === null) return;
  const body: Record<string, unknown> = {
    user_id: employmentUserId.value,
    position_id: employmentPositionId.value,
    starts_on: employmentStartsOn.value,
    is_primary: employmentIsPrimary.value,
    is_acting: employmentIsActing.value,
  };
  if (employmentReportsToId.value !== null) body.reports_to_id = employmentReportsToId.value;
  if (await save(() => apiRequest("/org/employments", { method: "POST", body: JSON.stringify(body) }), "Employee assigned.", "Unable to assign the employee.")) {
    employmentFormOpen.value = false;
  }
}

async function endEmployment(employment: Employment) {
  const endsOn = window.prompt(`Last working day of ${employment.user_email} as ${employment.position_title} (YYYY-MM-DD):`, today);
  if (!endsOn) return;
  await save(() => apiRequest(`/org/employments/${employment.id}`, { method: "PATCH", body: JSON.stringify({ ends_on: endsOn.trim() }) }), "Employment ended.", "Unable to end the employment.");
}

async function changeReportsTo(employment: Employment, value: string) {
  const body = value === "" ? { clear_reports_to: true } : { reports_to_id: Number(value) };
  await save(() => apiRequest(`/org/employments/${employment.id}`, { method: "PATCH", body: JSON.stringify(body) }), "Reporting line updated.", "Unable to change the reporting line.");
}

async function removeEmployment(employment: Employment) {
  if (!window.confirm(`Remove this employment record for ${employment.user_email}? Use "End" for a normal change; remove only mistakes.`)) return;
  await save(() => apiRequest(`/org/employments/${employment.id}`, { method: "DELETE" }), "Employment removed.", "Unable to remove the employment.");
}

async function submitDelegation() {
  if (deputyId.value === null) return;
  const body = { deputy_id: deputyId.value, starts_on: delegationStartsOn.value, ends_on: delegationEndsOn.value, reason: delegationReason.value.trim() || null };
  if (await save(() => apiRequest("/delegations", { method: "POST", body: JSON.stringify(body) }), "Deputy named.", "Unable to name the deputy.")) {
    deputyId.value = null;
    delegationReason.value = "";
  }
}

async function revokeDelegation(delegation: Delegation) {
  if (!window.confirm(`Stop ${delegation.deputy_email} acting for you from now on?`)) return;
  await save(() => apiRequest(`/delegations/${delegation.id}/revoke`, { method: "POST" }), "Delegation revoked.", "Unable to revoke the delegation.");
}

function kindLabel(kind: OrgUnitKind) {
  return unitKinds.find((item) => item.kind === kind)?.label ?? kind;
}

function formatDay(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(new Date(`${value}T00:00:00`));
}

onMounted(load);
</script>

<template>
  <div class="page-content">
    <div class="page-heading">
      <div>
        <p class="eyebrow">Organization</p>
        <h1>Structure and reporting lines.</h1>
      </div>
      <span class="access-badge">{{ isAdmin ? "Administrator editing" : "Read only" }}</span>
    </div>

    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="notice" class="settings-notice" role="status">{{ notice }}</p>
    <div v-if="isLoading && units.length === 0" class="empty-page"><h2>Loading the organization...</h2></div>

    <template v-else>
      <div class="detail-grid">
        <section class="detail-card" aria-labelledby="org-me-title">
          <h2 id="org-me-title">My reporting line</h2>
          <template v-if="me && me.unit_path.length">
            <p class="org-breadcrumb">
              <template v-for="(item, index) in me.unit_path" :key="item.id"><span v-if="index" aria-hidden="true"> › </span>{{ item.name }}</template>
            </p>
            <p class="muted">{{ me.user.position_title }}</p>
            <h3 class="org-subheading">Reports to</h3>
            <ol v-if="me.superiors.length" class="org-chain">
              <li v-for="person in me.superiors" :key="person.id">{{ personLabel(person) }}</li>
            </ol>
            <p v-else class="muted">Nobody: you are at the top of the structure.</p>
            <h3 class="org-subheading">Direct reports</h3>
            <ul v-if="me.direct_subordinates.length" class="detail-list">
              <li v-for="person in me.direct_subordinates" :key="person.id">{{ personLabel(person) }}</li>
            </ul>
            <p v-else class="muted">None.</p>
            <p v-if="me.subordinate_count > me.direct_subordinates.length" class="muted">{{ me.subordinate_count }} people in your reporting line in total.</p>
            <p v-if="managedUnits.length" class="muted">You head: {{ managedUnits.map((unit) => unit.name).join(", ") }}.</p>
          </template>
          <p v-else class="muted">You have no position in the structure yet. An administrator assigns positions.</p>
        </section>

        <section class="detail-card" aria-labelledby="org-deputy-title">
          <h2 id="org-deputy-title">Deputies</h2>
          <p class="muted">While you are away, your deputy receives the approvals and acknowledgements addressed to you.</p>
          <p v-if="me?.deputy"><strong>{{ me.deputy.email }}</strong> is acting for you today.</p>
          <ul v-if="myDelegations.length" class="detail-list">
            <li v-for="delegation in myDelegations" :key="delegation.id" class="org-row">
              <span>{{ delegation.deputy_email }} · {{ formatDay(delegation.starts_on) }} – {{ formatDay(delegation.ends_on) }}<template v-if="delegation.reason"> · {{ delegation.reason }}</template></span>
              <button class="edit-button danger-button" type="button" :disabled="isSaving" @click="revokeDelegation(delegation)">Revoke</button>
            </li>
          </ul>
          <p v-if="receivedDelegations.length" class="muted">
            You deputize for {{ receivedDelegations.map((delegation) => `${delegation.principal_email} (${formatDay(delegation.starts_on)} – ${formatDay(delegation.ends_on)})`).join(", ") }}.
          </p>
          <form class="org-inline-form" @submit.prevent="submitDelegation">
            <label for="deputy-person">Deputy</label>
            <select id="deputy-person" v-model.number="deputyId" required>
              <option :value="null" disabled>Choose a colleague</option>
              <option v-for="person in people.filter((candidate) => candidate.id !== me?.user.id)" :key="person.id" :value="person.id">{{ personLabel(person) }}</option>
            </select>
            <div class="inline-fields">
              <div><label for="deputy-from">From</label><input id="deputy-from" v-model="delegationStartsOn" type="date" required /></div>
              <div><label for="deputy-to">To</label><input id="deputy-to" v-model="delegationEndsOn" type="date" :min="delegationStartsOn" required /></div>
            </div>
            <label for="deputy-reason">Reason (optional)</label>
            <input id="deputy-reason" v-model="delegationReason" type="text" maxlength="255" placeholder="Annual leave" />
            <button class="edit-button" type="submit" :disabled="isSaving || deputyId === null">Name deputy</button>
          </form>
        </section>
      </div>

      <div class="org-layout">
        <aside class="detail-card org-tree-panel" aria-labelledby="org-tree-title">
          <div class="org-row">
            <h2 id="org-tree-title">Structure</h2>
            <button v-if="isAdmin && units.length === 0" class="edit-button" type="button" @click="openUnitForm('create')">+ Organization</button>
          </div>
          <label class="pm-all-projects-toggle"><input v-model="showArchived" type="checkbox" /> Show archived units</label>
          <p v-if="isAdmin && units.length > 1" class="muted org-hint">Drag a unit onto another to move it there.</p>
          <p v-if="rows.length === 0" class="muted">No units yet.</p>
          <ul class="org-tree" aria-label="Organization units">
            <li v-for="row in rows" :key="row.unit.id">
              <button
                type="button"
                class="org-tree-row"
                :class="{ 'is-selected': row.unit.id === selectedUnitId, 'is-drop-target': row.unit.id === dropTargetId, 'is-archived': row.unit.archived_at }"
                :style="{ paddingLeft: `${10 + row.level * 18}px` }"
                :aria-current="row.unit.id === selectedUnitId ? 'true' : undefined"
                :draggable="isAdmin && row.unit.kind !== 'organization' && !row.unit.archived_at"
                @click="selectedUnitId = row.unit.id"
                @dragstart="onDragStart(row.unit, $event)"
                @dragover="onDragOver(row.unit, $event)"
                @dragleave="dropTargetId = null"
                @dragend="draggingId = dropTargetId = null"
                @drop.prevent="onDrop(row.unit)"
              >
                <span class="org-tree-name">{{ row.unit.name }}</span>
                <span class="org-kind">{{ kindLabel(row.unit.kind) }}</span>
                <span class="org-tree-meta">{{ row.unit.head?.email ?? "No head" }} · {{ row.unit.member_count }}</span>
              </button>
            </li>
          </ul>
        </aside>

        <section class="org-detail" aria-live="polite">
          <form v-if="unitFormMode" class="article-form" @submit.prevent="submitUnit">
            <div class="article-form-heading">
              <div>
                <p class="card-kicker">{{ unitFormMode === "edit" ? "Edit unit" : "New unit" }}</p>
                <h2>{{ unitFormMode === "edit" ? selectedUnit?.name : "Add to the structure." }}</h2>
              </div>
              <button class="close-button" type="button" aria-label="Close unit form" @click="unitFormMode = null">&#10005;</button>
            </div>
            <div class="inline-fields">
              <div><label for="unit-code">Code</label><input id="unit-code" v-model="unitCode" type="text" maxlength="32" required placeholder="SALES" /></div>
              <div>
                <label for="unit-kind">Kind</label>
                <select id="unit-kind" v-model="unitKind">
                  <option v-for="item in unitKinds" :key="item.kind" :value="item.kind" :disabled="(item.kind === 'organization') !== (unitParentId === null)">{{ item.label }}</option>
                </select>
              </div>
            </div>
            <label for="unit-name">Name</label>
            <input id="unit-name" v-model="unitName" type="text" maxlength="255" required placeholder="Sales department" />
            <label for="unit-parent">Parent unit</label>
            <select id="unit-parent" v-model.number="unitParentId" :disabled="unitKind === 'organization' && unitFormMode === 'edit'">
              <option v-if="unitKind === 'organization'" :value="null">None (top level)</option>
              <option v-for="unit in parentOptions" :key="unit.id" :value="unit.id">{{ unitPath(units, unit.id).map((item) => item.name).join(" › ") }}</option>
            </select>
            <button class="button article-submit" type="submit" :disabled="isSaving">{{ isSaving ? "Saving..." : unitFormMode === "edit" ? "Save unit" : "Create unit" }} <span aria-hidden="true">&#8594;</span></button>
          </form>

          <div v-if="!selectedUnit" class="empty-page">
            <span class="empty-icon" aria-hidden="true">&#9673;</span>
            <h2>{{ units.length ? "Select a unit." : "No structure yet." }}</h2>
            <p class="muted">{{ isAdmin ? "Start with the organization, then add units and sections." : "An administrator sets up the organization structure." }}</p>
          </div>

          <template v-else>
            <article class="detail-card">
              <p class="org-breadcrumb muted">{{ breadcrumb.map((item) => item.name).join(" › ") }}</p>
              <div class="org-row">
                <h2>{{ selectedUnit.name }} <span class="org-kind">{{ kindLabel(selectedUnit.kind) }}</span></h2>
                <span v-if="selectedUnit.archived_at" class="status-pill">Archived</span>
              </div>
              <dl>
                <dt>Code</dt><dd>{{ selectedUnit.code }}</dd>
                <dt>Head</dt><dd>{{ selectedUnit.head?.email ?? "Vacant: staff report to the next head up" }}</dd>
                <dt>Staff</dt><dd>{{ selectedUnit.member_count }}</dd>
                <dt>Sub-units</dt><dd>{{ childUnits.length ? childUnits.map((unit) => unit.name).join(", ") : "None" }}</dd>
              </dl>
              <div v-if="isAdmin" class="org-actions">
                <button class="edit-button" type="button" :disabled="!!selectedUnit.archived_at" @click="openUnitForm('edit')">Edit</button>
                <button class="edit-button" type="button" :disabled="!!selectedUnit.archived_at" @click="openUnitForm('create')">+ Sub-unit</button>
                <button v-if="!selectedUnit.archived_at" class="edit-button" type="button" :disabled="isSaving || selectedUnit.kind === 'organization'" @click="unitAction('archive')">Archive</button>
                <button v-else class="edit-button" type="button" :disabled="isSaving" @click="unitAction('restore')">Restore</button>
                <button class="edit-button danger-button" type="button" :disabled="isSaving" @click="unitAction('delete')">Delete</button>
              </div>
            </article>

            <article class="detail-card">
              <div class="org-row">
                <h2>Positions</h2>
                <button v-if="isAdmin && !selectedUnit.archived_at && !positionFormOpen" class="edit-button" type="button" @click="openPositionForm">+ Position</button>
              </div>
              <form v-if="positionFormOpen" class="org-inline-form" @submit.prevent="submitPosition">
                <label for="position-title">Title</label>
                <input id="position-title" v-model="positionTitle" type="text" maxlength="255" required placeholder="Account manager" />
                <label class="pm-all-projects-toggle"><input v-model="positionIsHead" type="checkbox" :disabled="hasHeadPosition" /> Head of this unit{{ hasHeadPosition ? " (already defined)" : "" }}</label>
                <div class="org-actions">
                  <button class="edit-button" type="submit" :disabled="isSaving">Add position</button>
                  <button class="edit-button" type="button" @click="positionFormOpen = false">Cancel</button>
                </div>
              </form>
              <p v-if="isUnitLoading" class="muted">Loading...</p>
              <p v-else-if="positions.length === 0" class="muted">No positions in this unit.</p>
              <ul v-else class="detail-list">
                <li v-for="position in positions" :key="position.id" class="org-row" :class="{ 'is-archived': position.archived_at }">
                  <span>
                    <strong>{{ position.title }}</strong>
                    <span v-if="position.is_head" class="org-kind">Head</span>
                    <span v-if="position.archived_at" class="muted"> · archived</span>
                    <span class="muted"> · {{ position.holders.length ? position.holders.map((holder) => holder.email + (holder.is_acting ? " (acting)" : "")).join(", ") : "vacant" }}</span>
                  </span>
                  <span v-if="isAdmin && !position.archived_at" class="org-actions">
                    <button class="edit-button" type="button" :disabled="isSaving" @click="openEmploymentForm(position)">Assign</button>
                    <button class="edit-button" type="button" :disabled="isSaving || (!position.is_head && hasHeadPosition)" @click="positionAction(position, 'head')">{{ position.is_head ? "Unset head" : "Make head" }}</button>
                    <button class="edit-button" type="button" :disabled="isSaving" @click="positionAction(position, 'archive')">Archive</button>
                    <button class="edit-button danger-button" type="button" :disabled="isSaving" @click="positionAction(position, 'delete')">Delete</button>
                  </span>
                </li>
              </ul>
            </article>

            <article class="detail-card">
              <div class="org-row">
                <h2>Employees</h2>
                <span class="org-actions">
                  <label class="pm-all-projects-toggle"><input v-model="showEnded" type="checkbox" /> Include ended</label>
                  <button v-if="isAdmin && activePositions.length && !employmentFormOpen" class="edit-button" type="button" @click="openEmploymentForm()">+ Assign</button>
                </span>
              </div>
              <form v-if="employmentFormOpen" class="org-inline-form" @submit.prevent="submitEmployment">
                <label for="employment-user">Employee</label>
                <select id="employment-user" v-model.number="employmentUserId" required>
                  <option :value="null" disabled>Choose a user</option>
                  <option v-for="person in people" :key="person.id" :value="person.id">{{ personLabel(person) }}</option>
                </select>
                <label for="employment-position">Position</label>
                <select id="employment-position" v-model.number="employmentPositionId" required>
                  <option v-for="position in activePositions" :key="position.id" :value="position.id">{{ position.title }}{{ position.is_head ? " (head)" : "" }}</option>
                </select>
                <label for="employment-start">Starts on</label>
                <input id="employment-start" v-model="employmentStartsOn" type="date" required />
                <label class="pm-all-projects-toggle"><input v-model="employmentIsPrimary" type="checkbox" /> Primary position (decides the reporting line)</label>
                <label class="pm-all-projects-toggle"><input v-model="employmentIsActing" type="checkbox" /> Acting (temporary)</label>
                <label for="employment-reports">Reports to (optional override)</label>
                <select id="employment-reports" v-model.number="employmentReportsToId">
                  <option :value="null">Follow the structure</option>
                  <option v-for="person in people.filter((candidate) => candidate.id !== employmentUserId)" :key="person.id" :value="person.id">{{ personLabel(person) }}</option>
                </select>
                <div class="org-actions">
                  <button class="edit-button" type="submit" :disabled="isSaving || employmentUserId === null">Assign employee</button>
                  <button class="edit-button" type="button" @click="employmentFormOpen = false">Cancel</button>
                </div>
              </form>
              <p v-if="!isUnitLoading && employments.length === 0" class="muted">Nobody is assigned to this unit.</p>
              <ul v-else class="detail-list">
                <li v-for="employment in employments" :key="employment.id" class="org-row" :class="{ 'is-archived': !employment.is_active }">
                  <span>
                    <strong>{{ employment.user_email }}</strong>
                    <span class="muted"> · {{ employment.position_title }}</span>
                    <span v-if="employment.is_acting" class="org-kind">Acting</span>
                    <span v-if="!employment.is_primary" class="org-kind">Secondary</span>
                    <span class="muted"> · {{ formatDay(employment.starts_on) }} – {{ employment.ends_on ? formatDay(employment.ends_on) : "present" }}</span>
                    <span v-if="employment.reports_to_email && !isAdmin" class="muted"> · reports to {{ employment.reports_to_email }}</span>
                  </span>
                  <span v-if="isAdmin" class="org-actions">
                    <label v-if="employment.is_primary">
                      <span class="visually-hidden">Reporting override for {{ employment.user_email }}</span>
                      <select :value="employment.reports_to_id ?? ''" :disabled="isSaving" @change="changeReportsTo(employment, ($event.target as HTMLSelectElement).value)">
                        <option value="">Reports per structure</option>
                        <option v-for="person in people.filter((candidate) => candidate.id !== employment.user_id)" :key="person.id" :value="person.id">Reports to {{ person.email }}</option>
                      </select>
                    </label>
                    <button v-if="!employment.ends_on" class="edit-button" type="button" :disabled="isSaving" @click="endEmployment(employment)">End</button>
                    <button class="edit-button danger-button" type="button" :disabled="isSaving" @click="removeEmployment(employment)">Remove</button>
                  </span>
                </li>
              </ul>
            </article>

            <RecordChangeHistory record-type="org_unit" :record-id="selectedUnit.id" :key="`org-history-${selectedUnit.id}`" />
          </template>
        </section>
      </div>
    </template>
  </div>
</template>
