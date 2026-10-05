<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";

type Project = { id: number; title: string; client_title: string | null; manager_email: string | null; budget_amount: string | null; budget_currency: string | null };
type Task = { id: number; title: string; project_id: number | null };
type TimeEntry = { id: number; project_id: number; project_title: string; task_id: number | null; task_title: string | null; user_id: number; user_email: string; work_date: string; duration_minutes: number; billable: boolean; description: string };
type ExpenseApproval = { id: number; decision: string; actor_email: string; comment: string | null; created_at: string };
type Expense = { id: number; project_id: number; project_title: string; submitter_id: number; submitter_email: string; description: string; amount: string; currency: string; expense_date: string; status: "pending_approval" | "approved" | "rejected"; approvals: ExpenseApproval[] };
type ProjectDocument = { id: number; project_id: number; original_name: string; mime_type: string; size_bytes: number; uploaded_by: string | null; created_at: string; download_url: string };

const tabs = ["Time entries", "Expenses", "Approvals", "Documents"] as const;
type Tab = (typeof tabs)[number];
const tab = ref<Tab>("Time entries");
const projects = ref<Project[]>([]);
const tasks = ref<Task[]>([]);
const timeEntries = ref<TimeEntry[]>([]);
const expenses = ref<Expense[]>([]);
const pendingExpenses = ref<Expense[]>([]);
const documents = ref<ProjectDocument[]>([]);
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref("");
const noticeMessage = ref("");
const selectedProjectId = ref<number | null>(null);
const timeProjectId = ref<number | null>(null);
const timeTaskId = ref<number | null>(null);
const timeDate = ref(new Date().toISOString().slice(0, 10));
const timeMinutes = ref(60);
const timeBillable = ref(true);
const timeDescription = ref("");
const expenseProjectId = ref<number | null>(null);
const expenseDescription = ref("");
const expenseAmount = ref("");
const expenseCurrency = ref("EUR");
const expenseDate = ref(new Date().toISOString().slice(0, 10));
const approvalComments = ref<Record<number, string>>({});
const selectedFile = ref<File | null>(null);

const projectTasks = computed(() => tasks.value.filter((task) => task.project_id === timeProjectId.value));
const selectedProject = computed(() => projects.value.find((project) => project.id === selectedProjectId.value) ?? null);
const totalLoggedMinutes = computed(() => timeEntries.value.reduce((total, entry) => total + entry.duration_minutes, 0));
const usedStorage = computed(() => (document: ProjectDocument) => `${(document.size_bytes / 1024 / 1024).toFixed(2)} MB`);

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = localStorage.getItem("crm_token");
  const response = await fetch(`/api${path}`, {
    ...options,
    headers: { ...(token ? { Authorization: `Bearer ${token}` } : {}), ...options.headers },
  });
  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: string } | null;
    throw new Error(payload?.error ?? "Unable to complete the operations request.");
  }
  return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}

async function loadTimeEntries() {
  if (selectedProjectId.value === null) {
    timeEntries.value = [];
    return;
  }
  timeEntries.value = await request<TimeEntry[]>(`/operations/time-entries?project_id=${selectedProjectId.value}`);
}

async function loadDocuments() {
  if (selectedProjectId.value === null) {
    documents.value = [];
    return;
  }
  documents.value = await request<ProjectDocument[]>(`/operations/projects/${selectedProjectId.value}/documents`);
}

async function loadOperations() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    const [loadedProjects, loadedTasks, loadedExpenses, pending] = await Promise.all([
      request<Project[]>("/projects"),
      request<Task[]>("/tasks"),
      request<Expense[]>("/operations/expenses"),
      request<Expense[]>("/operations/expenses/pending-approval"),
    ]);
    projects.value = loadedProjects;
    tasks.value = loadedTasks;
    expenses.value = loadedExpenses;
    pendingExpenses.value = pending;
    if (selectedProjectId.value === null) selectedProjectId.value = projects.value[0]?.id ?? null;
    if (timeProjectId.value === null) timeProjectId.value = projects.value[0]?.id ?? null;
    if (expenseProjectId.value === null) expenseProjectId.value = projects.value[0]?.id ?? null;
    await Promise.all([loadTimeEntries(), loadDocuments()]);
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load operations records.";
  } finally {
    isLoading.value = false;
  }
}

