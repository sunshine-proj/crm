<script setup lang="ts">
import { onMounted, ref } from "vue";
import { isValidDateRange } from "../lib/planning";

type Project = {
  id: number;
  title: string;
  description: string;
  client_id: number | null;
  client_title: string | null;
  status: ProjectStatus;
  start_date: string | null;
  end_date: string | null;
  created_at: string;
  updated_at: string;
};

type Client = { id: number; title: string };
type ProjectStatus = "planned" | "active" | "on_hold" | "completed" | "cancelled";
type ProjectInput = Pick<Project, "title" | "description" | "client_id" | "status" | "start_date" | "end_date">;

const projectStatuses: { value: ProjectStatus; label: string }[] = [
  { value: "planned", label: "Planned" },
  { value: "active", label: "Active" },
  { value: "on_hold", label: "On hold" },
  { value: "completed", label: "Completed" },
  { value: "cancelled", label: "Cancelled" },
];

const isFormVisible = ref(false);
const projects = ref<Project[]>([]);
const clients = ref<Client[]>([]);
const editingId = ref<number | null>(null);
const title = ref("");
const description = ref("");
const clientId = ref<number | null>(null);
const status = ref<ProjectStatus>("planned");
const startDate = ref("");
const endDate = ref("");
const errorMessage = ref("");
const isLoading = ref(true);
const isSaving = ref(false);
const isDeleting = ref(false);

function formatProjectDate(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
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
    throw new Error(payload?.error ?? "Unable to complete the project request.");
  }

  return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}

async function loadProjects() {
  errorMessage.value = "";
  isLoading.value = true;

  try {
    const [loadedProjects, loadedClients] = await Promise.all([
      request<Project[]>("/projects"),
      request<Client[]>("/clients"),
    ]);
    projects.value = loadedProjects;
    clients.value = loadedClients;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load projects.";
  } finally {
    isLoading.value = false;
  }
}

onMounted(loadProjects);

function openCreateForm() {
  editingId.value = null;
  title.value = "";
  description.value = "";
  clientId.value = clients.value[0]?.id ?? null;
  status.value = "planned";
  startDate.value = "";
  endDate.value = "";
  errorMessage.value = "";
  isFormVisible.value = true;
}

function editProject(project: Project) {
  editingId.value = project.id;
  title.value = project.title;
  description.value = project.description;
  clientId.value = project.client_id;
  status.value = project.status;
  startDate.value = project.start_date ?? "";
  endDate.value = project.end_date ?? "";
  errorMessage.value = "";
  isFormVisible.value = true;
}

function closeForm() {
  if (!isSaving.value && !isDeleting.value) isFormVisible.value = false;
}

async function saveProject() {
  const project: ProjectInput = {
    title: title.value.trim(),
    description: description.value.trim(),
    client_id: clientId.value,
    status: status.value,
    start_date: startDate.value || null,
    end_date: endDate.value || null,
  };

  if (!project.title || !project.description || project.client_id === null) {
    errorMessage.value = "Complete the title, description, and client before saving.";
    return;
  }
  if (!isValidDateRange(project.start_date, project.end_date)) {
    errorMessage.value = "The end date must be on or after the start date.";
    return;
  }

  errorMessage.value = "";
  isSaving.value = true;

  try {
    const savedProject = await request<Project>(editingId.value === null ? "/projects" : `/projects/${editingId.value}`, {
      method: editingId.value === null ? "POST" : "PUT",
      body: JSON.stringify(project),
    });

    if (editingId.value === null) projects.value.unshift(savedProject);
    else {
      const projectIndex = projects.value.findIndex((item) => item.id === editingId.value);
      if (projectIndex !== -1) projects.value[projectIndex] = savedProject;
    }

    isFormVisible.value = false;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to save the project.";
  } finally {
    isSaving.value = false;
  }
}

async function deleteProject() {
  if (editingId.value === null) return;
  const project = projects.value.find((item) => item.id === editingId.value);
  if (!project || !window.confirm(`Delete "${project.title}"?`)) return;

  errorMessage.value = "";
  isDeleting.value = true;

  try {
    await request(`/projects/${project.id}`, { method: "DELETE" });
    projects.value = projects.value.filter((item) => item.id !== project.id);
    isFormVisible.value = false;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to delete the project.";
  } finally {
    isDeleting.value = false;
  }
}
</script>

<template>
  <div class="page-content">
    <div class="page-heading">
      <div>
        <p class="eyebrow">Projects</p>
        <h1>Build what matters.</h1>
      </div>
      <button v-if="!isFormVisible" class="create-button" type="button" @click="openCreateForm">
        + <span>Create project</span>
      </button>
    </div>

    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading projects...</h2></div>

    <form v-else-if="isFormVisible" class="article-form project-form" @submit.prevent="saveProject">
      <div class="article-form-heading">
        <div>
          <p class="card-kicker">{{ editingId === null ? "New project" : "Edit project" }}</p>
          <h2>{{ editingId === null ? "Give the work a direction." : "Keep the project focused." }}</h2>
        </div>
        <button class="close-button" type="button" aria-label="Close project form" @click="closeForm">&#10005;</button>
      </div>

      <label for="project-title">Title</label>
      <input id="project-title" v-model="title" type="text" placeholder="Customer onboarding" required />

      <label for="project-description">Description</label>
      <textarea id="project-description" v-model="description" rows="7" placeholder="Describe the outcome this project should deliver..." required></textarea>

      <label for="project-client">Client</label>
      <select id="project-client" v-model="clientId" required>
        <option :value="null" disabled>Select a client</option>
        <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.title }}</option>
      </select>

      <label for="project-status">Status</label>
      <select id="project-status" v-model="status">
        <option v-for="option in projectStatuses" :key="option.value" :value="option.value">{{ option.label }}</option>
      </select>

      <div class="task-datetime-grid">
        <div>
          <label for="project-start">Planned start</label>
          <input id="project-start" v-model="startDate" type="date" />
        </div>
        <div>
          <label for="project-end">Planned end</label>
          <input id="project-end" v-model="endDate" type="date" />
        </div>
      </div>

      <div class="task-form-actions">
        <button class="button article-submit" type="submit" :disabled="isSaving || isDeleting">
          {{ isSaving ? "Saving..." : "Save" }} <span aria-hidden="true">&#8594;</span>
        </button>
        <button class="edit-button" type="button" :disabled="isSaving || isDeleting" @click="closeForm">Cancel</button>
        <button v-if="editingId !== null" class="edit-button danger-button" type="button" :disabled="isSaving || isDeleting" @click="deleteProject">
          {{ isDeleting ? "Deleting..." : "Delete" }}
        </button>
      </div>
    </form>

    <div v-else-if="projects.length === 0" class="empty-page">
      <span class="empty-icon" aria-hidden="true">&#9673;</span>
      <h2>Make the work visible.</h2>
      <p class="muted">Create your first project to give a bigger goal a clear home.</p>
    </div>

    <div v-else class="article-list project-list">
      <article v-for="project in projects" :key="project.id" class="article-item project-item">
        <div>
          <p class="card-kicker">{{ project.client_title ?? "Unlinked legacy project" }} · {{ project.status.replace("_", " ") }}</p>
          <h2>{{ project.title }}</h2>
          <p class="muted">{{ project.description }}</p>
          <p v-if="project.start_date || project.end_date" class="card-kicker">
            {{ project.start_date || "Start not set" }} to {{ project.end_date || "End not set" }}
          </p>
        </div>
        <button class="edit-button" type="button" @click="editProject(project)">Edit</button>
      </article>
    </div>
  </div>
</template>