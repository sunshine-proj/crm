<script setup lang="ts">
import { onMounted, ref } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { detailHref } from "../lib/route";
import { barWidth, progressText, type AckRequest } from "../lib/acknowledgements";

const status = ref<"open" | "closed" | "">("open");
const rows = ref<AckRequest[]>([]);
const isLoading = ref(true);
const errorMessage = ref("");

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    rows.value = await apiRequest<AckRequest[]>(`/dms/acknowledgements${status.value ? `?status=${status.value}` : ""}`);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load acknowledgement requests.");
  } finally {
    isLoading.value = false;
  }
}

function formatDay(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(new Date(value.length === 10 ? `${value}T00:00:00` : value));
}

onMounted(load);
</script>

<template>
  <section aria-labelledby="ack-campaigns-title">
    <div class="org-row">
      <h2 id="ack-campaigns-title" class="visually-hidden">Acknowledgement status</h2>
      <p class="muted">Requests you sent, and requests that include people in units you manage.</p>
      <label><span class="visually-hidden">Show</span>
        <select v-model="status" @change="load">
          <option value="open">Open</option>
          <option value="closed">Closed</option>
          <option value="">All</option>
        </select>
      </label>
    </div>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading...</h2></div>
    <div v-else-if="rows.length === 0" class="empty-page">
      <h2>No {{ status }} acknowledgement requests.</h2>
      <p class="muted">Unit heads and registry clerks send registered documents for acknowledgement from the document page.</p>
    </div>
    <ul v-else class="dms-list">
      <li v-for="row in rows" :key="row.id" class="dms-row" :class="{ 'is-overdue': row.is_overdue }">
        <a class="dms-row-main" :href="detailHref('acknowledgement', row.id)">
          <span class="dms-number">{{ row.document.registration_number ?? row.document.type }} · v{{ row.version_number }}<template v-if="row.is_partial"> · your units</template></span>
          <strong>{{ row.document.title }}</strong>
          <span class="ack-bar" role="img" :aria-label="`${row.counts.completion_percent}% complete`"><span :style="{ width: barWidth(row.counts) }"></span></span>
          <span class="muted">{{ progressText(row.counts) }} · sent by {{ row.sender }} {{ formatDay(row.created_at) }}</span>
        </a>
        <span class="dms-row-meta">
          <span v-if="row.status === 'closed'" class="status-pill dms-tone-closed">Closed</span>
          <span v-else-if="row.due_on" class="status-pill" :class="row.is_overdue ? 'dms-tone-attention' : 'dms-tone-progress'">{{ row.is_overdue ? "Overdue since" : "Due" }} {{ formatDay(row.due_on) }}</span>
          <span class="status-pill" :class="row.counts.completion_percent === 100 ? 'dms-tone-done' : 'dms-tone-progress'">{{ row.counts.completion_percent }}%</span>
        </span>
      </li>
    </ul>
  </section>
</template>
