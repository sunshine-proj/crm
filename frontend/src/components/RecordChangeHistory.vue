<script setup lang="ts">
import { onMounted, ref, watch } from "vue";
import { apiRequest, errorText } from "../lib/api";

const props = defineProps<{ recordType: string; recordId: number }>();

type Change = { id: number; action: "create" | "update" | "delete"; actor: string; changed_fields: string[]; created_at: string };

const changes = ref<Change[]>([]);
const isLoading = ref(true);
const errorMessage = ref("");

function humanize(field: string): string {
  return field.replace(/([A-Z])/g, " $1").toLocaleLowerCase();
}

function describe(change: Change): string {
  if (change.action === "create") return "Created";
  if (change.action === "delete") return "Deleted";
  return change.changed_fields.length ? `Changed ${change.changed_fields.map(humanize).join(", ")}` : "Updated";
}

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    changes.value = await apiRequest<Change[]>(`/history/${props.recordType}/${props.recordId}`);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load change history.");
  } finally {
    isLoading.value = false;
  }
}

onMounted(load);
watch(() => [props.recordType, props.recordId], load);
</script>

<template>
  <section class="detail-card" aria-label="Change history">
    <h2>Change history</h2>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-else-if="isLoading" class="muted">Loading history...</p>
    <p v-else-if="changes.length === 0" class="muted">No changes recorded yet.</p>
    <ul v-else class="detail-list">
      <li v-for="change in changes" :key="change.id">
        {{ describe(change) }}
        <span class="muted"> · {{ new Date(change.created_at).toLocaleString() }} · {{ change.actor }}</span>
      </li>
    </ul>
  </section>
</template>
