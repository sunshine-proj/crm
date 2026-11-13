<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { apiRequest, downloadFile, errorText, previewFile } from "../lib/api";
import { detailHref } from "../lib/route";
import type { DocumentDetail } from "../lib/documents";
import {
  barWidth,
  emptySelection,
  filterCounts,
  filterLabels,
  filterRecipients,
  progressText,
  recipientStatusLabels,
  recipientsInUnit,
  selectionSize,
  statusTone,
  targetsPayload,
  type AckAudience,
  type AckBoard,
  type AckRecipient,
  type RecipientFilter,
} from "../lib/acknowledgements";
import AcknowledgementTargetPicker from "./AcknowledgementTargetPicker.vue";

const props = defineProps<{ requestId: number }>();

const board = ref<AckBoard | null>(null);
const isLoading = ref(true);
const errorMessage = ref("");
const notice = ref("");
const isSaving = ref(false);
const filter = ref<RecipientFilter>("all");
const unitFilter = ref<number | null | undefined>(undefined);
const panel = ref<"" | "deadline" | "recipients" | "reissue">("");
const newDue = ref("");
const audience = ref<AckAudience | null>(null);
const selection = ref(emptySelection());
const reissueOptions = ref<{ id: number; label: string }[]>([]);
const reissueDocumentId = ref<number | null>(null);
const reissueDue = ref("");
const excusingId = ref<number | null>(null);
const excuseReason = ref("");

const today = new Date().toISOString().slice(0, 10);
const filterKeys = Object.keys(filterLabels) as RecipientFilter[];
const inUnit = computed(() => (board.value === null ? [] : unitFilter.value === undefined ? board.value.recipients : recipientsInUnit(board.value.recipients, board.value.units, unitFilter.value)));
const counts = computed(() => filterCounts(inUnit.value));
const shown = computed(() => filterRecipients(inUnit.value, filter.value));
const selectedUnitName = computed(() => (unitFilter.value === undefined ? null : board.value?.units.find((unit) => unit.unit_id === unitFilter.value)?.name ?? "No unit recorded"));
const remindable = computed(() => (board.value?.recipients ?? []).filter((row) => row.can_remind).length);

async function load() {
  try {
    board.value = await apiRequest<AckBoard>(`/dms/acknowledgements/${props.requestId}`);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load the acknowledgement request.");
  } finally {
    isLoading.value = false;
  }
}

async function run(action: () => Promise<unknown>, fallback: string, success?: (result: unknown) => string) {
  isSaving.value = true;
  errorMessage.value = notice.value = "";
  try {
    const result = await action();
    if (success) notice.value = success(result);
    await load();
  } catch (error) {
    errorMessage.value = errorText(error, fallback);
  } finally {
    isSaving.value = false;
  }
}

const post = (path: string, body: unknown = {}, method = "POST") => apiRequest(`/dms/acknowledgements/${path}`, { method, body: JSON.stringify(body) });
const remindedText = (result: unknown) => {
  const { reminded, skipped } = result as { reminded: number; skipped: number };
  return `Reminded ${reminded} ${reminded === 1 ? "person" : "people"}${skipped ? `; ${skipped} already reminded today or done` : ""}.`;
};

function remindAll() {
  void run(() => post(`${props.requestId}/remind`), "Unable to send reminders.", remindedText);
}

function remindOne(row: AckRecipient) {
  void run(() => post(`${props.requestId}/remind`, { recipient_ids: [row.id] }), "Unable to send the reminder.", remindedText);
}

function startExcuse(row: AckRecipient) {
  excusingId.value = row.id;
  excuseReason.value = "";
}

function excuse(row: AckRecipient) {
  void run(() => post(`recipients/${row.id}/excuse`, { reason: excuseReason.value.trim() }), "Unable to excuse this person.", () => `${row.user.email} is excused.`).then(() => {
    if (!errorMessage.value) excusingId.value = null;
  });
}

function reinstate(row: AckRecipient) {
  void run(() => post(`recipients/${row.id}/excuse`, {}, "DELETE"), "Unable to undo the excuse.", () => `${row.user.email} is asked again.`);
}

