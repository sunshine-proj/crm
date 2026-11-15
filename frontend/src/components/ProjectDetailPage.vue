<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { overdueDays, projectManagementStatus } from "../lib/projectManagement";
import { detailHref } from "../lib/route";
import { isFinanceToken } from "../lib/session";
import { estimateProgress, formatMinutes, scopeNotes, type TimeReport } from "../lib/timeTracking";
import EntityNotesPanel from "./EntityNotesPanel.vue";
import RecordChangeHistory from "./RecordChangeHistory.vue";

const props = defineProps<{ projectId: number }>();

type Project = {
  id: number; title: string; description: string; client_id: number | null; client_title: string | null; manager_email: string | null;
  status: "planned" | "active" | "on_hold" | "completed" | "cancelled"; start_date: string | null; end_date: string | null;
  budget_amount: string | null; budget_currency: string | null; hourly_rate: string | null; billing_currency: string | null;
};
type Task = { id: number; title: string; project_id: number | null; status: string; assignee_email: string | null; due_date: string | null; due_status: string | null; estimated_minutes: number | null; logged_minutes: number; over_estimate: boolean };
type Milestone = { id: number; title: string; due_date: string | null; amount: string | null; currency: string | null; status: string };
type Expense = { id: number; description: string; amount: string; currency: string; status: string; expense_date: string };
type Invoice = { id: number; invoice_number: string | null; total: string; currency: string; balance_due: string; status: string };
type Profitability = {
  currency: string; approved_minutes: number; pending_approval_minutes: number; approved_billable_revenue: string; approved_labor_cost: string;
  approved_expense_cost: string; total_cost: string; gross_margin: string; budget: string | null; budget_variance: string | null; basis: string;
  budget_consumed_percent: number | null; budget_warning: boolean; budget_warning_percent: number;
};

const project = ref<Project | null>(null);
const tasks = ref<Task[]>([]);
const milestones = ref<Milestone[]>([]);
const timeByEmployee = ref<TimeReport | null>(null);
const timeByTask = ref<TimeReport | null>(null);
const expenses = ref<Expense[]>([]);
const invoices = ref<Invoice[]>([]);
const profitability = ref<Profitability | null>(null);
const isLoading = ref(true);
const errorMessage = ref("");
const periodFrom = ref("");
const periodTo = ref("");
const reportError = ref("");
const canSeeFinance = isFinanceToken(localStorage.getItem("crm_token"));
const today = new Date().toISOString().slice(0, 10);

const status = computed(() => (project.value ? projectManagementStatus(project.value, today) : null));
const lateDays = computed(() => (project.value && status.value === "overdue" ? overdueDays(project.value.end_date, today) : 0));
const openTasks = computed(() => tasks.value.filter((task) => task.status !== "done" && task.status !== "cancelled"));
const loggedMinutes = computed(() => timeByEmployee.value?.totals.total_minutes ?? 0);
const estimatedTasks = computed(() => tasks.value.filter((task) => task.status !== "cancelled"));
const estimatedMinutes = computed(() => estimatedTasks.value.reduce((sum, task) => sum + (task.estimated_minutes ?? 0), 0));
const loggedOnTasks = computed(() => estimatedTasks.value.reduce((sum, task) => sum + task.logged_minutes, 0));
const unestimatedTasks = computed(() => estimatedTasks.value.filter((task) => !task.estimated_minutes).length);
const projectProgress = computed(() => estimateProgress(estimatedMinutes.value, loggedOnTasks.value));
const taskById = computed(() => new Map(tasks.value.map((task) => [task.id, task])));
const hours = formatMinutes;

async function loadProfitability() {
  if (!canSeeFinance || !project.value) return;
  reportError.value = "";
  const currency = project.value.budget_currency ?? project.value.billing_currency ?? "EUR";
  const query = new URLSearchParams({ currency });
  if (periodFrom.value) query.set("from", periodFrom.value);
  if (periodTo.value) query.set("to", periodTo.value);
  try {
    profitability.value = await apiRequest<Profitability>(`/operations/projects/${props.projectId}/profitability?${query.toString()}`);
  } catch (error) {
    profitability.value = null;
    reportError.value = errorText(error, "Unable to load the financial report.");
  }
}

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    project.value = await apiRequest<Project>(`/projects/${props.projectId}`);
    const [allTasks, loadedMilestones, byEmployee, byTask, loadedExpenses, loadedInvoices] = await Promise.all([
      apiRequest<Task[]>("/tasks"),
      apiRequest<Milestone[]>(`/projects/${props.projectId}/milestones`),
      apiRequest<TimeReport>(`/operations/time-report?project_id=${props.projectId}&group_by=employee`),
      apiRequest<TimeReport>(`/operations/time-report?project_id=${props.projectId}&group_by=task`),
      apiRequest<Expense[]>(`/operations/expenses?project_id=${props.projectId}`),
      apiRequest<Invoice[]>(`/invoices?project_id=${props.projectId}`),
    ]);
    tasks.value = allTasks.filter((task) => task.project_id === props.projectId);
    milestones.value = loadedMilestones;
    timeByEmployee.value = byEmployee;
    timeByTask.value = byTask;
    expenses.value = loadedExpenses;
    invoices.value = loadedInvoices;
    await loadProfitability();
  } catch (error) {
    project.value = null;
    errorMessage.value = errorText(error, "Unable to load the project.");
  } finally {
    isLoading.value = false;
  }
}

