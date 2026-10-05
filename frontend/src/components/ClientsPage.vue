<script setup lang="ts">
import { onMounted, ref } from "vue";
import ClientActivityPanel from "./ClientActivityPanel.vue";
import ClientContactsPanel from "./ClientContactsPanel.vue";
import EntityNotesPanel from "./EntityNotesPanel.vue";

type Client = {
  id: number;
  title: string;
  notes: string;
  created_at: string;
  updated_at: string;
};

type ProjectSummary = { id: number; title: string; status: string };
type ClientInput = Pick<Client, "title" | "notes">;

const isFormVisible = ref(false);
const clients = ref<Client[]>([]);
const projectsByClient = ref<Record<number, ProjectSummary[]>>({});
const editingId = ref<number | null>(null);
const title = ref("");
const notes = ref("");
const errorMessage = ref("");
const isLoading = ref(true);
const isSaving = ref(false);
const deletingId = ref<number | null>(null);

function formatClientDate(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

function wasClientUpdated(client: Client) {
  return new Date(client.updated_at).getTime() > new Date(client.created_at).getTime();
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = localStorage.getItem("crm_token");
  const response = await fetch(`/api${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });

  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: string } | null;
    throw new Error(payload?.error ?? "Unable to complete the client request.");
  }

  return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}

async function loadClients() {
  errorMessage.value = "";
  isLoading.value = true;

  try {
    const [loadedClients, projects] = await Promise.all([
      request<Client[]>("/clients"),
      request<(ProjectSummary & { client_id: number | null })[]>("/projects"),
    ]);
    clients.value = loadedClients;
    projectsByClient.value = projects.reduce<Record<number, ProjectSummary[]>>((grouped, project) => {
      if (project.client_id === null) return grouped;
      grouped[project.client_id] ??= [];
      grouped[project.client_id].push(project);
      return grouped;
    }, {});
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load clients.";
  } finally {
    isLoading.value = false;
  }
}

onMounted(loadClients);

function openCreateForm() {
  editingId.value = null;
  title.value = "";
  notes.value = "";
  errorMessage.value = "";
  isFormVisible.value = true;
}

function editClient(client: Client) {
  editingId.value = client.id;
  title.value = client.title;
  notes.value = client.notes;
  errorMessage.value = "";
  isFormVisible.value = true;
}

async function saveClient() {
  const client: ClientInput = {
    title: title.value.trim(),
    notes: notes.value.trim(),
  };

  if (!client.title || !client.notes) return;

  errorMessage.value = "";
  isSaving.value = true;

  try {
    const savedClient = await request<Client>(editingId.value === null ? "/clients" : `/clients/${editingId.value}`, {
      method: editingId.value === null ? "POST" : "PUT",
      body: JSON.stringify(client),
    });

    if (editingId.value === null) clients.value.unshift(savedClient);
    else {
      const clientIndex = clients.value.findIndex((item) => item.id === editingId.value);
      if (clientIndex !== -1) clients.value[clientIndex] = savedClient;
    }

    isFormVisible.value = false;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to save the client.";
  } finally {
    isSaving.value = false;
  }
}

async function deleteClient(client: Client) {
  if (!window.confirm(`Delete "${client.title}"?`)) return;

  errorMessage.value = "";
  deletingId.value = client.id;

  try {
    await request(`/clients/${client.id}`, { method: "DELETE" });
    clients.value = clients.value.filter((item) => item.id !== client.id);
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to delete the client.";
  } finally {
    deletingId.value = null;
  }
}
</script>

<template>
  <div class="page-content">
    <div class="page-heading">
      <div>
        <p class="eyebrow">Clients</p>
        <h1>Your clients.</h1>
      </div>
      <button class="create-button" type="button" @click="openCreateForm">+ <span>Add client</span></button>
    </div>

    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading clients...</h2></div>

    <form v-else-if="isFormVisible" class="article-form client-form" @submit.prevent="saveClient">
      <div class="article-form-heading">
        <div>
          <p class="card-kicker">{{ editingId === null ? "New client" : "Edit client" }}</p>
          <h2>{{ editingId === null ? "Add a relationship." : "Update client details." }}</h2>
        </div>
        <button class="close-button" type="button" aria-label="Close client form" @click="isFormVisible = false">
          &#10005;
        </button>
      </div>

      <label for="client-title">Title</label>
      <input id="client-title" v-model="title" type="text" placeholder="Acme Co." required />

      <label for="client-notes">Notes</label>
      <textarea id="client-notes" v-model="notes" rows="7" placeholder="Add key context about this client..." required></textarea>

      <button class="button article-submit" type="submit" :disabled="isSaving">
        {{ isSaving ? "Saving..." : editingId === null ? "Create client" : "Save changes" }}
        <span aria-hidden="true">&#8594;</span>
      </button>
    </form>

    <div v-else-if="clients.length === 0" class="empty-page">
      <span class="empty-icon" aria-hidden="true">&#9673;</span>
      <h2>Client relationships in one place.</h2>
      <p class="muted">Add the first client to keep your pipeline organized.</p>
    </div>

    <div v-else class="article-list">
      <article v-for="client in clients" :key="client.id" class="article-item">
        <div>
          <p class="card-kicker">
            {{ formatClientDate(client.created_at) }}
            <span v-if="wasClientUpdated(client)"> (updated {{ formatClientDate(client.updated_at) }})</span>
          </p>
          <h2>{{ client.title }}</h2>
          <p class="muted">{{ client.notes }}</p>
          <div v-if="projectsByClient[client.id]?.length" class="client-project-list">
            <p class="card-kicker">Projects</p>
            <ul>
              <li v-for="project in projectsByClient[client.id]" :key="project.id">
                {{ project.title }} <span class="muted">· {{ project.status.replace("_", " ") }}</span>
              </li>
            </ul>
          </div>
          <p v-else class="card-kicker">No projects linked yet</p>
          <div class="client-detail-summary">
            <span class="card-kicker">Client detail</span>
            <div class="client-metrics">
              <strong>{{ projectsByClient[client.id]?.length ?? 0 }}</strong>
              <span>linked projects</span>
            </div>
          </div>
          <ClientContactsPanel :client-id="client.id" />
          <EntityNotesPanel :entity-type="'client'" :entity-id="client.id" />
          <ClientActivityPanel :client-id="client.id" :client-title="client.title" />
        </div>

        <div class="article-actions">
          <button class="edit-button" type="button" @click="editClient(client)">Edit</button>
          <button class="edit-button" type="button" :disabled="deletingId === client.id" @click="deleteClient(client)">
            {{ deletingId === client.id ? "Deleting..." : "Delete" }}
          </button>
        </div>
      </article>
    </div>
  </div>
</template>
