<script setup lang="ts">
import { ref } from "vue";

const props = defineProps<{ clientId: number; clientTitle?: string | null }>();

type Activity = {
  id: number;
  client_id: number;
  type: string;
  message: string;
  author_email: string | null;
  created_at: string;
};

type ActivityInput = {
  type: string;
  message: string;
};

const activities = ref<Activity[]>([]);
const isOpen = ref(false);
const isLoaded = ref(false);
const isLoading = ref(false);
const isSaving = ref(false);
const isFormVisible = ref(false);
const type = ref("update");
const message = ref("");
const errorMessage = ref("");

const activityTypes = [
  { value: "call", label: "Call" },
  { value: "meeting", label: "Meeting" },
  { value: "email", label: "Email" },
  { value: "note", label: "Note" },
  { value: "follow_up", label: "Follow-up" },
  { value: "project_created", label: "Project created" },
  { value: "update", label: "Update" },
];

function formatActivityDate(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

async function request<T>(path = "", options: RequestInit = {}): Promise<T> {
  const token = localStorage.getItem("crm_token");
  const response = await fetch(`/api/clients/${props.clientId}/activities${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });

  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: string } | null;
    throw new Error(payload?.error ?? "Unable to complete the activity request.");
  }

  return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}

async function loadActivities() {
  errorMessage.value = "";
  isLoading.value = true;

  try {
    activities.value = await request<Activity[]>();
    isLoaded.value = true;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load activity history.";
  } finally {
    isLoading.value = false;
  }
}

async function toggleActivities() {
  isOpen.value = !isOpen.value;
  if (isOpen.value && !isLoaded.value) await loadActivities();
}

function openCreateForm() {
  type.value = "update";
  message.value = "";
  errorMessage.value = "";
  isFormVisible.value = true;
}

function closeForm() {
  if (!isSaving.value) isFormVisible.value = false;
}

async function saveActivity() {
  const trimmed = message.value.trim();
  if (!trimmed) {
    errorMessage.value = "Enter a summary for this activity.";
    return;
  }

  errorMessage.value = "";
  isSaving.value = true;

  try {
    const payload: ActivityInput = {
      type: type.value,
      message: trimmed,
    };

    const saved = await request<Activity>("", {
      method: "POST",
      body: JSON.stringify(payload),
    });

    activities.value = [saved, ...activities.value];
    isFormVisible.value = false;
    message.value = "";
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to save the activity.";
  } finally {
    isSaving.value = false;
  }
}
</script>

<template>
  <section class="contact-panel" :aria-label="`Activity history for ${clientTitle ?? 'client'} ${clientId}`">
    <button class="edit-button" type="button" :aria-expanded="isOpen" @click="toggleActivities">
      {{ isOpen ? "Hide activity" : "View activity" }}
    </button>

    <div v-if="isOpen" class="contact-panel-content">
      <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
      <p v-if="isLoading" class="muted">Loading activity history...</p>

      <div v-else-if="isFormVisible" class="article-form contact-form">
        <div class="article-form-heading">
          <div>
            <p class="card-kicker">New activity</p>
            <h3>Log customer interaction.</h3>
          </div>
          <button class="close-button" type="button" aria-label="Close activity form" @click="closeForm">&#10005;</button>
        </div>

        <label :for="`activity-type-${clientId}`">Type</label>
        <select :id="`activity-type-${clientId}`" v-model="type">
          <option v-for="option in activityTypes" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>

        <label :for="`activity-message-${clientId}`">Summary</label>
        <textarea :id="`activity-message-${clientId}`" v-model="message" rows="4" required></textarea>

        <div class="task-form-actions">
          <button class="button article-submit" type="button" :disabled="isSaving" @click="saveActivity">
            {{ isSaving ? "Saving..." : "Save activity" }}
          </button>
          <button class="edit-button" type="button" :disabled="isSaving" @click="closeForm">Cancel</button>
        </div>
      </div>

      <div v-else>
        <button class="edit-button" type="button" @click="openCreateForm">Add activity</button>
        <p v-if="activities.length === 0" class="muted">No recent activity yet.</p>
        <ul v-else class="contact-list">
          <li v-for="item in activities" :key="item.id" class="contact-row">
            <div>
              <small class="card-kicker">
                {{ item.type.replace("_", " ") }} · {{ item.author_email ?? "System" }} · {{ formatActivityDate(item.created_at) }}
              </small>
              <p>{{ item.message }}</p>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </section>
</template>