onMounted(loadOperations);
watch(selectedProjectId, () => {
  if (!isLoading.value) void loadDocuments().catch((error: unknown) => { errorMessage.value = error instanceof Error ? error.message : "Unable to load project documents."; });
});
watch(timeProjectId, () => {
  timeTaskId.value = null;
  if (!isLoading.value) void loadTimeEntries().catch((error: unknown) => { errorMessage.value = error instanceof Error ? error.message : "Unable to load time entries."; });
});

async function saveTimeEntry() {
  if (timeProjectId.value === null || !timeDate.value || !Number.isInteger(timeMinutes.value) || timeMinutes.value < 1 || timeMinutes.value > 1440 || !timeDescription.value.trim()) {
    errorMessage.value = "Choose a project, date, duration from 1 to 1440 minutes, and description.";
    return;
  }
  isSaving.value = true;
  errorMessage.value = "";
  noticeMessage.value = "";
  try {
    const entry = await request<TimeEntry>("/operations/time-entries", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ project_id: timeProjectId.value, task_id: timeTaskId.value, work_date: timeDate.value, duration_minutes: timeMinutes.value, billable: timeBillable.value, description: timeDescription.value.trim() }),
    });
    if (selectedProjectId.value === entry.project_id) timeEntries.value.unshift(entry);
    timeDescription.value = "";
    noticeMessage.value = "Time entry recorded.";
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to record time entry.";
  } finally {
    isSaving.value = false;
  }
}

async function submitExpense() {
  if (expenseProjectId.value === null || !expenseDescription.value.trim() || !expenseDate.value || !/^(0|[1-9]\d*)(?:\.\d{1,2})?$/.test(expenseAmount.value)) {
    errorMessage.value = "Complete project, date, description, and a valid positive amount.";
    return;
  }
  isSaving.value = true;
  errorMessage.value = "";
  noticeMessage.value = "";
  try {
    const expense = await request<Expense>("/operations/expenses", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ project_id: expenseProjectId.value, description: expenseDescription.value.trim(), amount: expenseAmount.value, currency: expenseCurrency.value, expense_date: expenseDate.value }),
    });
    expenses.value.unshift(expense);
    expenseDescription.value = "";
    expenseAmount.value = "";
    noticeMessage.value = "Expense submitted for approval.";
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to submit expense.";
  } finally {
    isSaving.value = false;
  }
}

async function decideExpense(expense: Expense, decision: "approved" | "rejected") {
  isSaving.value = true;
  errorMessage.value = "";
  try {
    const updated = await request<Expense>(`/operations/expenses/${expense.id}/decision`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ decision, comment: approvalComments.value[expense.id]?.trim() || null }),
    });
    expenses.value = expenses.value.map((item) => item.id === updated.id ? updated : item);
    pendingExpenses.value = pendingExpenses.value.filter((item) => item.id !== updated.id);
    noticeMessage.value = `Expense ${decision}.`;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to decide expense.";
  } finally {
    isSaving.value = false;
  }
}

function selectDocument(event: Event) {
  const input = event.target as HTMLInputElement;
  selectedFile.value = input.files?.[0] ?? null;
}

async function uploadDocument() {
  if (selectedProjectId.value === null || selectedFile.value === null) {
    errorMessage.value = "Choose a project and a file to upload.";
    return;
  }
  isSaving.value = true;
  errorMessage.value = "";
  try {
    const data = new FormData();
    data.append("file", selectedFile.value);
    const document = await request<ProjectDocument>(`/operations/projects/${selectedProjectId.value}/documents`, { method: "POST", body: data });
    documents.value.unshift(document);
    selectedFile.value = null;
    noticeMessage.value = "Project document uploaded.";
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to upload document.";
  } finally {
    isSaving.value = false;
  }
}

