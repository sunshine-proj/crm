<script setup lang="ts">
import { onMounted, ref } from "vue";

type Project = {
  id: number;
  title: string;
  description: string;
  created_at: string;
  updated_at: string;
};

type ProjectInput = Pick<Project, "title" | "description">;

const isFormVisible = ref(false);
const projects = ref<Project[]>([]);
const editingId = ref<number | null>(null);
const title = ref("");
const description = ref("");
const errorMessage = ref("");
const isLoading = ref(true);
const isSaving = ref(false);
const isDeleting = ref(false);

function formatProjectDate(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = localStorage.getItem("crm_token");
  const response = await fetch(`/api/projects${path}`, {
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
    projects.value = await request<Project[]>("");
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
  errorMessage.value = "";
  isFormVisible.value = true;
}

function editProject(project: Project) {
  editingId.value = project.id;
  title.value = project.title;
  description.value = project.description;
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
  };

  if (!project.title || !project.description) {
    errorMessage.value = "Complete the title and description before saving.";
    return;
  }

  errorMessage.value = "";
  isSaving.value = true;

  try {
    const savedProject = await request<Project>(editingId.value === null ? "" : `/${editingId.value}`, {
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
    await request(`/${project.id}`, { method: "DELETE" });
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
          <p class="card-kicker">Created {{ formatProjectDate(project.created_at) }}</p>
          <h2>{{ project.title }}</h2>
          <p class="muted">{{ project.description }}</p>
        </div>
        <button class="edit-button" type="button" @click="editProject(project)">Edit</button>
      </article>
    </div>
  </div>
</template>