async function openPanel(name: typeof panel.value) {
  errorMessage.value = "";
  if (!board.value) return;
  try {
    if (name === "recipients") {
      audience.value ??= await apiRequest<AckAudience>(`/dms/documents/${board.value.document.id}/acknowledgements/audience`);
      selection.value = emptySelection();
    }
    if (name === "reissue") {
      const document = await apiRequest<DocumentDetail>(`/dms/documents/${board.value.document.id}`);
      const options = document.links
        .filter((link) => link.direction === "incoming" && (link.kind === "replaces" || link.kind === "amends"))
        .map((link) => ({ id: link.document.id, label: `${link.document.registration_number ?? "Unregistered"} · ${link.document.title} (${link.kind})` }));
      if ((document.current_version?.number ?? 0) > board.value.version_number) options.unshift({ id: document.id, label: `This document, version ${document.current_version?.number}` });
      reissueOptions.value = options;
      reissueDocumentId.value = options[0]?.id ?? null;
      reissueDue.value = "";
    }
    newDue.value = "";
    panel.value = panel.value === name ? "" : name;
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load the options.");
  }
}

function extend() {
  void run(() => post(`${props.requestId}/deadline`, { due_on: newDue.value }), "Unable to move the deadline.", () => "Deadline moved.").then(() => {
    if (!errorMessage.value) panel.value = "";
  });
}

function addRecipients() {
  void run(() => post(`${props.requestId}/recipients`, { targets: targetsPayload(selection.value) }), "Unable to add recipients.", (result) => {
    const added = (result as { added: number }).added;
    return added === 0 ? "Everyone chosen was already asked." : `Added ${added} ${added === 1 ? "person" : "people"}.`;
  }).then(() => {
    if (!errorMessage.value) panel.value = "";
  });
}

function closeRequest() {
  if (!window.confirm("Close this request? People who have not confirmed will no longer be asked.")) return;
  void run(() => post(`${props.requestId}/close`), "Unable to close the request.", () => "The request is closed.");
}

async function reissue() {
  isSaving.value = true;
  errorMessage.value = "";
  try {
    const created = await apiRequest<AckBoard>(`/dms/acknowledgements/${props.requestId}/reissue`, { method: "POST", body: JSON.stringify({ document_id: reissueDocumentId.value, due_on: reissueDue.value || null }) });
    window.location.hash = detailHref("acknowledgement", created.id);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to re-issue the request.");
  } finally {
    isSaving.value = false;
  }
}

function exportCsv() {
  downloadFile(`/dms/acknowledgements/${props.requestId}/export.csv`, `acknowledgement-${props.requestId}.csv`).catch((error: unknown) => (errorMessage.value = errorText(error, "Unable to export.")));
}

function openSheet() {
  previewFile(`/dms/acknowledgements/${props.requestId}/sheet.pdf`).catch((error: unknown) => (errorMessage.value = errorText(error, "Unable to open the sheet.")));
}

function formatWhen(value: string | null) {
  return value ? new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value)) : "";
}

function formatDay(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(new Date(`${value}T00:00:00`));
}

onMounted(load);
</script>

