<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { statusLabels } from "../lib/documents";
import { stepKindLabels, type StepKind } from "../lib/approvals";
import { formatDuration, statusMatrix, type DmsReport } from "../lib/records";

const today = new Date();
const iso = (day: Date) => day.toISOString().slice(0, 10);
const from = ref(iso(new Date(today.getTime() - 90 * 86400000)));
const to = ref(iso(today));
const report = ref<DmsReport | null>(null);
const isLoading = ref(false);
const errorMessage = ref("");

const matrix = computed(() => (report.value ? statusMatrix(report.value.documents.rows) : null));

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    report.value = await apiRequest<DmsReport>(`/dms/reports?from=${from.value}&to=${to.value}`);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to build the reports.");
  } finally {
    isLoading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <section aria-labelledby="reports-title">
    <h2 id="reports-title" class="visually-hidden">Registry reports</h2>
    <form class="inline-fields audit-filters" @submit.prevent="load">
      <div><label for="report-from">From</label><input id="report-from" v-model="from" type="date" required /></div>
      <div><label for="report-to">To</label><input id="report-to" v-model="to" type="date" required /></div>
      <div class="org-actions"><button class="edit-button" type="submit" :disabled="isLoading">Update</button></div>
    </form>
    <p class="muted org-hint">The period applies to approval times and acknowledgement requests; document counts and overdue approvals are as of today.</p>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="isLoading" class="muted">Loading...</p>

    <div v-if="report && matrix" class="detail-grid">
      <section class="detail-card wide" aria-labelledby="report-docs">
        <h3 id="report-docs">Documents by type and status <span class="board-count">{{ report.documents.total }}</span></h3>
        <div class="report-scroll">
          <table class="ack-units">
            <thead><tr><th scope="col">Type</th><th v-for="status in matrix.statuses" :key="status" scope="col">{{ statusLabels[status] }}</th><th scope="col">Total</th></tr></thead>
            <tbody>
              <tr v-for="row in matrix.types" :key="row.type"><th scope="row">{{ row.type }}</th><td v-for="status in matrix.statuses" :key="status">{{ row.counts[status] ?? "" }}</td><td>{{ row.total }}</td></tr>
            </tbody>
          </table>
        </div>
        <details class="approval-log">
          <summary>By owner unit</summary>
          <table class="ack-units">
            <thead><tr><th scope="col">Type</th><th scope="col">Unit</th><th scope="col">Status</th><th scope="col">Count</th></tr></thead>
            <tbody><tr v-for="(row, index) in report.documents.rows" :key="index"><td>{{ row.type }}</td><td>{{ row.unit ?? "No unit" }}</td><td>{{ statusLabels[row.status] }}</td><td>{{ row.count }}</td></tr></tbody>
          </table>
        </details>
      </section>

      <section class="detail-card" aria-labelledby="report-times">
        <h3 id="report-times">Approval time per step</h3>
        <p v-if="report.approval_time.by_kind.length === 0" class="muted">No steps were decided in this period.</p>
        <table v-else class="ack-units">
          <thead><tr><th scope="col">Step</th><th scope="col">Decided</th><th scope="col">Average</th><th scope="col">Median</th><th scope="col">Longest</th></tr></thead>
          <tbody><tr v-for="row in report.approval_time.by_kind" :key="row.kind"><th scope="row">{{ stepKindLabels[row.kind as StepKind] ?? row.kind }}</th><td>{{ row.decided }}</td><td>{{ formatDuration(row.mean_hours) }}</td><td>{{ formatDuration(row.median_hours) }}</td><td>{{ formatDuration(row.max_hours) }}</td></tr></tbody>
        </table>
        <details v-if="report.approval_time.by_approver.length" class="approval-log">
          <summary>Per approver</summary>
          <table class="ack-units">
            <thead><tr><th scope="col">Approver</th><th scope="col">Decided</th><th scope="col">Average</th><th scope="col">Median</th></tr></thead>
            <tbody><tr v-for="row in report.approval_time.by_approver" :key="row.approver"><th scope="row">{{ row.approver }}</th><td>{{ row.decided }}</td><td>{{ formatDuration(row.mean_hours) }}</td><td>{{ formatDuration(row.median_hours) }}</td></tr></tbody>
          </table>
        </details>
      </section>

      <section class="detail-card" aria-labelledby="report-overdue">
        <h3 id="report-overdue">Overdue approvals</h3>
        <p v-if="report.overdue_approvals.length === 0" class="muted">No approval is overdue.</p>
        <table v-else class="ack-units">
          <thead><tr><th scope="col">Approver</th><th scope="col">Overdue</th><th scope="col">Oldest due</th><th scope="col">Days late</th></tr></thead>
          <tbody><tr v-for="row in report.overdue_approvals" :key="row.approver"><th scope="row">{{ row.approver }}</th><td class="approval-overdue">{{ row.overdue }}</td><td>{{ row.oldest_due_on }}</td><td>{{ row.max_days_overdue }}</td></tr></tbody>
        </table>
      </section>

      <section class="detail-card wide" aria-labelledby="report-ack">
        <h3 id="report-ack">Acknowledgement completion per unit <span class="board-count">{{ report.acknowledgement.requests }} request(s)</span></h3>
        <p v-if="report.acknowledgement.by_unit.length === 0" class="muted">No acknowledgement requests were sent in this period.</p>
        <table v-else class="ack-units">
          <thead><tr><th scope="col">Unit</th><th scope="col">Done</th><th scope="col">Confirmed</th><th scope="col">Excused</th><th scope="col">Still open</th><th scope="col">Overdue</th></tr></thead>
          <tbody>
            <tr v-for="row in report.acknowledgement.by_unit" :key="row.unit">
              <th scope="row">{{ row.unit }}</th>
              <td><span class="ack-bar" role="img" :aria-label="`${row.completion_percent}% complete`"><span :style="{ width: `${row.completion_percent}%` }"></span></span> {{ row.completion_percent }}%</td>
              <td>{{ row.acknowledged }} / {{ row.total - row.excused }}</td><td>{{ row.excused }}</td><td>{{ row.open }}</td><td :class="{ 'approval-overdue': row.overdue }">{{ row.overdue }}</td>
            </tr>
          </tbody>
        </table>
      </section>
    </div>
  </section>
</template>
