<script setup lang="ts">
import { hasModule, whenModule } from "../lib/modules";
import { onMounted, ref } from "vue";
import { apiRequest } from "../lib/api";

type DashboardSummary = {
  client_count: number;
  active_project_count: number;
  open_task_count: number;
};

const summary = ref<DashboardSummary | null>(null);
const errorMessage = ref("");
const isLoading = ref(true);
const documentCounts = ref<{ toAcknowledge: number; awaitingDecision: number } | null>(null);

async function loadDocumentCounts() {
  try {
    const [acknowledgements, approvals] = await Promise.all([
      whenModule("document_management", () => apiRequest<{ to_acknowledge: number }>("/dms/acknowledgements/counts"), { to_acknowledge: 0 }),
      whenModule("document_management", () => apiRequest<{ awaiting: number }>("/dms/approvals/counts"), { awaiting: 0 }),
    ]);
    documentCounts.value = { toAcknowledge: acknowledgements.to_acknowledge, awaitingDecision: approvals.awaiting };
  } catch {
    // The document counters are a convenience; the Documents section shows the real lists.
  }
}

async function loadSummary() {
  try {
    const token = localStorage.getItem("crm_token");
    const response = await fetch("/api/dashboard/summary", {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    });
    if (!response.ok) throw new Error("Unable to load the dashboard summary.");
    summary.value = (await response.json()) as DashboardSummary;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load the dashboard summary.";
  } finally {
    isLoading.value = false;
  }
}

onMounted(() => {
  void loadSummary();
  void loadDocumentCounts();
});
</script>

<template>
  <div class="page-content">
    <div class="page-heading">
      <div>
        <p class="eyebrow">Dashboard</p>
        <h1>Good to see you.</h1>
      </div>
      <span class="access-badge">Authorized</span>
    </div>
    <div class="dashboard-layout">
      <article class="welcome-card">
        <p class="card-kicker">Today at a glance</p>
        <h2>Your clients and projects, in focus.</h2>
        <p class="muted">See the work in progress and the next steps across your client relationships.</p>
      </article>
      <div class="metric-list">
        <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
        <div v-if="hasModule('clients')"><span>Clients</span><strong>{{ isLoading ? "..." : summary?.client_count ?? 0 }}</strong></div>
        <div v-if="hasModule('projects')"><span>Active projects</span><strong>{{ isLoading ? "..." : summary?.active_project_count ?? 0 }}</strong></div>
        <div v-if="hasModule('projects')"><span>Open tasks</span><strong>{{ isLoading ? "..." : summary?.open_task_count ?? 0 }}</strong></div>
        <div v-if="documentCounts && hasModule('document_management')"><span>Documents to acknowledge</span><strong>{{ documentCounts.toAcknowledge }}</strong></div>
        <div v-if="documentCounts && hasModule('document_management')"><span>Documents awaiting my approval</span><strong>{{ documentCounts.awaitingDecision }}</strong></div>
      </div>
    </div>
  </div>
</template>
