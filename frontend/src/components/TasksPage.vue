<script setup lang="ts">
import { onMounted, ref } from "vue";

type Task = {
  id: number;
  title: string;
  description: string;
  start_datetime: string;
  end_datetime: string;
  created_at: string;
  updated_at: string;
};

type TaskInput = {
  title: string;
  description: string;
  start_datetime: string;
  end_datetime: string;
};

const isFormVisible = ref(false);
const tasks = ref<Task[]>([]);
const editingId = ref<number | null>(null);
const title = ref("");
const description = ref("");
const startDatetime = ref("");
const endDatetime = ref("");
const errorMessage = ref("");
const isLoading = ref(true);
const isSaving = ref(false);
const isDeleting = ref(false);

function formatTaskDate(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

function toDatetimeLocal(value: string) {
  const date = new Date(value);
  const pad = (part: number) => String(part).padStart(2, "0");

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function toApiDatetime(value: string) {
  return new Date(value).toISOString();
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = localStorage.getItem("crm_token");
  const response = await fetch(`/api/tasks${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });

  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: string } | null;
    throw new Error(payload?.error ?? "Unable to complete the task request.");
  }

  return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}

async function loadTasks() {
  errorMessage.value = "";
  isLoading.value = true;

  try {
    tasks.value = await request<Task[]>("");
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load tasks.";
  } finally {
    isLoading.value = false;
  }
}

onMounted(loadTasks);

function openCreateForm() {
  editingId.value = null;
  title.value = "";
  description.value = "";
  startDatetime.value = "";
  endDatetime.value = "";
  errorMessage.value = "";
  isFormVisible.value = true;
}

function editTask(task: Task) {
  editingId.value = task.id;
  title.value = task.title;
  description.value = task.description;
  startDatetime.value = toDatetimeLocal(task.start_datetime);
  endDatetime.value = toDatetimeLocal(task.end_datetime);
  errorMessage.value = "";
  isFormVisible.value = true;
}

function closeForm() {
  if (!isSaving.value && !isDeleting.value) isFormVisible.value = false;
}

async function saveTask() {
  const task: TaskInput = {
    title: title.value.trim(),
    description: description.value.trim(),
    start_datetime: startDatetime.value ? toApiDatetime(startDatetime.value) : "",
    end_datetime: endDatetime.value ? toApiDatetime(endDatetime.value) : "",
  };

  if (!task.title || !task.description || !task.start_datetime || !task.end_datetime) {
    errorMessage.value = "Complete all task fields before saving.";
    return;
  }
  if (new Date(task.end_datetime) < new Date(task.start_datetime)) {
    errorMessage.value = "End date and time must be after the start date and time.";
    return;
  }

  errorMessage.value = "";
  isSaving.value = true;

  try {
    const savedTask = await request<Task>(editingId.value === null ? "" : `/${editingId.value}`, {
      method: editingId.value === null ? "POST" : "PUT",
      body: JSON.stringify(task),
    });

    if (editingId.value === null) tasks.value.unshift(savedTask);
    else {
      const taskIndex = tasks.value.findIndex((item) => item.id === editingId.value);
      if (taskIndex !== -1) tasks.value[taskIndex] = savedTask;
    }

    isFormVisible.value = false;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to save the task.";
  } finally {
    isSaving.value = false;
  }
}

async function deleteTask() {
  if (editingId.value === null) return;
  const task = tasks.value.find((item) => item.id === editingId.value);
  if (!task || !window.confirm(`Delete "${task.title}"?`)) return;

  errorMessage.value = "";
  isDeleting.value = true;

  try {
    await request(`/${task.id}`, { method: "DELETE" });
    tasks.value = tasks.value.filter((item) => item.id !== task.id);
    isFormVisible.value = false;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to delete the task.";
  } finally {
    isDeleting.value = false;
  }
}
</script>

<template>
  <div class="page-content">
    <div class="page-heading">
      <div>
        <p class="eyebrow">Tasks</p>
        <h1>What is next.</h1>
      </div>
      <button v-if="!isFormVisible" class="create-button" type="button" @click="openCreateForm">
        + <span>Create task</span>
      </button>
    </div>

    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading tasks...</h2></div>

    <form v-else-if="isFormVisible" class="article-form task-form" @submit.prevent="saveTask">
      <div class="article-form-heading">
        <div>
          <p class="card-kicker">{{ editingId === null ? "New task" : "Edit task" }}</p>
          <h2>{{ editingId === null ? "Plan the next move." : "Keep the details current." }}</h2>
        </div>
        <button class="close-button" type="button" aria-label="Close task form" @click="closeForm">&#10005;</button>
      </div>

      <label for="task-title">Title</label>
      <input id="task-title" v-model="title" type="text" placeholder="Prepare client follow-up" required />

      <label for="task-description">Description</label>
      <textarea id="task-description" v-model="description" rows="5" placeholder="What needs to get done?" required></textarea>

      <div class="task-datetime-grid">
        <div>
          <label for="task-start">Start date and time</label>
          <input id="task-start" v-model="startDatetime" type="datetime-local" required />
        </div>
        <div>
          <label for="task-end">End date and time</label>
          <input id="task-end" v-model="endDatetime" type="datetime-local" required />
        </div>
      </div>

      <div class="task-form-actions">
        <button class="button article-submit" type="submit" :disabled="isSaving || isDeleting">
          {{ isSaving ? "Saving..." : "Save" }} <span aria-hidden="true">&#8594;</span>
        </button>
        <button class="edit-button" type="button" :disabled="isSaving || isDeleting" @click="closeForm">Cancel</button>
        <button v-if="editingId !== null" class="edit-button danger-button" type="button" :disabled="isSaving || isDeleting" @click="deleteTask">
          {{ isDeleting ? "Deleting..." : "Delete" }}
        </button>
      </div>
    </form>

    <div v-else-if="tasks.length === 0" class="empty-page">
      <span class="empty-icon" aria-hidden="true">&#10003;</span>
      <h2>Make progress visible.</h2>
      <p class="muted">Create your first task to give the next step a place to land.</p>
    </div>

    <div v-else class="article-list task-list">
      <article v-for="task in tasks" :key="task.id" class="article-item task-item">
        <div>
          <p class="card-kicker">{{ formatTaskDate(task.start_datetime) }} to {{ formatTaskDate(task.end_datetime) }}</p>
          <h2>{{ task.title }}</h2>
          <p class="muted">{{ task.description }}</p>
        </div>
        <button class="edit-button" type="button" @click="editTask(task)">Edit</button>
      </article>
    </div>
  </div>
</template>
