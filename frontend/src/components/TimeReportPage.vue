<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { apiRequest, downloadFile, errorText } from "../lib/api";
import {
  cellKey,
  formatMinutes,
  isoDate,
  presetRange,
  scopeNotes,
  timeQuery,
  toMatrix,
  type DatePreset,
  type TimeEntry,
  type TimeFilters,
  type TimeReport,
  type TimeReportRow,
  type TimeScope,
} from "../lib/timeTracking";

type Project = { id: number; title: string };
type Person = { id: number; email: string };
type WorkloadWeek = { week_start: string; logged_minutes: number; planned_minutes: number; overloaded: boolean };
type Workload = {
  scope: TimeScope;
  from: string;
  to: string;
  capacity_minutes: number;
  weeks: string[];
  employees: { employee_id: number; employee_email: string; logged_minutes: number; planned_minutes: number; weeks: WorkloadWeek[] }[];
  unscheduled_tasks: number;
  basis: string;
};

const props = defineProps<{ projectId?: number }>();

const view = ref<"report" | "workload">("report");
const today = isoDate(new Date());
// Opened from a project page: that project over its whole life; otherwise this month for everything.
const preset = ref<DatePreset | "custom">(props.projectId ? "custom" : "this_month");
const filters = ref<TimeFilters>(props.projectId
  ? { from: "", to: "", projectId: props.projectId, userId: null, status: "", billable: "" }
  : { ...presetRange("this_month", today), projectId: null, userId: null, status: "", billable: "" });
const projects = ref<Project[]>([]);
const people = ref<Person[]>([]);
const report = ref<TimeReport | null>(null);
const isLoading = ref(true);
const isExporting = ref(false);
const errorMessage = ref("");

const selected = ref<{ employeeId: number; employeeEmail: string; projectId: number; projectTitle: string } | null>(null);
const taskRows = ref<TimeReportRow[]>([]);
const selectedTaskKey = ref<string | null>(null);
const entries = ref<TimeEntry[]>([]);
const isDetailLoading = ref(false);

const workload = ref<Workload | null>(null);
const isWorkloadLoading = ref(false);

const matrix = computed(() => toMatrix(report.value?.rows ?? []));
const visibleEntries = computed(() => entries.value.filter((entry) => selectedTaskKey.value === null || String(entry.task_id ?? "none") === selectedTaskKey.value));

watch(preset, (value) => {
  if (value !== "custom") Object.assign(filters.value, presetRange(value, today));
});

async function loadReport() {
  errorMessage.value = "";
  isLoading.value = true;
  selected.value = null;
  try {
    report.value = await apiRequest<TimeReport>(`/operations/time-report?${timeQuery(filters.value, { group_by: "employee,project" })}`, {}, "Unable to load the time report.");
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load the time report.");
  } finally {
    isLoading.value = false;
  }
}

async function openCell(employeeId: number, employeeEmail: string, projectId: number, projectTitle: string) {
  selected.value = { employeeId, employeeEmail, projectId, projectTitle };
  selectedTaskKey.value = null;
  isDetailLoading.value = true;
  errorMessage.value = "";
  const scoped = { ...filters.value, projectId, userId: employeeId };
  try {
    const [byTask, list] = await Promise.all([
      apiRequest<TimeReport>(`/operations/time-report?${timeQuery(scoped, { group_by: "task" })}`),
      apiRequest<TimeEntry[]>(`/operations/time-entries?${timeQuery(scoped)}`),
    ]);
    taskRows.value = byTask.rows;
    entries.value = list;
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load the breakdown.");
  } finally {
    isDetailLoading.value = false;
  }
}

async function exportCsv() {
  isExporting.value = true;
  errorMessage.value = "";
  try {
    await downloadFile(`/operations/time-report/export?${timeQuery(filters.value, { group_by: "employee,project,task" })}`, `time-report-${filters.value.from || "all"}-${filters.value.to || "all"}.csv`, "Unable to export the time report.");
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to export the time report.");
  } finally {
    isExporting.value = false;
  }
}

async function loadWorkload() {
  isWorkloadLoading.value = true;
  errorMessage.value = "";
  try {
    workload.value = await apiRequest<Workload>("/operations/time-report/workload", {}, "Unable to load the workload.");
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load the workload.");
  } finally {
    isWorkloadLoading.value = false;
  }
}

watch(view, (value) => {
  if (value === "workload" && workload.value === null) void loadWorkload();
});

onMounted(async () => {
  try {
    [projects.value, people.value] = await Promise.all([apiRequest<Project[]>("/projects"), apiRequest<Person[]>("/projects/managers")]);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load filter options.");
  }
  await loadReport();
});

function weekLabel(start: string) {
  return new Intl.DateTimeFormat(undefined, { day: "numeric", month: "short" }).format(new Date(`${start}T00:00:00`));
}
</script>

