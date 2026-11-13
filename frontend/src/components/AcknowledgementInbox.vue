<script setup lang="ts">
import { onMounted, ref } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { detailHref } from "../lib/route";
import { recipientStatusLabels, statusTone, type AckMine } from "../lib/acknowledgements";

const emit = defineEmits<{ counted: [count: number] }>();

const mode = ref<"open" | "history">("open");
const rows = ref<AckMine[]>([]);
const isLoading = ref(true);
const errorMessage = ref("");

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    rows.value = await apiRequest<AckMine[]>(mode.value === "open" ? "/dms/acknowledgements/inbox" : "/dms/acknowledgements/history");
    if (mode.value === "open") emit("counted", rows.value.length);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load your acknowledgements.");
  } finally {
    isLoading.value = false;
  }
}

function show(next: "open" | "history") {
  mode.value = next;
  void load();
}

function formatDay(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(new Date(value.length === 10 ? `${value}T00:00:00` : value));
}

onMounted(load);
</script>

<template>
  <section aria-labelledby="ack-inbox-title">
    <h2 id="ack-inbox-title" class="visually-hidden">Documents to acknowledge</h2>
    <div class="org-actions">
      <button class="edit-button" type="button" :aria-pressed="mode === 'open'" @click="show('open')">To acknowledge</button>
      <button class="edit-button" type="button" :aria-pressed="mode === 'history'" @click="show('history')">My history</button>
    </div>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading...</h2></div>
    <div v-else-if="rows.length === 0" class="empty-page">
      <span class="empty-icon" aria-hidden="true">&#10003;</span>
      <h2>{{ mode === "open" ? "Nothing to acknowledge." : "You have not been asked to acknowledge anything yet." }}</h2>
      <p class="muted">Documents your managers ask you to read and confirm appear here.</p>
    </div>
    <ul v-else class="dms-list">
      <li v-for="row in rows" :key="row.id" class="dms-row" :class="{ 'is-overdue': row.is_overdue }">
        <a class="dms-row-main" :href="detailHref('document', row.request.document.id)">
          <span class="dms-number">{{ row.request.document.registration_number ?? row.request.document.type }} · v{{ row.request.version_number }}</span>
          <strong>{{ row.request.document.title }}</strong>
          <span class="muted">
            Asked by {{ row.request.sender }}
            <template v-if="row.acknowledged_at"> · confirmed {{ formatDay(row.acknowledged_at) }}<template v-if="row.is_late"> (late)</template></template>
            <template v-else-if="row.request.status === 'closed' && row.status !== 'excused'"> · closed without confirmation</template>
            <template v-if="row.needs_opening"> · open it before confirming</template>
          </span>
        </a>
        <span class="dms-row-meta">
          <span v-if="row.request.document.confidentiality === 'confidential'" class="org-kind">Confidential</span>
          <span v-if="mode === 'history'" class="status-pill" :class="statusTone(row)">{{ recipientStatusLabels[row.status] }}</span>
          <span v-if="row.request.due_on && mode === 'open'" class="status-pill" :class="row.is_overdue ? 'dms-tone-attention' : 'dms-tone-progress'">{{ row.is_overdue ? "Overdue since" : "Due" }} {{ formatDay(row.request.due_on) }}</span>
        </span>
      </li>
    </ul>
  </section>
</template>
