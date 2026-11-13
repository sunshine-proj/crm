<script setup lang="ts">
import { onMounted, ref } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { detailHref } from "../lib/route";
import { stepKindLabels, type InboxRow } from "../lib/approvals";

const emit = defineEmits<{ counted: [count: number] }>();

const rows = ref<InboxRow[]>([]);
const isLoading = ref(true);
const errorMessage = ref("");

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    rows.value = await apiRequest<InboxRow[]>("/dms/approvals/inbox");
    emit("counted", rows.value.length);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load your approvals.");
  } finally {
    isLoading.value = false;
  }
}

function formatDay(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(new Date(`${value}T00:00:00`));
}

onMounted(load);
</script>

<template>
  <section aria-labelledby="inbox-title">
    <h2 id="inbox-title" class="visually-hidden">Awaiting my decision</h2>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading...</h2></div>
    <div v-else-if="rows.length === 0" class="empty-page">
      <span class="empty-icon" aria-hidden="true">&#10003;</span>
      <h2>Nothing awaits your decision.</h2>
      <p class="muted">Documents sent to you for review, approval or signature appear here.</p>
    </div>
    <ul v-else class="dms-list">
      <li v-for="row in rows" :key="row.id" class="dms-row" :class="{ 'is-overdue': row.is_overdue }">
        <a class="dms-row-main" :href="detailHref('document', row.document.id)">
          <span class="dms-number">{{ stepKindLabels[row.kind] }}<template v-if="row.acting_for"> · for {{ row.acting_for }}</template></span>
          <strong>{{ row.document.title }}</strong>
          <span class="muted">
            {{ row.document.type }} · from {{ row.document.author }}
            <template v-if="row.requested_by"> · asked by {{ row.requested_by }}</template>
            <template v-else> · stage {{ row.stage_position }} of {{ row.stage_count }}</template>
            <template v-if="row.round > 1"> · round {{ row.round }}</template>
          </span>
        </a>
        <span class="dms-row-meta">
          <span v-if="row.document.confidentiality === 'confidential'" class="org-kind">Confidential</span>
          <span v-if="row.due_on" class="status-pill" :class="row.is_overdue ? 'dms-tone-attention' : 'dms-tone-progress'">{{ row.is_overdue ? "Overdue since" : "Due" }} {{ formatDay(row.due_on) }}</span>
        </span>
      </li>
    </ul>
  </section>
</template>
