<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import TaskBoard from "./TaskBoard.vue";
import {
  matchesScheduleDateRange,
  overdueDays,
  projectManagementStatus,
  taskManagementStatus,
  timelinePosition,
  type ProjectManagementStatus,
  type TaskManagementStatus,
  type TimelineDomain,
  type TimelinePosition,
} from "../lib/projectManagement";

type Project = {
  id: number;
  title: string;
  client_title: string | null;
  manager_email: string | null;
  status: "planned" | "active" | "on_hold" | "completed" | "cancelled";
  start_date: string | null;
  end_date: string | null;
};

type Task = {
  id: number;
  title: string;
  project_id: number | null;
  project_title: string | null;
  assignee_email: string | null;
  status: "todo" | "in_progress" | "blocked" | "done" | "cancelled";
  due_date: string | null;
  start_datetime: string | null;
  end_datetime: string | null;
};

type OverdueRow = {
  id: number;
  title: string;
  project: string;
  owner: string;
  dueDate: string;
  days: number;
  status: ProjectManagementStatus | TaskManagementStatus;
};

type TimelineTaskRow = {
  task: Task;
  status: TaskManagementStatus;
  bar: TimelinePosition | null;
};

type TimelineProjectRow = {
  project: Project;
  status: ProjectManagementStatus;
  bar: TimelinePosition | null;
  tasks: TimelineTaskRow[];
};

const projectStatusOptions: { value: ProjectManagementStatus; label: string }[] = [
  { value: "planned", label: "Planned" },
  { value: "in_progress", label: "In progress" },
  { value: "overdue", label: "Overdue" },
  { value: "finished", label: "Finished" },
  { value: "canceled", label: "Canceled" },
  { value: "stopped", label: "Stopped" },
];

const taskStatusOptions: { value: TaskManagementStatus; label: string }[] = [
  { value: "planned", label: "Planned" },
  { value: "in_progress", label: "In progress" },
  { value: "overdue", label: "Overdue" },
  { value: "finished", label: "Finished" },
  { value: "canceled", label: "Canceled" },
  { value: "blocked", label: "Blocked" },
];

const projects = ref<Project[]>([]);
const tasks = ref<Task[]>([]);
const errorMessage = ref("");
const view = ref<"timetable" | "board">("timetable");
const isLoading = ref(true);
const showAllProjects = ref(false);
const dateFrom = ref("");
const dateTo = ref("");
const today = localDateKey();
const isDateRangeValid = computed(() => dateFrom.value === "" || dateTo.value === "" || dateFrom.value <= dateTo.value);

const scopedProjects = computed(() => projects.value.filter(
  (project) => showAllProjects.value || project.status === "active",
));
const projectIds = computed(() => new Set(scopedProjects.value.map((project) => project.id)));
const dateFilteredProjects = computed(() => scopedProjects.value.filter((project) =>
  matchesScheduleDateRange(project.start_date, project.end_date, dateFrom.value, dateTo.value),
));
const dateFilteredTasks = computed(() => tasks.value.filter((task) =>
  task.project_id !== null
    && projectIds.value.has(task.project_id)
    && matchesScheduleDateRange(task.start_datetime, task.end_datetime, dateFrom.value, dateTo.value),
));
const taskStatuses = computed(() => dateFilteredTasks.value.map((task) => taskManagementStatus(task, today)));
const projectStatuses = computed(() => dateFilteredProjects.value.map((project) => projectManagementStatus(project, today)));

const projectCounts = computed(() => countStatuses(projectStatusOptions.map(({ value }) => value), projectStatuses.value));
const taskCounts = computed(() => countStatuses(taskStatusOptions.map(({ value }) => value), taskStatuses.value));

const overdueProjects = computed<OverdueRow[]>(() => dateFilteredProjects.value
  .filter((project) => projectManagementStatus(project, today) === "overdue" && project.end_date !== null)
  .map((project) => ({
    id: project.id,
    title: project.title,
    project: project.client_title ?? "Unlinked client",
    owner: project.manager_email ?? "Unassigned",
    dueDate: project.end_date as string,
    days: overdueDays(project.end_date, today),
    status: "overdue" as const,
  }))
  .sort((left, right) => right.days - left.days));

const overdueTasks = computed<OverdueRow[]>(() => dateFilteredTasks.value
  .filter((task) => taskManagementStatus(task, today) === "overdue")
  .map((task) => {
    const dueDate = task.due_date ?? task.end_datetime?.slice(0, 10) ?? "";
    return {
      id: task.id,
      title: task.title,
      project: task.project_title ?? "Unlinked project",
      owner: task.assignee_email ?? "Unassigned",
      dueDate,
      days: overdueDays(dueDate, today),
      status: "overdue" as const,
    };
  })
  .sort((left, right) => right.days - left.days));