<template>
  <div class="page-content time-report-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">Time report</p>
        <h1>Where the hours go.</h1>
      </div>
    </div>

    <div class="view-tabs" role="tablist" aria-label="Time report views">
      <button role="tab" type="button" :aria-selected="view === 'report'" :class="{ active: view === 'report' }" @click="view = 'report'">Employees × projects</button>
      <button role="tab" type="button" :aria-selected="view === 'workload'" :class="{ active: view === 'workload' }" @click="view = 'workload'">Weekly workload</button>
    </div>

    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>

    <template v-if="view === 'report'">
      <form class="pm-filters" @submit.prevent="loadReport">
        <div>
          <label for="time-preset">Period</label>
          <select id="time-preset" v-model="preset">
            <option value="this_week">This week</option>
            <option value="last_week">Last week</option>
            <option value="this_month">This month</option>
            <option value="last_month">Last month</option>
            <option value="custom">Custom</option>
          </select>
        </div>
        <div>
          <label for="time-from">From</label>
          <input id="time-from" v-model="filters.from" type="date" @input="preset = 'custom'" />
        </div>
        <div>
          <label for="time-to">To</label>
          <input id="time-to" v-model="filters.to" type="date" @input="preset = 'custom'" />
        </div>
        <div>
          <label for="time-project">Project</label>
          <select id="time-project" v-model="filters.projectId">
            <option :value="null">All projects</option>
            <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.title }}</option>
          </select>
        </div>
        <div>
          <label for="time-employee">Employee</label>
          <select id="time-employee" v-model="filters.userId">
            <option :value="null">Everyone</option>
            <option v-for="person in people" :key="person.id" :value="person.id">{{ person.email }}</option>
          </select>
        </div>
        <div>
          <label for="time-status">Approval</label>
          <select id="time-status" v-model="filters.status">
            <option value="">Any status</option>
            <option value="pending_approval">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
          </select>
        </div>
        <div>
          <label for="time-billable">Billable</label>
          <select id="time-billable" v-model="filters.billable">
            <option value="">All time</option>
            <option value="true">Billable</option>
            <option value="false">Non-billable</option>
          </select>
        </div>
        <button class="edit-button" type="submit" :disabled="isLoading">Apply</button>
        <button class="edit-button" type="button" :disabled="isExporting || isLoading" @click="exportCsv">{{ isExporting ? "Exporting..." : "Export CSV" }}</button>
      </form>

      <div v-if="isLoading" class="empty-page"><h2>Loading the time report...</h2></div>
      <template v-else-if="report">
        <p class="time-scope-note muted">{{ scopeNotes[report.scope] }}</p>
        <div class="time-summary">
          <div><span class="card-kicker">Total</span><strong>{{ formatMinutes(report.totals.total_minutes) }}</strong></div>
          <div><span class="card-kicker">Approved</span><strong>{{ formatMinutes(report.totals.approved_minutes) }}</strong></div>
          <div><span class="card-kicker">Pending</span><strong>{{ formatMinutes(report.totals.pending_minutes) }}</strong></div>
          <div><span class="card-kicker">Billable</span><strong>{{ formatMinutes(report.totals.billable_minutes) }}</strong></div>
          <div v-for="cost in report.totals.approved_cost ?? []" :key="cost.currency"><span class="card-kicker">Approved cost</span><strong>{{ cost.amount }} {{ cost.currency }}</strong></div>
        </div>

        <div v-if="matrix.employees.length === 0" class="empty-page">
          <h2>No time in this period.</h2>
          <p class="muted">Change the filters, or log time in the Timesheet or on a task.</p>
        </div>
        <div v-else class="finance-table-wrap">
          <table class="finance-table time-matrix">
            <caption class="visually-hidden">Time per employee and project. Select a cell for the task breakdown.</caption>
            <thead>
              <tr>
                <th scope="col">Employee</th>
                <th v-for="project in matrix.projects" :key="project.id" scope="col">{{ project.title }}</th>
                <th scope="col">Total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="employee in matrix.employees" :key="employee.id">
                <th scope="row">{{ employee.email }}</th>
                <td v-for="project in matrix.projects" :key="project.id">
                  <button
                    v-if="matrix.cells.get(cellKey(employee.id, project.id))"
                    class="time-cell"
                    :class="{ active: selected?.employeeId === employee.id && selected?.projectId === project.id }"
                    type="button"
                    :aria-label="`${employee.email} on ${project.title}: ${formatMinutes(matrix.cells.get(cellKey(employee.id, project.id))!.total_minutes)}. Show tasks.`"
                    @click="openCell(employee.id, employee.email, project.id, project.title)"
                  >
                    {{ formatMinutes(matrix.cells.get(cellKey(employee.id, project.id))!.total_minutes) }}
                    <small v-if="matrix.cells.get(cellKey(employee.id, project.id))!.pending_minutes > 0">{{ formatMinutes(matrix.cells.get(cellKey(employee.id, project.id))!.pending_minutes) }} pending</small>
                  </button>
                  <span v-else class="muted" aria-label="No time">–</span>
                </td>
                <td><strong>{{ formatMinutes(employee.total) }}</strong></td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="finance-table-total">
                <th scope="row">Total</th>
                <th v-for="project in matrix.projects" :key="project.id">{{ formatMinutes(project.total) }}</th>
                <th>{{ formatMinutes(matrix.total) }}</th>
              </tr>
            </tfoot>
          </table>
        </div>

        <section v-if="selected" class="pm-panel time-detail" aria-live="polite">
          <div class="pm-section-heading">
            <div>
              <p class="card-kicker">{{ selected.employeeEmail }}</p>
              <h2>{{ selected.projectTitle }}</h2>
            </div>
            <button class="close-button" type="button" aria-label="Close breakdown" @click="selected = null">&#10005;</button>
          </div>
          <div v-if="isDetailLoading" class="muted">Loading tasks...</div>
          <template v-else>
            <div class="finance-table-wrap">
              <table class="finance-table">
                <thead>
                  <tr><th scope="col">Task</th><th scope="col">Logged</th><th scope="col">Approved</th><th scope="col">Pending</th><th scope="col">Task estimate</th><th scope="col"><span class="visually-hidden">Entries</span></th></tr>
                </thead>
                <tbody>
                  <tr v-for="row in taskRows" :key="row.task_id ?? 'none'" :class="{ 'time-row-active': selectedTaskKey === String(row.task_id ?? 'none') }">
                    <th scope="row">{{ row.task_title ?? "No task" }}</th>
                    <td>{{ formatMinutes(row.total_minutes) }}</td>
                    <td>{{ formatMinutes(row.approved_minutes) }}</td>
                    <td>{{ formatMinutes(row.pending_minutes) }}</td>
                    <td>
                      <template v-if="row.task_estimated_minutes">{{ formatMinutes(row.task_estimated_minutes) }}</template>
                      <span v-else class="muted">—</span>
                    </td>
                    <td><button class="edit-button" type="button" @click="selectedTaskKey = selectedTaskKey === String(row.task_id ?? 'none') ? null : String(row.task_id ?? 'none')">{{ selectedTaskKey === String(row.task_id ?? "none") ? "All entries" : "Entries" }}</button></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p class="muted">The task estimate covers the whole task, all employees and dates.</p>
            <h3>Entries <span class="muted">({{ visibleEntries.length }})</span></h3>
            <ul class="time-entry-list">
              <li v-for="entry in visibleEntries" :key="entry.id">
                <span>{{ entry.work_date }}</span>
                <strong>{{ formatMinutes(entry.duration_minutes) }}</strong>
                <span>{{ entry.task_title ?? "No task" }} · {{ entry.description }}</span>
                <span class="muted">{{ entry.approval_status.replaceAll("_", " ") }}{{ entry.billable ? " · billable" : "" }}</span>
              </li>
            </ul>
          </template>
        </section>
      </template>
    </template>

    <template v-else>
      <div v-if="isWorkloadLoading" class="empty-page"><h2>Loading the workload...</h2></div>
      <template v-else-if="workload">
        <p class="time-scope-note muted">{{ scopeNotes[workload.scope] }} {{ workload.basis }}</p>
        <p v-if="workload.unscheduled_tasks > 0" class="muted">{{ workload.unscheduled_tasks }} estimated task(s) have no due date and are not placed in a week.</p>
        <div v-if="workload.employees.length === 0" class="empty-page"><h2>No logged or planned work in these weeks.</h2></div>
        <div v-else class="finance-table-wrap">
          <table class="finance-table time-workload">
            <caption class="visually-hidden">Logged and planned minutes per employee and week; weeks above {{ formatMinutes(workload.capacity_minutes) }} are marked overloaded.</caption>
            <thead>
              <tr>
                <th scope="col">Employee</th>
                <th v-for="week in workload.weeks" :key="week" scope="col">{{ weekLabel(week) }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="employee in workload.employees" :key="employee.employee_id">
                <th scope="row">{{ employee.employee_email }}</th>
                <td v-for="week in employee.weeks" :key="week.week_start" :class="{ 'time-overloaded': week.overloaded }">
                  <span>{{ formatMinutes(week.logged_minutes) }}</span>
                  <small v-if="week.planned_minutes > 0"> + {{ formatMinutes(week.planned_minutes) }} planned</small>
                  <strong v-if="week.overloaded" class="time-flag">Overloaded</strong>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <button class="edit-button" type="button" @click="loadWorkload">Refresh</button>
      </template>
    </template>
  </div>
</template>