<template>
  <div class="page-content">
    <div v-if="isLoading" class="empty-page"><h2>Loading...</h2></div>
    <div v-else-if="!board" class="empty-page">
      <h2>This acknowledgement request is not available.</h2>
      <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
      <a class="edit-button" href="#/">Back</a>
    </div>
    <template v-else>
      <div class="page-heading">
        <div>
          <p class="eyebrow"><a :href="detailHref('document', board.document.id)">{{ board.document.registration_number ?? board.document.type }}</a> · Acknowledgement</p>
          <h1>{{ board.document.title }}</h1>
          <p class="muted">
            Version {{ board.version_number }} · sent by {{ board.sender }} on {{ formatWhen(board.created_at) }}
            <template v-if="board.due_on"> · due {{ formatDay(board.due_on) }}</template>
            <template v-if="board.status === 'closed'"> · closed {{ formatWhen(board.closed_at) }} by {{ board.closed_by }}</template>
          </p>
        </div>
        <span class="status-pill" :class="board.status === 'closed' ? 'dms-tone-closed' : board.is_overdue ? 'dms-tone-attention' : 'dms-tone-progress'">{{ board.status === "closed" ? "Closed" : board.is_overdue ? "Overdue" : "Open" }}</span>
      </div>

      <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
      <p v-if="notice" class="settings-notice" role="status">{{ notice }}</p>
      <p v-if="board.is_partial" class="muted">You see the people in the units you manage.</p>
      <blockquote v-if="board.message" class="approval-quote dms-prewrap">{{ board.message }}</blockquote>

      <section class="detail-card wide dms-actions" aria-label="Request actions">
        <div class="ack-summary">
          <strong class="ack-percent">{{ board.counts.completion_percent }}%</strong>
          <span>
            {{ progressText(board.counts) }}
            <span class="ack-bar" role="img" :aria-label="`${board.counts.completion_percent}% complete`"><span :style="{ width: barWidth(board.counts) }"></span></span>
          </span>
        </div>
        <p class="muted">Asked: {{ board.targets.map((target) => target.label).join(", ") }}<template v-if="board.auto_add_joiners"> · people who join are added</template><template v-if="board.require_open"> · opening the document is required</template></p>
        <div class="org-actions">
          <button v-if="board.permissions.can_remind && remindable" class="edit-button" type="button" :disabled="isSaving" @click="remindAll">Remind everyone pending ({{ remindable }})</button>
          <button v-if="board.permissions.can_control" class="edit-button" type="button" @click="openPanel('deadline')">{{ board.due_on ? "Extend deadline" : "Set deadline" }}</button>
          <button v-if="board.permissions.can_control" class="edit-button" type="button" @click="openPanel('recipients')">Add recipients</button>
          <button v-if="board.permissions.can_reissue" class="edit-button" type="button" @click="openPanel('reissue')">Re-issue for a new version</button>
          <button v-if="board.permissions.can_control" class="edit-button" type="button" :disabled="isSaving" @click="closeRequest">Close request</button>
          <button class="edit-button" type="button" @click="exportCsv">Export CSV</button>
          <button class="edit-button" type="button" @click="openSheet">Acknowledgement sheet (PDF)</button>
          <a v-if="board.previous_id" class="edit-button" :href="detailHref('acknowledgement', board.previous_id)">Previous request</a>
        </div>

        <form v-if="panel === 'deadline'" class="org-inline-form" @submit.prevent="extend">
          <label for="ack-new-due">New deadline</label>
          <input id="ack-new-due" v-model="newDue" type="date" :min="board.due_on ?? today" required />
          <button class="edit-button" type="submit" :disabled="isSaving || !newDue">Save deadline</button>
        </form>
        <form v-if="panel === 'recipients' && audience" class="org-inline-form" @submit.prevent="addRecipients">
          <AcknowledgementTargetPicker v-model="selection" :audience="audience" id-prefix="ack-add" />
          <button class="edit-button" type="submit" :disabled="isSaving || selectionSize(selection) === 0">Add and notify</button>
        </form>
        <form v-if="panel === 'reissue'" class="org-inline-form" @submit.prevent="reissue">
          <p class="muted org-hint">The same units, positions and people are asked to confirm the new edition; this request is closed.</p>
          <p v-if="reissueOptions.length === 0" class="muted">There is nothing to re-issue for: the document has no newer version, and no registered document is linked as replacing or amending it.</p>
          <template v-else>
            <label for="ack-reissue-doc">New edition</label>
            <select id="ack-reissue-doc" v-model.number="reissueDocumentId">
              <option v-for="option in reissueOptions" :key="option.id" :value="option.id">{{ option.label }}</option>
            </select>
            <label for="ack-reissue-due">Deadline (optional)</label>
            <input id="ack-reissue-due" v-model="reissueDue" type="date" :min="today" />
            <button class="create-button" type="submit" :disabled="isSaving || reissueDocumentId === null">Re-issue</button>
          </template>
        </form>
      </section>

      <div class="detail-grid">
        <section class="detail-card" aria-labelledby="ack-units-title">
          <h2 id="ack-units-title">By unit</h2>
          <table class="ack-units">
            <thead><tr><th scope="col">Unit</th><th scope="col">Done</th><th scope="col">Confirmed</th><th scope="col">Overdue</th></tr></thead>
            <tbody>
              <tr v-for="unit in board.units" :key="unit.unit_id ?? 'none'" :class="{ 'is-selected': unitFilter === unit.unit_id }">
                <th scope="row" :style="{ paddingLeft: `${8 + unit.depth * 16}px` }">
                  <button class="ack-unit-button" type="button" :aria-pressed="unitFilter === unit.unit_id" @click="unitFilter = unitFilter === unit.unit_id ? undefined : unit.unit_id">{{ unit.name }}</button>
                </th>
                <td><span class="ack-bar" role="img" :aria-label="`${unit.total.completion_percent}% complete`"><span :style="{ width: barWidth(unit.total) }"></span></span> {{ unit.total.completion_percent }}%</td>
                <td>{{ unit.total.acknowledged }} / {{ unit.total.total - unit.total.excused }}</td>
                <td :class="{ 'approval-overdue': unit.total.overdue }">{{ unit.total.overdue }}</td>
              </tr>
            </tbody>
          </table>
          <p class="muted org-hint">Each unit includes the units below it. Select a unit to list its people.</p>
        </section>

        <section class="detail-card wide" aria-labelledby="ack-people-title">
          <div class="org-row">
            <h2 id="ack-people-title">People<template v-if="selectedUnitName"> in {{ selectedUnitName }}</template></h2>
            <button v-if="unitFilter !== undefined" class="edit-button" type="button" @click="unitFilter = undefined">Show all units</button>
          </div>
          <div class="view-tabs" role="group" aria-label="Filter people">
            <button v-for="key in filterKeys" :key="key" type="button" :aria-pressed="filter === key" :class="{ active: filter === key }" @click="filter = key">{{ filterLabels[key] }} <span class="board-count">{{ counts[key] }}</span></button>
          </div>
          <p v-if="shown.length === 0" class="muted">Nobody here.</p>
          <ul v-else class="dms-list">
            <li v-for="row in shown" :key="row.id" class="dms-row" :class="{ 'is-overdue': row.is_overdue }">
              <span class="dms-row-main">
                <strong>{{ row.user.email }}</strong>
                <span class="muted">{{ row.position ?? "No position" }}<template v-if="row.unit"> · {{ row.unit.name }}</template><template v-if="row.source === 'joined'"> · joined later</template><template v-else-if="row.source === 'added'"> · added by {{ row.added_by }}</template></span>
                <span class="muted">
                  <template v-if="row.acknowledged_at">Confirmed {{ formatWhen(row.acknowledged_at) }}<template v-if="row.is_late"> (late)</template></template>
                  <template v-else-if="row.viewed_at">Opened {{ formatWhen(row.viewed_at) }}</template>
                  <template v-if="row.excuse_reason"> Excused by {{ row.excused_by }}: {{ row.excuse_reason }}</template>
                  <template v-if="row.reminder_count"> · reminded {{ row.reminder_count }}×, last {{ formatWhen(row.last_reminded_at) }}</template>
                </span>
              </span>
              <span class="dms-row-meta">
                <span class="status-pill" :class="statusTone(row)">{{ recipientStatusLabels[row.status] }}<template v-if="row.is_overdue"> · overdue</template></span>
                <button v-if="row.can_remind" class="edit-button" type="button" :disabled="isSaving" @click="remindOne(row)">Remind</button>
                <button v-if="row.can_excuse && excusingId !== row.id" class="edit-button" type="button" @click="startExcuse(row)">Excuse</button>
                <button v-if="row.can_reinstate" class="edit-button" type="button" :disabled="isSaving" @click="reinstate(row)">Ask again</button>
              </span>
              <form v-if="excusingId === row.id" class="approval-inline" @submit.prevent="excuse(row)">
                <label :for="`excuse-${row.id}`" class="visually-hidden">Reason for excusing {{ row.user.email }}</label>
                <input :id="`excuse-${row.id}`" v-model="excuseReason" type="text" maxlength="1000" required placeholder="Reason, e.g. parental leave until March" />
                <button class="edit-button" type="submit" :disabled="isSaving || !excuseReason.trim()">Excuse</button>
                <button class="edit-button" type="button" @click="excusingId = null">Cancel</button>
              </form>
            </li>
          </ul>
        </section>
      </div>
    </template>
  </div>
</template>
