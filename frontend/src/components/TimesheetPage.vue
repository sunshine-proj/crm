<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { apiRequest, errorText } from "../lib/api";
import {
  addDays,
  formatMinutes,
  isoDate,
  mondayOf,
  timeQuery,
  timesheetKey,
  timesheetPayload,
  timesheetRows,
  weekDays,
  type TimeEntry,
  type TimesheetDraft,
} from "../lib/timeTracking";

type Project = { id: number; title: string };
type Task = { id: number; title: string; project_id: number | null; status: string };
type GridRow = { key: string; projectId: number; projectTitle: string; taskId: number | null; taskTitle: string | null; existing: Record<string, number>; inputs: Record<string, string>; description: string; billable: boolean };

const today = isoDate(new Date());
const monday = ref(mondayOf(today));
const days = computed(() => weekDays(monday.value));
const projects = ref<Project[]>([]);
const tasks = ref<Task[]>([]);
const entries = ref<TimeEntry[]>([]);
const rows = ref<GridRow[]>([]);
const newProjectId = ref<number | null>(null);
const newTaskId = ref<number | null>(null);
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref("");
const savedMessage = ref("");

const tasksForNewRow = computed(() => tasks.value.filter((task) => task.project_id === newProjectId.value && task.status !== "done" && task.status !== "cancelled"));
const dayTotals = computed(() => days.value.map((day) => rows.value.reduce((sum, row) => sum + (row.existing[day] ?? 0), 0)));
const weekTotal = computed(() => dayTotals.value.reduce((sum, value) => sum + value, 0));

function dayLabel(day: string) {
  return new Intl.DateTimeFormat(undefined, { weekday: "short", day: "numeric", month: "short" }).format(new Date(`${day}T00:00:00`));
}

function emptyInputs(): Record<string, string> {
  return Object.fromEntries(days.value.map((day) => [day, ""]));
}

async function loadWeek() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    entries.value = await apiRequest<TimeEntry[]>(`/operations/time-entries?${timeQuery({ from: days.value[0], to: days.value[6] }, { mine: 1 })}`, {}, "Unable to load your week.");
    // Keep rows the person added but has not saved yet.
    const drafts = rows.value.filter((row) => Object.values(row.inputs).some((value) => value.trim() !== ""));
    const loaded: GridRow[] = timesheetRows(entries.value, days.value).map((row) => ({ ...row, existing: row.minutes, inputs: emptyInputs(), description: "", billable: false }));
    for (const draft of drafts) {
      const match = loaded.find((row) => row.key === draft.key);
      if (match) Object.assign(match, { inputs: draft.inputs, description: draft.description, billable: draft.billable });
      else loaded.push({ ...draft, existing: {} });
    }
    rows.value = loaded;
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load your week.");
  } finally {
    isLoading.value = false;
  }
}

function moveWeek(weeks: number) {
  if (rows.value.some((row) => Object.values(row.inputs).some((value) => value.trim() !== "")) && !window.confirm("Discard the unsaved time in this week?")) return;
  rows.value = [];
  monday.value = weeks === 0 ? mondayOf(today) : addDays(monday.value, weeks * 7);
  void loadWeek();
}

function addRow() {
  if (newProjectId.value === null) return;
  const key = timesheetKey(newProjectId.value, newTaskId.value);
  if (rows.value.some((row) => row.key === key)) {
    errorMessage.value = "That project and task already has a row.";
    return;
  }
  const project = projects.value.find((item) => item.id === newProjectId.value);
  const task = tasks.value.find((item) => item.id === newTaskId.value);
  rows.value.push({ key, projectId: newProjectId.value, projectTitle: project?.title ?? "", taskId: task?.id ?? null, taskTitle: task?.title ?? null, existing: {}, inputs: emptyInputs(), description: task?.title ?? "", billable: false });
  newTaskId.value = null;
  errorMessage.value = "";
}

async function save() {
  savedMessage.value = "";
  const drafts: TimesheetDraft[] = rows.value.flatMap((row) => days.value.map((day) => ({ projectId: row.projectId, taskId: row.taskId, day, text: row.inputs[day] ?? "", description: row.description, billable: row.billable })));
  const payload = timesheetPayload(drafts);
  if ("error" in payload) {
    errorMessage.value = payload.error;
    return;
  }
  isSaving.value = true;
  errorMessage.value = "";
  try {
    const saved = await apiRequest<TimeEntry[]>("/operations/time-entries/batch", { method: "POST", body: JSON.stringify(payload) }, "Unable to save the timesheet.");
    for (const row of rows.value) row.inputs = emptyInputs();
    savedMessage.value = `Saved ${saved.length} entr${saved.length === 1 ? "y" : "ies"} for approval.`;
    await loadWeek();
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to save the timesheet.");
  } finally {
    isSaving.value = false;
  }
}

