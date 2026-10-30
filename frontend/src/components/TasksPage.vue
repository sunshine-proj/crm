<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { isValidDateTimeRange } from "../lib/planning";

type Task = {
  id: number;
  title: string;
  description: string;
  project_id: number | null;
  project_title: string | null;
  assignee_id: number | null;
  assignee_email: string | null;
  status: TaskStatus;
  priority: TaskPriority;
  due_date: string | null;
  due_status: "done" | "overdue" | "upcoming" | null;
  start_datetime: string;
  end_datetime: string;
  created_at: string;
  updated_at: string;
};

type TaskInput = {
  title: string;
  description: string;
  project_id: number | null;
  assignee_id: number | null;
  start_datetime: string;
  end_datetime: string;
  status: TaskStatus;
  priority: TaskPriority;
  due_date: string | null;
};

type Project = { id: number; title: string };
type Manager = { id: number; email: string };
type TaskStatus = "todo" | "in_progress" | "blocked" | "done";
type TaskPriority = "low" | "normal" | "high" | "urgent";

const taskStatuses: { value: TaskStatus; label: string }[] = [
  { value: "todo", label: "To do" },
  { value: "in_progress", label: "In progress" },
  { value: "blocked", label: "Blocked" },
  { value: "done", label: "Done" },
];
const taskPriorities: { value: TaskPriority; label: string }[] = [
  { value: "low", label: "Low" },
  { value: "normal", label: "Normal" },
  { value: "high", label: "High" },
  { value: "urgent", label: "Urgent" },
];

const isFormVisible = ref(false);
const tasks = ref<Task[]>([]);
const projects = ref<Project[]>([]);
const managers = ref<Manager[]>([]);
const editingId = ref<number | null>(null);
const title = ref("");
const description = ref("");
const projectId = ref<number | null>(null);
const assigneeId = ref<number | null>(null);
const status = ref<TaskStatus>("todo");
const priority = ref<TaskPriority>("normal");
const dueDate = ref("");
const startDatetime = ref("");
const endDatetime = ref("");
const errorMessage = ref("");
const isLoading = ref(true);
const isSaving = ref(false);
const isDeleting = ref(false);
const searchQuery = ref("");
const statusFilter = ref("");
const dependenciesTaskId = ref<number | null>(null);
const selectedDependencyIds = ref<number[]>([]);
const isSavingDependencies = ref(false);
const visibleTasks = computed(() => tasks.value.filter((task) => {
  const query = searchQuery.value.trim().toLocaleLowerCase();
  const matchesQuery = query === "" || `${task.title} ${task.description} ${task.project_title ?? ""} ${task.assignee_email ?? ""}`.toLocaleLowerCase().includes(query);
  return matchesQuery && (!statusFilter.value || task.status === statusFilter.value);
}));
const dependencyChoices = computed(() => tasks.value.filter((task) => task.project_id === tasks.value.find((item) => item.id === dependenciesTaskId.value)?.project_id && task.id !== dependenciesTaskId.value));

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
    throw new Error(payload?.error ?? "Unable to complete the task request.");
  }

  return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}

