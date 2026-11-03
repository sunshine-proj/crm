<script setup lang="ts">
import { onMounted, ref } from "vue";
import { changeSummary, restoreWarning, revisionLabel, type RecordRevision } from "../lib/recordRevisions";

const revisions = ref<RecordRevision[]>([]);
const typeFilter = ref("");
const actionFilter = ref("");
const isLoading = ref(true);
const isRestoring = ref(false);
const errorMessage = ref("");
const noticeMessage = ref("");

const recordTypes = ["client", "client_contact", "project", "project_milestone", "task", "product", "supplier", "warehouse"];

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = localStorage.getItem("crm_token");
  const response = await fetch(`/api${path}`, {
    ...options,
    headers: { "Content-Type": "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}), ...options.headers },
  });
  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: string } | null;
    throw new Error(payload?.error ?? "Unable to complete the record history request.");
  }
  return (await response.json()) as T;
}

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  const query = new URLSearchParams({ limit: "50" });
  if (typeFilter.value) query.set("type", typeFilter.value);
  if (actionFilter.value) query.set("action", actionFilter.value);
  try {
    revisions.value = await request<RecordRevision[]>(`/records/revisions?${query.toString()}`);
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load record history.";
  } finally {
    isLoading.value = false;
  }
}

async function restore(revision: RecordRevision) {
  if (!window.confirm(restoreWarning(revision))) return;
  isRestoring.value = true;
  errorMessage.value = "";
  noticeMessage.value = "";
  try {
    const result = await request<{ record_id: number; recreated: boolean }>(`/records/revisions/${revision.id}/restore`, { method: "POST" });
    noticeMessage.value = result.recreated ? `Record recreated as #${result.record_id}.` : `Record #${result.record_id} rolled back.`;
    await load();
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to restore the record.";
  } finally {
    isRestoring.value = false;
  }
}

function formatDate(value: string): string {
  return new Date(value).toLocaleString();
}

onMounted(load);
</script>

<template>
  <section class="settings-card" aria-labelledby="record-history-heading">
    <div class="settings-card-heading">
      <div>
        <p class="card-kicker">Audit and recovery</p>
        <h2 id="record-history-heading">Record history</h2>
      </div>
    </div>
    <p class="muted">Changes to clients, contacts, projects, milestones, tasks, products, suppliers and warehouses are versioned. Invoices and ledger records are corrected through their own workflows and are not listed here.</p>
    <div class="settings-filters">
      <label>Record type
        <select v-model="typeFilter" @change="load">
          <option value="">All</option>
          <option v-for="type in recordTypes" :key="type" :value="type">{{ type.replaceAll("_", " ") }}</option>
        </select>
      </label>
      <label>Action
        <select v-model="actionFilter" @change="load">
          <option value="">All</option>
          <option value="create">Created</option>
          <option value="update">Updated</option>
          <option value="delete">Deleted</option>
        </select>
      </label>
    </div>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="noticeMessage" class="settings-notice" role="status">{{ noticeMessage }}</p>
    <p v-if="isLoading" class="muted">Loading record history…</p>
    <p v-else-if="revisions.length === 0" class="muted">No record changes match this filter.</p>
    <ol v-else class="settings-history">
      <li v-for="revision in revisions" :key="revision.id">
        <div>
          <strong>{{ revisionLabel(revision) }}</strong>
          <span>{{ changeSummary(revision) }} · {{ formatDate(revision.created_at) }} by {{ revision.actor }}</span>
        </div>
        <button class="settings-restore" type="button" :disabled="isRestoring" @click="restore(revision)">
          {{ revision.action === "delete" ? "Recreate" : "Restore this version" }}
        </button>
      </li>
    </ol>
  </section>
</template>