const timelineRows = computed<TimelineProjectRow[]>(() => {
  const rows: TimelineProjectRow[] = [];
  for (const project of scopedProjects.value) {
    const projectMatchesRange = matchesScheduleDateRange(
      project.start_date,
      project.end_date,
      dateFrom.value,
      dateTo.value,
    );
    const projectTasks = tasks.value
      .filter((task) => task.project_id === project.id)
      .filter((task) => matchesScheduleDateRange(
        task.start_datetime,
        task.end_datetime,
        dateFrom.value,
        dateTo.value,
      ));
    if (!projectMatchesRange && projectTasks.length === 0) continue;

    rows.push({
      project,
      status: projectManagementStatus(project, today),
      bar: projectMatchesRange && timelineDomain.value
        ? timelinePosition(project.start_date, project.end_date, timelineDomain.value)
        : null,
      tasks: projectTasks.map((task) => ({
        task,
        status: taskManagementStatus(task, today),
        bar: timelineDomain.value
          ? timelinePosition(task.start_datetime, task.end_datetime, timelineDomain.value)
          : null,
      })),
    });
  }
  return rows;
});

const timelineDomain = computed<TimelineDomain | null>(() => {
  const dates: string[] = [];
  for (const project of scopedProjects.value) {
    if (matchesScheduleDateRange(project.start_date, project.end_date, dateFrom.value, dateTo.value)
      && project.start_date !== null && project.end_date !== null) {
      dates.push(project.start_date, project.end_date);
    }
    for (const task of tasks.value) {
      if (task.project_id === project.id
        && matchesScheduleDateRange(task.start_datetime, task.end_datetime, dateFrom.value, dateTo.value)
        && task.start_datetime !== null && task.end_datetime !== null) {
        dates.push(task.start_datetime.slice(0, 10), task.end_datetime.slice(0, 10));
      }
    }
  }
  if (dates.length === 0) return null;
  dates.sort();
  return { start: dates[0], end: dates[dates.length - 1] };
});

const timelineTicks = computed(() => {
  const domain = timelineDomain.value;
  if (domain === null) return [];
  const monthCount = (Number(domain.end.slice(0, 4)) - Number(domain.start.slice(0, 4))) * 12
    + Number(domain.end.slice(5, 7)) - Number(domain.start.slice(5, 7)) + 1;
  const interval = monthCount > 60 ? 6 : monthCount > 24 ? 3 : monthCount > 12 ? 2 : 1;
  const start = new Date(`${domain.start}T00:00:00Z`);
  const end = new Date(`${domain.end}T00:00:00Z`);
  start.setUTCDate(1);
  const ticks: { label: string; left: number }[] = [];
  let monthIndex = 0;
  while (start <= end) {
    if (monthIndex % interval === 0) {
      const date = start.toISOString().slice(0, 10);
      const totalDays = dateDistance(domain.start, domain.end) + 1;
      ticks.push({
        label: new Intl.DateTimeFormat(undefined, { month: "short", year: "numeric", timeZone: "UTC" }).format(start),
        left: (dateDistance(domain.start, date) / totalDays) * 100,
      });
    }
    start.setUTCMonth(start.getUTCMonth() + 1);
    monthIndex += 1;
  }
  return ticks;
});

async function request<T>(path: string): Promise<T> {
  const token = localStorage.getItem("crm_token");
  const response = await fetch(`/api${path}`, {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  });
  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: string } | null;
    throw new Error(payload?.error ?? "Unable to load project management data.");
  }
  return (await response.json()) as T;
}

async function loadManagementData() {
  errorMessage.value = "";
  isLoading.value = true;
  try {
    const [loadedProjects, loadedTasks] = await Promise.all([
      request<Project[]>("/projects"),
      request<Task[]>("/tasks"),
    ]);
    projects.value = loadedProjects;
    tasks.value = loadedTasks;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load project management data.";
  } finally {
    isLoading.value = false;
  }
}

function resetDateRange() {
  dateFrom.value = "";
  dateTo.value = "";
}

function clearFilters() {
  showAllProjects.value = false;
  resetDateRange();
}

function statusLabel(status: ProjectManagementStatus | TaskManagementStatus): string {
  return [...projectStatusOptions, ...taskStatusOptions].find((item) => item.value === status)?.label ?? status;
}

function formatDate(value: string | null): string {
  if (value === null) return "Not scheduled";
  const date = new Date(`${value.slice(0, 10)}T00:00:00Z`);
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeZone: "UTC" }).format(date);
}

function barTitle(
  title: string,
  start: string | null,
  end: string | null,
  status: ProjectManagementStatus | TaskManagementStatus,
): string {
  return `${title}: ${formatDate(start)} – ${formatDate(end)} (${statusLabel(status)})`;
}