async function loadTasks() {
  errorMessage.value = "";
  isLoading.value = true;

  try {
    const [loadedTasks, loadedProjects, loadedManagers] = await Promise.all([
      request<Task[]>("/tasks"),
      request<Project[]>("/projects"),
      request<Manager[]>("/projects/managers"),
    ]);
    tasks.value = loadedTasks;
    projects.value = loadedProjects;
    managers.value = loadedManagers;
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
  projectId.value = projects.value[0]?.id ?? null;
  assigneeId.value = null;
  status.value = "todo";
  priority.value = "normal";
  dueDate.value = "";
  startDatetime.value = "";
  endDatetime.value = "";
  errorMessage.value = "";
  isFormVisible.value = true;
}

function editTask(task: Task) {
  editingId.value = task.id;
  title.value = task.title;
  description.value = task.description;
  projectId.value = task.project_id;
  assigneeId.value = task.assignee_id;
  status.value = task.status;
  priority.value = task.priority;
  dueDate.value = task.due_date ?? "";
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
    project_id: projectId.value,
    assignee_id: assigneeId.value,
    start_datetime: startDatetime.value ? toApiDatetime(startDatetime.value) : "",
    end_datetime: endDatetime.value ? toApiDatetime(endDatetime.value) : "",
    status: status.value,
    priority: priority.value,
    due_date: dueDate.value || null,
  };

  if (!task.title || !task.description || task.project_id === null || !task.start_datetime || !task.end_datetime) {
    errorMessage.value = "Complete the title, description, project, and schedule before saving.";
    return;
  }
  if (!isValidDateTimeRange(task.start_datetime, task.end_datetime)) {
    errorMessage.value = "End date and time must be after the start date and time.";
    return;
  }

  errorMessage.value = "";
  isSaving.value = true;

  try {
    const savedTask = await request<Task>(editingId.value === null ? "/tasks" : `/tasks/${editingId.value}`, {
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
    await request(`/tasks/${task.id}`, { method: "DELETE" });
    tasks.value = tasks.value.filter((item) => item.id !== task.id);
    isFormVisible.value = false;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to delete the task.";
  } finally {
    isDeleting.value = false;
  }
}

  async function openDependencies(task: Task) {
    errorMessage.value = "";
    try {
      const dependencies = await request<{ id: number; title: string }[]>(`/tasks/${task.id}/dependencies`);
      dependenciesTaskId.value = task.id;
      selectedDependencyIds.value = dependencies.map((dependency) => dependency.id);
    } catch (error) {
      errorMessage.value = error instanceof Error ? error.message : "Unable to load task dependencies.";
    }
  }

  async function saveDependencies(task: Task) {
    isSavingDependencies.value = true;
    errorMessage.value = "";
    try {
      await request(`/tasks/${task.id}/dependencies`, {
        method: "PUT",
        body: JSON.stringify({ depends_on_ids: selectedDependencyIds.value }),
      });
      dependenciesTaskId.value = null;
    } catch (error) {
      errorMessage.value = error instanceof Error ? error.message : "Unable to save task dependencies.";
    } finally {
      isSavingDependencies.value = false;
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

      <label for="task-project">Project</label>
      <select id="task-project" v-model="projectId" required>
        <option :value="null" disabled>Select a project</option>
        <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.title }}</option>
      </select>

      <label for="task-assignee">Assignee</label>
      <select id="task-assignee" v-model="assigneeId">
        <option :value="null">Unassigned</option>
        <option v-for="manager in managers" :key="manager.id" :value="manager.id">{{ manager.email }}</option>
      </select>

      <div class="task-datetime-grid">
        <div>
          <label for="task-status">Status</label>
          <select id="task-status" v-model="status">
            <option v-for="option in taskStatuses" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </div>
        <div>
          <label for="task-priority">Priority</label>
          <select id="task-priority" v-model="priority">
            <option v-for="option in taskPriorities" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </div>
      </div>

      <label for="task-due">Due date</label>
      <input id="task-due" v-model="dueDate" type="date" />

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
      <div class="task-filter-row"><label for="task-search">Search tasks</label><input id="task-search" v-model="searchQuery" type="search" placeholder="Title, project, assignee..." /><label for="task-status-filter">Status</label><select id="task-status-filter" v-model="statusFilter"><option value="">All statuses</option><option v-for="option in taskStatuses" :key="option.value" :value="option.value">{{ option.label }}</option></select></div>
      <article v-for="task in visibleTasks" :key="task.id" class="article-item task-item">
        <div>
          <p class="card-kicker">{{ task.project_title ?? "Unlinked legacy task" }} · {{ task.status.replace("_", " ") }} · {{ task.priority }} · {{ task.assignee_email ?? "Unassigned" }}</p>
          <h2>{{ task.title }}</h2>
          <p class="muted">{{ task.description }}</p>
          <p class="card-kicker">{{ formatTaskDate(task.start_datetime) }} to {{ formatTaskDate(task.end_datetime) }}<span v-if="task.due_date"> · Due {{ task.due_date }}<strong v-if="task.due_status === 'overdue'"> · OVERDUE</strong></span></p>
          <div class="task-dependency-panel">
            <button class="edit-button" type="button" @click="dependenciesTaskId === task.id ? dependenciesTaskId = null : openDependencies(task)">Dependencies</button>
            <div v-if="dependenciesTaskId === task.id" class="task-dependency-options">
              <label v-for="choice in dependencyChoices" :key="choice.id"><input v-model="selectedDependencyIds" type="checkbox" :value="choice.id" /> {{ choice.title }}</label>
              <button class="edit-button" type="button" :disabled="isSavingDependencies" @click="saveDependencies(task)">Save dependencies</button>
            </div>
          </div>
        </div>
        <button class="edit-button" type="button" @click="editTask(task)">Edit</button>
      </article>
      <p v-if="visibleTasks.length === 0" class="muted">No tasks match the selected filters.</p>
    </div>
  </div>
</template>