onMounted(async () => {
  try {
    [projects.value, tasks.value] = await Promise.all([apiRequest<Project[]>("/projects"), apiRequest<Task[]>("/tasks")]);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load projects and tasks.");
  }
  await loadWeek();
});
</script>

<template>
  <div class="page-content timesheet-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">Timesheet</p>
        <h1>Your week, in hours.</h1>
      </div>
      <div class="timesheet-week-nav">
        <button class="edit-button" type="button" aria-label="Previous week" @click="moveWeek(-1)">&#8592;</button>
        <button class="edit-button" type="button" @click="moveWeek(0)">This week</button>
        <button class="edit-button" type="button" aria-label="Next week" @click="moveWeek(1)">&#8594;</button>
      </div>
    </div>

    <p class="muted">Week of {{ dayLabel(days[0]) }}. Type durations like <code>1:30</code>, <code>1.5</code>, <code>90m</code> or <code>2h</code>. Saved time is sent to the project manager for approval and can be withdrawn from the Operations page while pending.</p>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="savedMessage" class="success" role="status">{{ savedMessage }}</p>

    <div v-if="isLoading" class="empty-page"><h2>Loading your week...</h2></div>
    <form v-else @submit.prevent="save">
      <div class="finance-table-wrap">
        <table class="finance-table timesheet-grid">
          <caption class="visually-hidden">Time per project and task for each day of the week. Grey figures are already saved; inputs add new time.</caption>
          <thead>
            <tr>
              <th scope="col">Project / task</th>
              <th v-for="day in days" :key="day" scope="col">{{ dayLabel(day) }}</th>
              <th scope="col">Description</th>
              <th scope="col">Billable</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="rows.length === 0"><td :colspan="days.length + 3" class="muted">No time this week yet. Add a project row below.</td></tr>
            <tr v-for="row in rows" :key="row.key">
              <th scope="row">{{ row.projectTitle }}<small class="muted">{{ row.taskTitle ?? "No task" }}</small></th>
              <td v-for="day in days" :key="day">
                <span v-if="row.existing[day]" class="timesheet-existing">{{ formatMinutes(row.existing[day]) }}</span>
                <input v-model="row.inputs[day]" class="timesheet-input" type="text" inputmode="decimal" :aria-label="`Add time for ${row.projectTitle}, ${row.taskTitle ?? 'no task'} on ${dayLabel(day)}`" placeholder="+" />
              </td>
              <td><input v-model="row.description" type="text" maxlength="255" :aria-label="`Description for ${row.projectTitle}, ${row.taskTitle ?? 'no task'}`" placeholder="What did you work on?" /></td>
              <td><input v-model="row.billable" type="checkbox" :aria-label="`Billable time for ${row.projectTitle}`" /></td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="finance-table-total">
              <th scope="row">Saved total</th>
              <th v-for="(total, index) in dayTotals" :key="days[index]">{{ formatMinutes(total) }}</th>
              <th colspan="2">{{ formatMinutes(weekTotal) }}</th>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="pm-filters timesheet-add-row">
        <div>
          <label for="timesheet-project">Project</label>
          <select id="timesheet-project" v-model="newProjectId" @change="newTaskId = null">
            <option :value="null" disabled>Select a project</option>
            <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.title }}</option>
          </select>
        </div>
        <div>
          <label for="timesheet-task">Task</label>
          <select id="timesheet-task" v-model="newTaskId" :disabled="newProjectId === null">
            <option :value="null">No task</option>
            <option v-for="task in tasksForNewRow" :key="task.id" :value="task.id">{{ task.title }}</option>
          </select>
        </div>
        <button class="edit-button" type="button" :disabled="newProjectId === null" @click="addRow">Add row</button>
        <button class="button article-submit" type="submit" :disabled="isSaving">{{ isSaving ? "Saving..." : "Save week" }} <span aria-hidden="true">&#8594;</span></button>
      </div>
    </form>
  </div>
</template>