async function downloadDocument(uploadedDocument: ProjectDocument) {
  try {
    const token = localStorage.getItem("crm_token");
    const response = await fetch(uploadedDocument.download_url, { headers: token ? { Authorization: `Bearer ${token}` } : {} });
    if (!response.ok) throw new Error("Unable to download project document.");
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement("a");
    link.href = url;
    link.download = uploadedDocument.original_name;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to download project document.";
  }
}
</script>

<template>
  <div class="page-content operations-page">
    <div class="page-heading"><div><p class="eyebrow">Project operations</p><h1>Plan, record, and review the work.</h1></div></div>
    <nav class="sales-tabs" aria-label="Operations sections"><button v-for="item in tabs" :key="item" type="button" :class="{ active: tab === item }" :aria-pressed="tab === item" @click="tab = item; errorMessage = ''; noticeMessage = ''">{{ item }}</button></nav>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p><p v-if="noticeMessage" class="sales-notice" role="status">{{ noticeMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading operations...</h2></div>

    <template v-else-if="tab === 'Time entries'">
      <section class="operations-section"><div class="finance-section-heading"><div><p class="card-kicker">Time tracking</p><h2>Record work against a project</h2></div></div>
        <form class="operations-form-grid" @submit.prevent="saveTimeEntry"><div><label for="time-project">Project</label><select id="time-project" v-model.number="timeProjectId"><option :value="null" disabled>Select project</option><option v-for="project in projects" :key="project.id" :value="project.id">{{ project.title }}</option></select></div><div><label for="time-task">Task</label><select id="time-task" v-model.number="timeTaskId"><option :value="null">No task</option><option v-for="task in projectTasks" :key="task.id" :value="task.id">{{ task.title }}</option></select></div><div><label for="time-date">Work date</label><input id="time-date" v-model="timeDate" type="date" required /></div><div><label for="time-minutes">Duration (minutes)</label><input id="time-minutes" v-model.number="timeMinutes" type="number" min="1" max="1440" step="1" required /></div><div><label for="time-description">Work summary</label><input id="time-description" v-model="timeDescription" maxlength="255" required /></div><label class="operations-checkbox"><input v-model="timeBillable" type="checkbox" /> Billable</label><button class="edit-button" type="submit" :disabled="isSaving">{{ isSaving ? "Recording..." : "Record time" }}</button></form>
        <div class="operations-summary"><span>{{ timeEntries.length }} entries</span><strong>{{ Math.floor(totalLoggedMinutes / 60) }}h {{ totalLoggedMinutes % 60 }}m logged</strong></div>
        <div v-if="timeEntries.length" class="operations-record-list"><article v-for="entry in timeEntries" :key="entry.id"><div><p class="card-kicker">{{ entry.work_date }} · {{ entry.user_email }} · {{ entry.billable ? "Billable" : "Non-billable" }}</p><h3>{{ entry.description }}</h3><span>{{ entry.project_title }}<span v-if="entry.task_title"> · {{ entry.task_title }}</span></span></div><strong>{{ Math.floor(entry.duration_minutes / 60) }}h {{ entry.duration_minutes % 60 }}m</strong></article></div><p v-else class="muted">No time entries for this project.</p>
      </section>
    </template>

    <template v-else-if="tab === 'Expenses'">
      <section class="operations-section"><div class="finance-section-heading"><div><p class="card-kicker">Project costs</p><h2>Submit an expense for approval</h2></div></div>
        <form class="operations-form-grid" @submit.prevent="submitExpense"><div><label for="expense-project">Project</label><select id="expense-project" v-model.number="expenseProjectId"><option :value="null" disabled>Select project</option><option v-for="project in projects" :key="project.id" :value="project.id">{{ project.title }}</option></select></div><div><label for="expense-date">Date</label><input id="expense-date" v-model="expenseDate" type="date" required /></div><div><label for="expense-description">Description</label><input id="expense-description" v-model="expenseDescription" maxlength="255" required /></div><div><label for="expense-amount">Amount</label><input id="expense-amount" v-model="expenseAmount" inputmode="decimal" placeholder="0.00" required /></div><div><label for="expense-currency">Currency</label><select id="expense-currency" v-model="expenseCurrency"><option>EUR</option><option>GBP</option><option>USD</option></select></div><button class="edit-button" type="submit" :disabled="isSaving">Submit expense</button></form>
        <div v-if="expenses.length" class="operations-record-list"><article v-for="expense in expenses" :key="expense.id"><div><p class="card-kicker">{{ expense.project_title }} · {{ expense.expense_date }} · {{ expense.status.replaceAll("_", " ") }}</p><h3>{{ expense.description }}</h3><span>Submitted by {{ expense.submitter_email }}</span><div v-if="expense.approvals.length" class="operations-history"><p v-for="approval in expense.approvals" :key="approval.id">{{ approval.decision }} by {{ approval.actor_email }} · {{ approval.created_at }}<span v-if="approval.comment"> · {{ approval.comment }}</span></p></div></div><strong>{{ expense.amount }} {{ expense.currency }}</strong></article></div><p v-else class="muted">No expenses submitted.</p>
      </section>
    </template>

    <template v-else-if="tab === 'Approvals'">
      <section class="operations-section"><div class="finance-section-heading"><div><p class="card-kicker">Approval workflow</p><h2>Pending project expenses</h2></div></div>
        <div v-if="pendingExpenses.length" class="operations-record-list"><article v-for="expense in pendingExpenses" :key="expense.id"><div><p class="card-kicker">{{ expense.project_title }} · {{ expense.expense_date }} · {{ expense.submitter_email }}</p><h3>{{ expense.description }}</h3><strong>{{ expense.amount }} {{ expense.currency }}</strong><label :for="`approval-comment-${expense.id}`">Decision note</label><input :id="`approval-comment-${expense.id}`" v-model="approvalComments[expense.id]" maxlength="255" placeholder="Optional explanation" /></div><div class="operations-approval-actions"><button class="edit-button" type="button" :disabled="isSaving" @click="decideExpense(expense, 'approved')">Approve</button><button class="edit-button danger-button" type="button" :disabled="isSaving" @click="decideExpense(expense, 'rejected')">Reject</button></div></article></div><p v-else class="muted">No expenses are awaiting your approval.</p>
      </section>
    </template>

    <template v-else>
      <section class="operations-section"><div class="finance-section-heading"><div><p class="card-kicker">Project documents</p><h2>Files attached to project work</h2></div></div>
        <div class="operations-form-grid"><div><label for="document-project">Project</label><select id="document-project" v-model.number="selectedProjectId"><option :value="null" disabled>Select project</option><option v-for="project in projects" :key="project.id" :value="project.id">{{ project.title }}</option></select></div><div><label for="project-file">Upload document (PDF, image, text, DOCX or XLSX; max 10 MB)</label><input id="project-file" type="file" accept=".pdf,.jpg,.jpeg,.png,.txt,.csv,.docx,.xlsx" @change="selectDocument" /></div><button class="edit-button" type="button" :disabled="isSaving || selectedFile === null" @click="uploadDocument">{{ isSaving ? "Uploading..." : "Upload" }}</button></div>
        <div v-if="documents.length" class="operations-record-list"><article v-for="document in documents" :key="document.id"><div><p class="card-kicker">{{ document.mime_type }} · {{ usedStorage(document) }} · {{ document.created_at }}</p><h3>{{ document.original_name }}</h3><span>Uploaded by {{ document.uploaded_by ?? "Former user" }}</span></div><button class="edit-button" type="button" @click="downloadDocument(document)">Download</button></article></div><p v-else class="muted">No project documents uploaded.</p>
      </section>
    </template>
  </div>
</template>