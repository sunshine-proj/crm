<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { apiRequest, downloadFile, errorText, previewFile } from "../lib/api";
import { detailHref } from "../lib/route";
import type { DocumentVersion } from "../lib/documents";
import { barWidth, emptySelection, progressText, selectionSize, targetsPayload, type AckAudience, type AckBoard, type AckDocumentView, type AckMine } from "../lib/acknowledgements";
import AcknowledgementTargetPicker from "./AcknowledgementTargetPicker.vue";

const props = defineProps<{ documentId: number; versions: DocumentVersion[] }>();
const emit = defineEmits<{ changed: [] }>();

const view = ref<AckDocumentView | null>(null);
const audience = ref<AckAudience | null>(null);
const errorMessage = ref("");
const notice = ref("");
const isSaving = ref(false);
const isFormOpen = ref(false);
const selection = ref(emptySelection());
const message = ref("");
const dueOn = ref("");
const requireOpen = ref(true);
const addJoiners = ref(true);

const today = new Date().toISOString().slice(0, 10);
const openRows = computed(() => (view.value?.mine ?? []).filter((row) => row.request.status === "open" && (row.status === "pending" || row.status === "viewed")));
const pastRows = computed(() => (view.value?.mine ?? []).filter((row) => !openRows.value.includes(row)));
const isVisible = computed(() => view.value !== null && (view.value.can_send || view.value.mine.length > 0 || view.value.requests.length > 0));

async function load() {
  try {
    view.value = await apiRequest<AckDocumentView>(`/dms/documents/${props.documentId}/acknowledgements`);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load acknowledgements.");
  }
}

async function openForm() {
  errorMessage.value = notice.value = "";
  try {
    audience.value ??= await apiRequest<AckAudience>(`/dms/documents/${props.documentId}/acknowledgements/audience`);
    selection.value = emptySelection();
    message.value = dueOn.value = "";
    requireOpen.value = addJoiners.value = true;
    isFormOpen.value = true;
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load the people you can address.");
  }
}

async function send() {
  isSaving.value = true;
  errorMessage.value = "";
  try {
    const board = await apiRequest<AckBoard>(`/dms/documents/${props.documentId}/acknowledgements`, {
      method: "POST",
      body: JSON.stringify({ targets: targetsPayload(selection.value), message: message.value.trim() || null, due_on: dueOn.value || null, require_open: requireOpen.value, auto_add_joiners: addJoiners.value }),
    });
    notice.value = `Sent to ${board.counts.total} ${board.counts.total === 1 ? "person" : "people"}.`;
    isFormOpen.value = false;
    await load();
    emit("changed");
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to send the request.");
  } finally {
    isSaving.value = false;
  }
}

async function openVersion(row: AckMine) {
  const number = row.request.version_number;
  const version = props.versions.find((item) => item.number === number);
  const path = `/dms/documents/${props.documentId}/versions/${number}/file`;
  try {
    if (version?.previewable) await previewFile(`${path}?inline=1`);
    else await downloadFile(path, version?.original_name ?? `version-${number}`);
    await load();
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to open the document.");
  }
}

async function acknowledge(row: AckMine) {
  errorMessage.value = "";
  try {
    await apiRequest(`/dms/acknowledgements/recipients/${row.id}/acknowledge`, { method: "POST" });
    notice.value = "Thank you. Your acknowledgement is recorded.";
    await load();
    emit("changed");
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to record your acknowledgement.");
  }
}

