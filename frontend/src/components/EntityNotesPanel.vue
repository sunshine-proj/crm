<script setup lang="ts">
import { ref } from "vue";

const props = defineProps<{
  entityType: "client" | "project" | "task";
  entityId: number;
}>();

type Note = {
  id: number;
  entity_type: string;
  entity_id: number;
  content: string;
  author_email: string | null;
  created_at: string;
  updated_at: string;
};

const notes = ref<Note[]>([]);
const isOpen = ref(false);
const isLoaded = ref(false);
const isLoading = ref(false);
const isSaving = ref(false);
const isFormVisible = ref(false);
const content = ref("");
const errorMessage = ref("");

function formatNoteDate(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

async function request<T>(path = "", options: RequestInit = {}): Promise<T> {
  const token = localStorage.getItem("crm_token");
  const response = await fetch(`/api/notes/${props.entityType}/${props.entityId}${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });

  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: string } | null;
    throw new Error(payload?.error ?? "Unable to complete the note request.");
  }

  return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}

async function loadNotes() {
  errorMessage.value = "";
  isLoading.value = true;

  try {
    notes.value = await request<Note[]>();
    isLoaded.value = true;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load notes.";
  } finally {
    isLoading.value = false;
  }
}

async function toggleNotes() {
  isOpen.value = !isOpen.value;
  if (isOpen.value && !isLoaded.value) await loadNotes();
}

function openCreateForm() {
  content.value = "";
  errorMessage.value = "";
  isFormVisible.value = true;
}

function closeForm() {
  if (!isSaving.value) isFormVisible.value = false;
}

async function saveNote() {
  const trimmed = content.value.trim();
  if (!trimmed) {
    errorMessage.value = "Write a note before saving.";
    return;
  }

  errorMessage.value = "";
  isSaving.value = true;

  try {
    const saved = await request<Note>("", {
      method: "POST",
      body: JSON.stringify({ content: trimmed }),
    });

    notes.value.push(saved);
    isFormVisible.value = false;
    content.value = "";
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to save the note.";
  } finally {
    isSaving.value = false;
  }
}
</script>

<template>
  <section class="contact-panel" :aria-label="`Notes for ${entityType} ${entityId}`">
    <button class="edit-button" type="button" :aria-expanded="isOpen" @click="toggleNotes">
      {{ isOpen ? "Hide notes" : "View notes" }}
    </button>

    <div v-if="isOpen" class="contact-panel-content">
      <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
      <p v-if="isLoading" class="muted">Loading notes...</p>

      <div v-else-if="isFormVisible" class="article-form contact-form">
        <div class="article-form-heading">
          <div>
            <p class="card-kicker">New note</p>
            <h3>Add project context.</h3>
          </div>
          <button class="close-button" type="button" aria-label="Close note form" @click="closeForm">&#10005;</button>
        </div>

        <label :for="`note-content-${entityType}-${entityId}`">Details</label>
        <textarea :id="`note-content-${entityType}-${entityId}`" v-model="content" rows="5" maxlength="5000" required></textarea>

        <div class="task-form-actions">
          <button class="button article-submit" type="button" :disabled="isSaving" @click="saveNote">
            {{ isSaving ? "Saving..." : "Save note" }}
          </button>
          <button class="edit-button" type="button" :disabled="isSaving" @click="closeForm">Cancel</button>
        </div>
      </div>

      <div v-else>
        <button class="edit-button" type="button" @click="openCreateForm">Add note</button>
        <p v-if="notes.length === 0" class="muted">No notes yet.</p>
        <ul v-else class="contact-list">
          <li v-for="note in notes" :key="note.id" class="contact-row">
            <div>
              <small class="card-kicker">
                {{ note.author_email ?? "System" }} · {{ formatNoteDate(note.created_at) }}
              </small>
              <p>{{ note.content }}</p>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </section>
</template>
