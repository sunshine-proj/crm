<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { boardColumns, canMove, filterBoardTasks, groupByStatus, type BoardStatus } from "../lib/kanban";

type Task = {
  id: number;
  title: string;
  project_id: number | null;
  project_title: string | null;
  assignee_id: number | null;
  assignee_email: string | null;
  status: BoardStatus;
  priority: string;
  due_date: string | null;
  due_status: "done" | "cancelled" | "overdue" | "upcoming" | null;
};
type Project = { id: number; title: string };
type Person = { id: number; email: string };

const tasks = ref<Task[]>([]);
const projects = ref<Project[]>([]);
const people = ref<Person[]>([]);
const projectId = ref<number | null>(null);
const assigneeId = ref<number | null>(null);
const hideClosed = ref(false);
const isLoading = ref(true);
const savingId = ref<number | null>(null);
const draggingId = ref<number | null>(null);
const dropTarget = ref<BoardStatus | null>(null);
const errorMessage = ref("");
const announcement = ref("");

const columns = computed(() => groupByStatus(filterBoardTasks(tasks.value, { projectId: projectId.value, assigneeId: assigneeId.value, hideClosed: hideClosed.value })));
const visibleColumns = computed(() => boardColumns.filter((column) => !hideClosed.value || (column.status !== "done" && column.status !== "cancelled")));

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    [tasks.value, projects.value, people.value] = await Promise.all([
      apiRequest<Task[]>("/tasks"),
      apiRequest<Project[]>("/projects"),
      apiRequest<Person[]>("/projects/managers"),
    ]);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load the task board.");
  } finally {
    isLoading.value = false;
  }
}

async function moveTask(task: Task, target: BoardStatus) {
  if (!canMove(task, target) || savingId.value !== null) return;
  const previous = task.status;
  task.status = target; // optimistic; rolled back if the API rejects the move
  savingId.value = task.id;
  errorMessage.value = "";
  try {
    const saved = await apiRequest<Task>(`/tasks/${task.id}`, { method: "PATCH", body: JSON.stringify({ status: target }) }, "Unable to move the task.");
    Object.assign(task, saved);
    announcement.value = `${task.title} moved to ${boardColumns.find((column) => column.status === target)?.label}.`;
  } catch (error) {
    task.status = previous;
    errorMessage.value = errorText(error, "Unable to move the task.");
  } finally {
    savingId.value = null;
  }
}

function onDrop(target: BoardStatus) {
  const task = tasks.value.find((item) => item.id === draggingId.value);
  draggingId.value = null;
  dropTarget.value = null;
  if (task) void moveTask(task, target);
}

onMounted(load);
</script>

<template>
  <section class="task-board" aria-label="Task board">
    <div class="pm-filters">
      <div>
        <label for="board-project">Project</label>
        <select id="board-project" v-model.number="projectId">
          <option :value="null">All projects</option>
          <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.title }}</option>
        </select>
      </div>
      <div>
        <label for="board-assignee">Assignee</label>
        <select id="board-assignee" v-model.number="assigneeId">
          <option :value="null">Everyone</option>
          <option v-for="person in people" :key="person.id" :value="person.id">{{ person.email }}</option>
        </select>
      </div>
      <label class="pm-all-projects-toggle"><input v-model="hideClosed" type="checkbox" /> Hide done and canceled</label>
      <button class="edit-button" type="button" :disabled="isLoading" @click="load">Refresh</button>
    </div>

    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p class="visually-hidden" role="status">{{ announcement }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading the board...</h2></div>

    <div v-else class="board-columns">
      <section
        v-for="column in visibleColumns"
        :key="column.status"
        class="board-column"
        :class="{ 'is-drop-target': dropTarget === column.status }"
        :aria-label="`${column.label}, ${columns[column.status].length} tasks`"
        @dragover.prevent="dropTarget = column.status"
        @dragleave="dropTarget = null"
        @drop.prevent="onDrop(column.status)"
      >
        <h3>{{ column.label }} <span class="board-count">{{ columns[column.status].length }}</span></h3>
        <p v-if="columns[column.status].length === 0" class="muted">No tasks</p>
        <article
          v-for="task in columns[column.status]"
          :key="task.id"
          class="board-card"
          :class="[`priority-${task.priority}`, { 'is-saving': savingId === task.id }]"
          draggable="true"
          @dragstart="draggingId = task.id"
          @dragend="draggingId = null; dropTarget = null"
        >
          <strong>{{ task.title }}</strong>
          <span class="muted">{{ task.project_title ?? "No project" }}</span>
          <span class="muted">{{ task.assignee_email ?? "Unassigned" }}</span>
          <span v-if="task.due_date" class="board-due" :class="{ 'is-overdue': task.due_status === 'overdue' }">
            Due {{ task.due_date }}<template v-if="task.due_status === 'overdue'"> · overdue</template>
          </span>
          <label class="board-move">
            <span class="visually-hidden">Move {{ task.title }} to</span>
            <select :value="task.status" :disabled="savingId !== null" @change="moveTask(task, ($event.target as HTMLSelectElement).value as BoardStatus)">
              <option v-for="option in boardColumns" :key="option.status" :value="option.status">{{ option.label }}</option>
            </select>
          </label>
        </article>
      </section>
    </div>
  </section>
</template>