onMounted(load);
watch(() => props.projectId, load);
</script>

<template>
  <div class="page-content">
    <a class="detail-back" href="#">&larr; All projects</a>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading project...</h2></div>

    <template v-else-if="project">
      <div class="page-heading">
        <div>
          <p class="eyebrow">Project <span class="status-pill">{{ status?.replace("_", " ") }}</span></p>
          <h1>{{ project.title }}</h1>
        </div>
      </div>

      <div class="detail-grid">
        <section class="detail-card">
          <h2>Overview</h2>
          <dl>
            <dt>Client</dt><dd><a v-if="project.client_id" :href="detailHref('client', project.client_id)">{{ project.client_title }}</a><template v-else>Not linked</template></dd>
            <dt>Manager</dt><dd>{{ project.manager_email ?? "—" }}</dd>
            <dt>Lifecycle</dt><dd>{{ project.status.replace("_", " ") }}</dd>
            <dt>Budget</dt><dd>{{ project.budget_amount ? `${project.budget_amount} ${project.budget_currency}` : "—" }}</dd>
            <dt>Billing rate</dt><dd>{{ project.hourly_rate ? `${project.hourly_rate} ${project.billing_currency} / h` : "—" }}</dd>
          </dl>
          <p class="muted">{{ project.description }}</p>
        </section>

        <section class="detail-card">
          <h2>Schedule</h2>
          <dl>
            <dt>Planned start</dt><dd>{{ project.start_date ?? "Unscheduled" }}</dd>
            <dt>Planned end</dt><dd>{{ project.end_date ?? "Unscheduled" }}</dd>
            <dt>Open tasks</dt><dd>{{ openTasks.length }} of {{ tasks.length }}</dd>
            <dt>Time logged</dt><dd>{{ hours(loggedMinutes) }}<span v-if="timeByEmployee?.scope === 'own'" class="muted"> (yours)</span></dd>
            <dt>Estimated effort</dt><dd>{{ estimatedMinutes ? hours(estimatedMinutes) : "No estimates" }}<span v-if="unestimatedTasks > 0" class="muted"> · {{ unestimatedTasks }} task{{ unestimatedTasks === 1 ? "" : "s" }} without one</span></dd>
            <dt>Logged on tasks</dt><dd :class="{ 'time-over-text': projectProgress.over }">{{ hours(loggedOnTasks) }}<template v-if="projectProgress.percent !== null"> · {{ projectProgress.percent }}% of estimate</template></dd>
          </dl>
          <p v-if="lateDays > 0" class="error">Overdue by {{ lateDays }} day{{ lateDays === 1 ? "" : "s" }}.</p>
          <h3>Milestones</h3>
          <p v-if="milestones.length === 0" class="muted">No milestones.</p>
          <ul v-else class="detail-list">
            <li v-for="milestone in milestones" :key="milestone.id">
              {{ milestone.title }} <span class="status-pill">{{ milestone.status }}</span>
              <span class="muted"> {{ milestone.due_date ?? "no date" }}<template v-if="milestone.amount"> · {{ milestone.amount }} {{ milestone.currency }}</template></span>
            </li>
          </ul>
        </section>

        <section class="detail-card wide">
          <h2>Tasks</h2>
          <p v-if="tasks.length === 0" class="muted">No tasks yet.</p>
          <ul v-else class="detail-list">
            <li v-for="task in tasks" :key="task.id">
              {{ task.title }} <span class="status-pill">{{ task.status.replace("_", " ") }}</span>
              <span class="muted"> {{ task.assignee_email ?? "Unassigned" }}<template v-if="task.due_date"> · due {{ task.due_date }}</template></span>
              <span v-if="task.due_status === 'overdue'" class="error"> overdue</span>
            </li>
          </ul>
        </section>

        <section class="detail-card">
          <div class="pm-section-heading">
            <h2>Time by employee</h2>
            <a class="edit-button" :href="`#/time-report/projects/${project.id}`">Open in time report</a>
          </div>
          <p v-if="timeByEmployee" class="muted">{{ scopeNotes[timeByEmployee.scope] }}</p>
          <p v-if="!timeByEmployee?.rows.length" class="muted">No time recorded.</p>
          <ul v-else class="detail-list">
            <li v-for="row in timeByEmployee.rows" :key="row.employee_id">
              {{ row.employee_email }} · <strong>{{ hours(row.total_minutes) }}</strong>
              <span class="muted"> {{ hours(row.approved_minutes) }} approved<template v-if="row.pending_minutes"> · {{ hours(row.pending_minutes) }} pending</template></span>
            </li>
          </ul>
        </section>

        <section class="detail-card wide">
          <h2>Time by task</h2>
          <p v-if="!timeByTask?.rows.length" class="muted">No time recorded.</p>
          <div v-else class="finance-table-wrap">
            <table class="finance-table">
              <thead><tr><th scope="col">Task</th><th scope="col">Logged</th><th scope="col">Estimate</th><th scope="col">Progress</th></tr></thead>
              <tbody>
                <tr v-for="row in timeByTask.rows" :key="row.task_id ?? 'none'">
                  <th scope="row">{{ row.task_title ?? "No task" }}</th>
                  <td>{{ hours(row.total_minutes - row.rejected_minutes) }}</td>
                  <td>{{ row.task_estimated_minutes ? hours(row.task_estimated_minutes) : "—" }}</td>
                  <td>
                    <template v-if="row.task_id && taskById.get(row.task_id)?.estimated_minutes">
                      <span :class="{ 'time-over-text': taskById.get(row.task_id)?.over_estimate }">{{ estimateProgress(taskById.get(row.task_id)?.estimated_minutes, taskById.get(row.task_id)?.logged_minutes ?? 0).percent }}%<template v-if="taskById.get(row.task_id)?.over_estimate"> · over estimate</template></span>
                    </template>
                    <span v-else class="muted">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="muted">Progress compares everyone's pending and approved time on the task with its estimate; rejected time is not counted.</p>
        </section>

        <section class="detail-card">
          <h2>Expenses <span class="muted">({{ expenses.length }})</span></h2>
          <p v-if="expenses.length === 0" class="muted">No expenses.</p>
          <ul v-else class="detail-list">
            <li v-for="expense in expenses.slice(0, 8)" :key="expense.id">
              {{ expense.expense_date }} · {{ expense.description }} · {{ expense.amount }} {{ expense.currency }}
              <span class="status-pill">{{ expense.status.replace("_", " ") }}</span>
            </li>
          </ul>
        </section>

        <section class="detail-card">
          <h2>Invoices <span class="muted">({{ invoices.length }})</span></h2>
          <p v-if="invoices.length === 0" class="muted">No invoices for this project.</p>
          <ul v-else class="detail-list">
            <li v-for="invoice in invoices" :key="invoice.id">
              {{ invoice.invoice_number ?? `Draft #${invoice.id}` }} <span class="status-pill">{{ invoice.status.replace("_", " ") }}</span>
              <span class="muted"> {{ invoice.total }} {{ invoice.currency }} · balance {{ invoice.balance_due }}</span>
            </li>
          </ul>
        </section>

        <section v-if="canSeeFinance" class="detail-card">
          <h2>Financial report</h2>
          <form class="pm-filters" @submit.prevent="loadProfitability">
            <div><label for="period-from">From</label><input id="period-from" v-model="periodFrom" type="date" /></div>
            <div><label for="period-to">To</label><input id="period-to" v-model="periodTo" type="date" /></div>
            <button class="edit-button" type="submit">Apply period</button>
          </form>
          <p v-if="reportError" class="error" role="alert">{{ reportError }}</p>
          <dl v-if="profitability">
            <dt>Approved time</dt><dd>{{ hours(profitability.approved_minutes) }} (pending {{ hours(profitability.pending_approval_minutes) }})</dd>
            <dt>Billable revenue</dt><dd>{{ profitability.approved_billable_revenue }} {{ profitability.currency }}</dd>
            <dt>Labor cost</dt><dd>{{ profitability.approved_labor_cost }} {{ profitability.currency }}</dd>
            <dt>Expense cost</dt><dd>{{ profitability.approved_expense_cost }} {{ profitability.currency }}</dd>
            <dt>Gross margin</dt><dd>{{ profitability.gross_margin }} {{ profitability.currency }}</dd>
            <dt>Budget variance</dt><dd>{{ profitability.budget_variance ?? "n/a for a period" }}</dd>
            <dt>Budget used</dt><dd :class="{ 'time-over-text': profitability.budget_warning }">{{ profitability.budget_consumed_percent !== null ? `${profitability.budget_consumed_percent}%` : "n/a" }}</dd>
          </dl>
          <p v-if="profitability?.budget_warning" class="error" role="alert">Approved cost has reached {{ profitability.budget_consumed_percent }}% of the budget (warning at {{ profitability.budget_warning_percent }}%).</p>
          <p v-if="profitability" class="muted">{{ profitability.basis }}</p>
        </section>

        <div class="detail-card wide"><EntityNotesPanel entity-type="project" :entity-id="project.id" /></div>
        <RecordChangeHistory class="wide" record-type="project" :record-id="project.id" />
      </div>
    </template>
  </div>
</template>
