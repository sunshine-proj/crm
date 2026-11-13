<script setup lang="ts">
import { ref } from "vue";
import { apiRequest, downloadFile, errorText } from "../lib/api";
import { auditActionFilters, auditActionLabel, auditDetails, auditQuery, type AuditFilters, type AuditTrail } from "../lib/records";

const props = defineProps<{ documentId: number }>();

const trail = ref<AuditTrail | null>(null);
const filters = ref<AuditFilters>({ action: "", actor: "", from: "", to: "" });
const isLoading = ref(false);
const errorMessage = ref("");

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    trail.value = await apiRequest<AuditTrail>(`/dms/documents/${props.documentId}/audit${auditQuery(filters.value)}`);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load the audit trail.");
  } finally {
    isLoading.value = false;
  }
}

function exportCsv() {
  downloadFile(`/dms/documents/${props.documentId}/audit${auditQuery(filters.value, "csv")}`, `document-${props.documentId}-audit.csv`).catch((error: unknown) => (errorMessage.value = errorText(error, "Unable to export.")));
}

function formatWhen(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "medium" }).format(new Date(value));
}
</script>

<template>
  <section class="detail-card wide" aria-labelledby="audit-title">
    <details @toggle="($event.target as HTMLDetailsElement).open && !trail && load()">
      <summary><h2 id="audit-title" class="records-summary-heading">Audit trail</h2></summary>
      <p class="muted org-hint">Every view, download, version, access change, approval action, acknowledgement, legal hold and disposal step, oldest first. Only the author and registry clerks see it.</p>
      <form class="inline-fields audit-filters" @submit.prevent="load">
        <div>
          <label for="audit-action">Action</label>
          <select id="audit-action" v-model="filters.action">
            <option v-for="option in auditActionFilters" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </div>
        <div><label for="audit-actor">Person</label><input id="audit-actor" v-model="filters.actor" type="search" placeholder="E-mail contains" /></div>
        <div><label for="audit-from">From</label><input id="audit-from" v-model="filters.from" type="date" /></div>
        <div><label for="audit-to">To</label><input id="audit-to" v-model="filters.to" type="date" /></div>
        <div class="org-actions">
          <button class="edit-button" type="submit" :disabled="isLoading">Filter</button>
          <button class="edit-button" type="button" @click="exportCsv">Export CSV</button>
        </div>
      </form>
      <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
      <p v-if="isLoading" class="muted">Loading...</p>
      <template v-else-if="trail">
        <p class="muted">{{ trail.total }} entr{{ trail.total === 1 ? "y" : "ies" }}<template v-if="trail.truncated"> (showing the first {{ trail.entries.length }}; narrow the filters or export)</template>.</p>
        <ol class="detail-list dms-history">
          <li v-for="(entry, index) in trail.entries" :key="index">
            <strong>{{ auditActionLabel(entry.action) }}</strong>
            <span class="muted"> · {{ entry.actor }}<template v-if="entry.on_behalf_of"> for {{ entry.on_behalf_of }}</template> · {{ formatWhen(entry.at) }}</span>
            <span v-if="auditDetails(entry.details)" class="muted dms-event-detail">{{ auditDetails(entry.details) }}</span>
          </li>
        </ol>
      </template>
    </details>
  </section>
</template>