function formatWhen(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

function formatDay(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(new Date(`${value}T00:00:00`));
}

onMounted(load);
</script>

<template>
  <section v-if="isVisible || errorMessage" class="detail-card wide" aria-labelledby="ack-title">
    <div class="org-row">
      <h2 id="ack-title">Acknowledgement</h2>
      <button v-if="view?.can_send && !isFormOpen" class="edit-button" type="button" @click="openForm">Ask for acknowledgement</button>
    </div>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="notice" class="settings-notice" role="status">{{ notice }}</p>

    <div v-for="row in openRows" :key="row.id" class="ack-mine" :class="{ 'is-overdue': row.is_overdue }">
      <p>
        <strong>{{ row.request.sender }}</strong> asks you to read version {{ row.request.version_number }} and confirm.
        <template v-if="row.request.due_on"> {{ row.is_overdue ? "It was due" : "Please do so by" }} {{ formatDay(row.request.due_on) }}.</template>
      </p>
      <blockquote v-if="row.request.message" class="approval-quote dms-prewrap">{{ row.request.message }}</blockquote>
      <div class="org-actions">
        <button class="edit-button" type="button" @click="openVersion(row)">Open v{{ row.request.version_number }}</button>
        <button class="create-button" type="button" :disabled="!row.can_acknowledge" :aria-describedby="row.needs_opening ? `ack-hint-${row.id}` : undefined" @click="acknowledge(row)">I have read and understood</button>
      </div>
      <p v-if="row.needs_opening" :id="`ack-hint-${row.id}`" class="muted org-hint">Open the document first; the confirmation becomes available once you have opened it.</p>
    </div>
    <ul v-if="pastRows.length" class="detail-list">
      <li v-for="row in pastRows" :key="row.id" class="muted">
        <template v-if="row.status === 'acknowledged' && row.acknowledged_at">You acknowledged v{{ row.request.version_number }} on {{ formatWhen(row.acknowledged_at) }}<template v-if="row.is_late"> (after the deadline)</template>.</template>
        <template v-else-if="row.status === 'excused'">You were excused from reading v{{ row.request.version_number }}: {{ row.excuse_reason }}.</template>
        <template v-else>The request to read v{{ row.request.version_number }} was closed before you confirmed it.</template>
      </li>
    </ul>

    <form v-if="isFormOpen && audience" class="org-inline-form" @submit.prevent="send">
      <h3 class="org-subheading">Ask people to read and confirm the current version</h3>
      <AcknowledgementTargetPicker v-model="selection" :audience="audience" id-prefix="ack-send" />
      <label for="ack-message">Message (optional)</label>
      <textarea id="ack-message" v-model="message" rows="2" maxlength="4000" placeholder="Why this matters, what to pay attention to"></textarea>
      <div class="inline-fields">
        <div><label for="ack-due">Deadline (optional)</label><input id="ack-due" v-model="dueOn" type="date" :min="today" /></div>
      </div>
      <label class="pm-all-projects-toggle"><input v-model="requireOpen" type="checkbox" /> People must open the document before they can confirm</label>
      <label class="pm-all-projects-toggle"><input v-model="addJoiners" type="checkbox" /> Add people who later join the chosen units or positions</label>
      <div class="org-actions">
        <button class="create-button" type="submit" :disabled="isSaving || selectionSize(selection) === 0">{{ isSaving ? "Sending..." : "Send" }}</button>
        <button class="edit-button" type="button" @click="isFormOpen = false">Cancel</button>
      </div>
    </form>

    <template v-if="view?.requests.length">
      <h3 class="org-subheading">Requests</h3>
      <ul class="dms-list">
        <li v-for="request in view.requests" :key="request.id" class="dms-row" :class="{ 'is-overdue': request.is_overdue }">
          <a class="dms-row-main" :href="detailHref('acknowledgement', request.id)">
            <span class="dms-number">v{{ request.version_number }} · {{ request.status === "open" ? "Open" : "Closed" }}<template v-if="request.is_partial"> · your units</template></span>
            <strong>{{ progressText(request.counts) }}</strong>
            <span class="ack-bar" role="img" :aria-label="`${request.counts.completion_percent}% complete`"><span :style="{ width: barWidth(request.counts) }"></span></span>
            <span class="muted">Sent by {{ request.sender }} on {{ formatWhen(request.created_at) }}<template v-if="request.due_on"> · due {{ formatDay(request.due_on) }}</template></span>
          </a>
          <span class="dms-row-meta"><span class="status-pill" :class="request.counts.completion_percent === 100 ? 'dms-tone-done' : request.is_overdue ? 'dms-tone-attention' : 'dms-tone-progress'">{{ request.counts.completion_percent }}%</span></span>
        </li>
      </ul>
    </template>
    <p v-else-if="view?.can_send && !isFormOpen && openRows.length === 0 && pastRows.length === 0" class="muted">Nobody has been asked to acknowledge this document yet.</p>
  </section>
</template>