function countStatuses<T extends string>(keys: T[], statuses: T[]): Record<T, number> {
  return Object.fromEntries(keys.map((key) => [key, statuses.filter((status) => status === key).length])) as Record<T, number>;
}

function localDateKey(): string {
  const now = new Date();
  const pad = (value: number) => String(value).padStart(2, "0");
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

function dateDistance(start: string, end: string): number {
  return (Date.parse(`${end}T00:00:00Z`) - Date.parse(`${start}T00:00:00Z`)) / 86_400_000;
}

onMounted(loadManagementData);
</script>

<template>
  <div class="page-content project-management-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">Project management</p>
        <h1>One view of the work.</h1>
      </div>
      <label v-if="view === 'timetable'" class="pm-all-projects-toggle">
        <input v-model="showAllProjects" type="checkbox" />
        Show all projects
      </label>
    </div>

    <div class="view-tabs" role="tablist" aria-label="Project management views">
      <button role="tab" type="button" :aria-selected="view === 'timetable'" :class="{ active: view === 'timetable' }" @click="view = 'timetable'">Timetable</button>
      <button role="tab" type="button" :aria-selected="view === 'board'" :class="{ active: view === 'board' }" @click="view = 'board'">Task board</button>
    </div>

    <TaskBoard v-if="view === 'board'" />
    <template v-else>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading project management...</h2></div>
    <div v-else>
      <section class="pm-filters" aria-label="Project management filters">
        <div>
          <label for="pm-date-from">From</label>
          <input id="pm-date-from" v-model="dateFrom" type="date" />
        </div>
        <div>
          <label for="pm-date-to">To</label>
          <input id="pm-date-to" v-model="dateTo" type="date" />
        </div>
        <button class="edit-button" type="button" @click="clearFilters">Reset filters</button>
        <p>Statistics and timetable rows use the selected schedule range.</p>
      </section>
      <p v-if="!isDateRangeValid" class="error" role="alert">The end date must be on or after the start date.</p>

      <section class="pm-overview" aria-label="Project and task status statistics">
        <article class="pm-stat-panel">
          <div class="pm-section-heading">
            <div><p class="card-kicker">Portfolio</p><h2>Projects by status</h2></div>
            <strong>{{ dateFilteredProjects.length }}</strong>
          </div>
          <ul class="pm-status-list">
            <li v-for="item in projectStatusOptions" :key="item.value">
              <span><i class="pm-status-dot" :class="`pm-status--${item.value}`"></i>{{ item.label }}</span>
              <strong>{{ projectCounts[item.value] }}</strong>
            </li>
          </ul>
        </article>
        <article class="pm-stat-panel">
          <div class="pm-section-heading">
            <div><p class="card-kicker">Delivery</p><h2>Tasks by status</h2></div>
            <strong>{{ dateFilteredTasks.length }}</strong>
          </div>
          <ul class="pm-status-list">
            <li v-for="item in taskStatusOptions" :key="item.value">
              <span><i class="pm-status-dot" :class="`pm-status--${item.value}`"></i>{{ item.label }}</span>
              <strong>{{ taskCounts[item.value] }}</strong>
            </li>
          </ul>
        </article>
      </section>

      <section class="pm-panel pm-timeline-panel">
        <div class="pm-section-heading">
          <div><p class="card-kicker">Schedule</p><h2>{{ showAllProjects ? "All projects" : "Active projects" }}</h2></div>
          <span class="pm-result-count">{{ timelineRows.length }} projects · {{ dateFilteredTasks.length }} tasks</span>
        </div>
        <p class="pm-explainer">Project bars use planned start/end dates. Each task has its own bar from its scheduled start/end.</p>
        <ul class="pm-legend" aria-label="Timetable status legend">
          <li v-for="item in projectStatusOptions.filter((option) => option.value !== 'stopped')" :key="item.value">
            <i class="pm-status-dot" :class="`pm-status--${item.value}`"></i>{{ item.label }}
          </li>
          <li><i class="pm-status-dot pm-status--stopped"></i>Stopped</li>
          <li><i class="pm-status-dot pm-status--blocked"></i>Blocked</li>
        </ul>

        <div v-if="timelineRows.length === 0" class="pm-empty">
          <h3>No projects match these filters.</h3>
          <p>Adjust the date range or show all projects.</p>
        </div>
        <div v-else-if="timelineDomain === null" class="pm-empty">
          <h3>There are no scheduled dates to plot.</h3>
          <p>Add project or task start and end dates to see timetable bars.</p>
          <ul class="pm-unscheduled-list">
            <li v-for="row in timelineRows" :key="row.project.id">
              <strong>{{ row.project.title }}</strong>
              <span>{{ row.tasks.length }} tasks · no dated bars in this range</span>
            </li>
          </ul>
        </div>
        <div v-else class="pm-timeline-scroll">
          <div class="pm-timeline">
            <div class="pm-timeline-header">
              <div class="pm-timeline-label">Project / task</div>
              <div class="pm-chart-cell pm-chart-heading">
                <span v-for="tick in timelineTicks" :key="tick.label" class="pm-month-tick" :style="{ left: `${tick.left}%` }">{{ tick.label }}</span>
              </div>
            </div>
            <section v-for="row in timelineRows" :key="row.project.id" class="pm-project-group">
              <div class="pm-timeline-row pm-project-row">
                <div class="pm-timeline-label">
                  <strong>{{ row.project.title }}</strong>
                  <span>{{ row.project.client_title ?? "Unlinked client" }} · {{ row.project.manager_email ?? "Unassigned" }}</span>
                  <span>{{ formatDate(row.project.start_date) }} to {{ formatDate(row.project.end_date) }}</span>
                </div>
                <div class="pm-chart-cell">
                  <span v-for="tick in timelineTicks" :key="tick.label" class="pm-grid-tick" :style="{ left: `${tick.left}%` }"></span>
                  <span v-if="row.bar" class="pm-bar" :class="`pm-status--${row.status}`" :style="{ left: `${row.bar.left}%`, width: `${row.bar.width}%` }" :title="barTitle(row.project.title, row.project.start_date, row.project.end_date, row.status)">{{ statusLabel(row.status) }}</span>
                  <span v-else class="pm-no-bar">No project dates</span>
                </div>
              </div>
              <div v-for="taskRow in row.tasks" :key="taskRow.task.id" class="pm-timeline-row pm-task-row">
                <div class="pm-timeline-label">
                  <span class="pm-task-title">{{ taskRow.task.title }}</span>
                  <span>{{ taskRow.task.assignee_email ?? "Unassigned" }} · {{ statusLabel(taskRow.status) }}</span>
                  <span>{{ formatDate(taskRow.task.start_datetime) }} to {{ formatDate(taskRow.task.end_datetime) }}</span>
                </div>
                <div class="pm-chart-cell">
                  <span v-for="tick in timelineTicks" :key="tick.label" class="pm-grid-tick" :style="{ left: `${tick.left}%` }"></span>
                  <span v-if="taskRow.bar" class="pm-bar" :class="`pm-status--${taskRow.status}`" :style="{ left: `${taskRow.bar.left}%`, width: `${taskRow.bar.width}%` }" :title="barTitle(taskRow.task.title, taskRow.task.start_datetime, taskRow.task.end_datetime, taskRow.status)">{{ statusLabel(taskRow.status) }}</span>
                  <span v-else class="pm-no-bar">No task dates</span>
                </div>
              </div>
            </section>
          </div>
        </div>
      </section>

      <section class="pm-overdue-section" aria-label="Overdue project and task statistics">
        <div class="pm-section-heading">
          <div><p class="card-kicker">Attention needed</p><h2>Overdue work</h2></div>
          <strong>{{ overdueProjects.length + overdueTasks.length }}</strong>
        </div>
        <div class="pm-overdue-grid">
          <article class="pm-panel">
            <div class="pm-section-heading"><h3>Overdue projects</h3><span>{{ overdueProjects.length }}</span></div>
            <div v-if="overdueProjects.length" class="pm-table-scroll">
              <table class="pm-table">
                <thead><tr><th>Project</th><th>Client</th><th>Manager</th><th>Planned end</th><th>Days overdue</th></tr></thead>
                <tbody><tr v-for="row in overdueProjects" :key="row.id"><td>{{ row.title }}</td><td>{{ row.project }}</td><td>{{ row.owner }}</td><td>{{ formatDate(row.dueDate) }}</td><td>{{ row.days }}</td></tr></tbody>
              </table>
            </div>
            <p v-else class="pm-no-overdue">No overdue projects in this view.</p>
          </article>
          <article class="pm-panel">
            <div class="pm-section-heading"><h3>Overdue tasks</h3><span>{{ overdueTasks.length }}</span></div>
            <div v-if="overdueTasks.length" class="pm-table-scroll">
              <table class="pm-table">
                <thead><tr><th>Task</th><th>Project</th><th>Assignee</th><th>Due</th><th>Days overdue</th></tr></thead>
                <tbody><tr v-for="row in overdueTasks" :key="row.id"><td>{{ row.title }}</td><td>{{ row.project }}</td><td>{{ row.owner }}</td><td>{{ formatDate(row.dueDate) }}</td><td>{{ row.days }}</td></tr></tbody>
              </table>
            </div>
            <p v-else class="pm-no-overdue">No overdue tasks in this view.</p>
          </article>
        </div>
      </section>
    </div>
    </template>
  </div>
</template>
