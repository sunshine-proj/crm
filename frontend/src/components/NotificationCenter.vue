<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { detailHref } from "../lib/route";

type Notice = { id: number; kind: "due_soon" | "overdue" | "escalation"; subject_type: string; subject_id: number; project_id: number | null; message: string; created_at: string; read_at: string | null };
type Preferences = { enabled: boolean; task_lead_days: number; project_lead_days: number; escalate_after_days: number; delivery: string };

const isOpen = ref(false);
const items = ref<Notice[]>([]);
const unread = ref(0);
const preferences = ref<Preferences | null>(null);
const showPreferences = ref(false);
const isSaving = ref(false);
const errorMessage = ref("");
const savedMessage = ref("");
let timer: number | undefined;

async function refresh() {
  try {
    const inbox = await apiRequest<{ unread_count: number; items: Notice[] }>("/notifications?limit=30");
    items.value = inbox.items;
    unread.value = inbox.unread_count;
  } catch {
    // The bell is a convenience; a failed poll must not interrupt other work.
  }
}

async function toggle() {
  isOpen.value = !isOpen.value;
  if (isOpen.value) await refresh();
}

async function markRead(notice: Notice) {
  if (notice.read_at) return;
  try {
    Object.assign(notice, await apiRequest<Notice>(`/notifications/${notice.id}/read`, { method: "POST" }));
    unread.value = Math.max(0, unread.value - 1);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to update the notification.");
  }
}

async function markAllRead() {
  try {
    await apiRequest("/notifications/read-all", { method: "POST" });
    await refresh();
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to update notifications.");
  }
}

async function openPreferences() {
  showPreferences.value = !showPreferences.value;
  savedMessage.value = "";
  if (showPreferences.value && !preferences.value) {
    try {
      preferences.value = await apiRequest<Preferences>("/notifications/preferences");
    } catch (error) {
      errorMessage.value = errorText(error, "Unable to load reminder settings.");
    }
  }
}

async function savePreferences() {
  if (!preferences.value) return;
  isSaving.value = true;
  errorMessage.value = "";
  try {
    preferences.value = await apiRequest<Preferences>("/notifications/preferences", { method: "PUT", body: JSON.stringify(preferences.value) });
    savedMessage.value = "Reminder settings saved.";
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to save reminder settings.");
  } finally {
    isSaving.value = false;
  }
}

function link(notice: Notice): string {
  return notice.project_id === null ? "#" : detailHref("project", notice.project_id);
}

onMounted(() => {
  void refresh();
  timer = window.setInterval(refresh, 120_000);
});
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
  <div class="notification-center">
    <button class="notification-toggle" type="button" :aria-expanded="isOpen" aria-haspopup="dialog" @click="toggle">
      <span aria-hidden="true">&#128276;</span>
      <span class="visually-hidden">Notifications</span>
      <span v-if="unread > 0" class="notification-badge" :aria-label="`${unread} unread`">{{ unread }}</span>
    </button>

    <div v-if="isOpen" class="notification-panel" role="dialog" aria-label="Notifications">
      <div class="pm-filters">
        <strong>Notifications</strong>
        <button class="edit-button" type="button" :disabled="unread === 0" @click="markAllRead">Mark all read</button>
        <button class="edit-button" type="button" @click="openPreferences">{{ showPreferences ? "Hide settings" : "Reminder settings" }}</button>
      </div>
      <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>

      <form v-if="showPreferences && preferences" class="detail-card" @submit.prevent="savePreferences">
        <label class="pm-all-projects-toggle"><input v-model="preferences.enabled" type="checkbox" /> Remind me about my work</label>
        <label for="pref-task">Remind me about tasks this many days before they are due</label>
        <input id="pref-task" v-model.number="preferences.task_lead_days" type="number" min="0" max="60" required />
        <label for="pref-project">Remind me about projects this many days before the planned end</label>
        <input id="pref-project" v-model.number="preferences.project_lead_days" type="number" min="0" max="60" required />
        <label for="pref-escalate">Escalate to the manager after this many days overdue (0 = never)</label>
        <input id="pref-escalate" v-model.number="preferences.escalate_after_days" type="number" min="0" max="60" required />
        <p class="muted">Delivery is in-app only; reminders are generated hourly. E-mail delivery is not configured.</p>
        <button class="button" type="submit" :disabled="isSaving">{{ isSaving ? "Saving..." : "Save settings" }}</button>
        <p v-if="savedMessage" class="settings-notice" role="status">{{ savedMessage }}</p>
      </form>

      <p v-if="items.length === 0" class="muted">You have no notifications.</p>
      <ul v-else>
        <li v-for="notice in items" :key="notice.id" :class="[notice.kind, { unread: !notice.read_at }]">
          <a :href="link(notice)" @click="markRead(notice)"><strong>{{ notice.kind === "escalation" ? "Escalation" : notice.kind === "overdue" ? "Overdue" : "Due soon" }}</strong> {{ notice.message }}</a>
          <div class="muted">{{ new Date(notice.created_at).toLocaleString() }}</div>
        </li>
      </ul>
    </div>
  </div>
</template>
