<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

type Client = { id: number; title: string };
type Manager = { id: number; email: string };
type Project = {
  id: number;
  title: string;
  client_id: number | null;
  client_title: string | null;
  manager_id: number | null;
  manager_email: string | null;
};
type Task = { id: number; title: string; project_id: number | null };
type InvoiceLine = { task_id: number | null; description: string; amount: string };
type Invoice = {
  id: number;
  invoice_number: string;
  client_id: number | null;
  client_name: string;
  project_id: number | null;
  project_name: string;
  manager_id: number | null;
  manager_name: string | null;
  invoice_date: string;
  due_date: string | null;
  currency: string;
  items: InvoiceLine[];
  total: string;
  status: string;
};
type InvoiceInput = {
  project_id: number;
  invoice_date: string;
  due_date: string | null;
  currency: string;
  items: InvoiceLine[];
};

const currencies = ["EUR", "GBP", "USD"];
const invoices = ref<Invoice[]>([]);
const clients = ref<Client[]>([]);
const managers = ref<Manager[]>([]);
const projects = ref<Project[]>([]);
const tasks = ref<Task[]>([]);
const isLoading = ref(true);
const isFormVisible = ref(false);
const isSaving = ref(false);
const isDeleting = ref(false);
const isExporting = ref(false);
const errorMessage = ref("");
const editingId = ref<number | null>(null);

const filterClientId = ref("");
const filterProjectId = ref("");
const filterManagerId = ref("");
const filterFrom = ref("");
const filterTo = ref("");
const filterCurrency = ref("");
const filterMinimum = ref("");
const filterMaximum = ref("");

const projectId = ref<number | null>(null);
const invoiceDate = ref("");
const dueDate = ref("");
const currency = ref("EUR");
const items = ref<InvoiceLine[]>([]);
const taskToAddId = ref<number | null>(null);
const savedInvoiceId = ref<number | null>(null);
const savedFormSignature = ref("");

const availableTasks = computed(() =>
  tasks.value.filter(
    (task) => task.project_id === projectId.value && !items.value.some((line) => line.task_id === task.id),
  ),
);

function parseMinor(amount: string): bigint | null {
  const match = /^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/.exec(amount);
  if (!match) return null;
  return BigInt(match[1]) * 100n + BigInt((match[2] ?? "").padEnd(2, "0"));
}

function formatMinor(amount: bigint): string {
  return `${amount / 100n}.${String(amount % 100n).padStart(2, "0")}`;
}

const totalMinor = computed(() =>
  items.value.reduce((total, item) => total + (parseMinor(item.amount) ?? 0n), 0n),
);
const formattedTotal = computed(() => formatMinor(totalMinor.value));
const formSignature = computed(() => JSON.stringify({
  project_id: projectId.value,
  invoice_date: invoiceDate.value,
  due_date: dueDate.value || null,
  currency: currency.value,
  items: items.value,
}));

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
    throw new Error(payload?.error ?? "Unable to complete the invoice request.");
  }

  return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}

async function loadInvoices() {
  const params = new URLSearchParams();
  const filters: [string, string][] = [
    ["client_id", filterClientId.value],
    ["project_id", filterProjectId.value],
    ["manager_id", filterManagerId.value],
    ["from", filterFrom.value],
    ["to", filterTo.value],
    ["currency", filterCurrency.value],
    ["min", filterMinimum.value],
    ["max", filterMaximum.value],
  ];
  for (const [key, value] of filters) {
    if (value) params.set(key, value);
  }
  invoices.value = await request<Invoice[]>(`/invoices${params.size ? `?${params.toString()}` : ""}`);
}

async function loadPage() {
  errorMessage.value = "";
  isLoading.value = true;
  try {
    const [loadedClients, loadedManagers, loadedProjects, loadedTasks] = await Promise.all([
      request<Client[]>("/clients"),
      request<Manager[]>("/projects/managers"),
      request<Project[]>("/projects"),
      request<Task[]>("/tasks"),
    ]);
    clients.value = loadedClients;
    managers.value = loadedManagers;
    projects.value = loadedProjects;
    tasks.value = loadedTasks;
    await loadInvoices();
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load invoices.";
  } finally {
    isLoading.value = false;
  }
}

onMounted(loadPage);

async function applyFilters() {
  errorMessage.value = "";
  if ((filterMinimum.value || filterMaximum.value) && !filterCurrency.value) {
    errorMessage.value = "Choose a currency to filter by amount.";
    return;
  }
  try {
    await loadInvoices();
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to filter invoices.";
  }
}

function resetFilters() {
  filterClientId.value = "";
  filterProjectId.value = "";
  filterManagerId.value = "";
  filterFrom.value = "";
  filterTo.value = "";
  filterCurrency.value = "";
  filterMinimum.value = "";
  filterMaximum.value = "";
  void applyFilters();
}

function setNewInvoiceDefaults() {
  editingId.value = null;
  savedInvoiceId.value = null;
  savedFormSignature.value = "";
  projectId.value = projects.value.find((project) => project.client_id !== null)?.id ?? null;
  invoiceDate.value = new Date().toISOString().slice(0, 10);
  dueDate.value = "";
  currency.value = "EUR";
  items.value = [];
  taskToAddId.value = null;
  errorMessage.value = "";
  isFormVisible.value = true;
}

function editInvoice(invoice: Invoice) {
  editingId.value = invoice.id;
  savedInvoiceId.value = invoice.id;
  projectId.value = invoice.project_id;
  invoiceDate.value = invoice.invoice_date;
  dueDate.value = invoice.due_date ?? "";
  currency.value = invoice.currency;
  items.value = invoice.items.map((item) => ({ ...item }));
  taskToAddId.value = null;
  savedFormSignature.value = formSignature.value;
  errorMessage.value = "";
  isFormVisible.value = true;
}

function closeForm() {
  if (!isSaving.value && !isDeleting.value && !isExporting.value) isFormVisible.value = false;
}

function addTaskLine() {
  const task = tasks.value.find((item) => item.id === taskToAddId.value);
  if (!task) return;
  items.value.push({ task_id: task.id, description: task.title, amount: "" });
  taskToAddId.value = availableTasks.value[0]?.id ?? null;
}

function addAdditionalCost() {
  items.value.push({ task_id: null, description: "", amount: "" });
}

function removeLine(index: number) {
  items.value.splice(index, 1);
}

function changeProject() {
  items.value = items.value.filter((item) => item.task_id === null);
  taskToAddId.value = null;
}

async function saveInvoice() {
  if (projectId.value === null || !invoiceDate.value || items.value.length === 0) {
    errorMessage.value = "Choose a project and invoice date, then add at least one line item.";
    return;
  }
  if (dueDate.value && dueDate.value < invoiceDate.value) {
    errorMessage.value = "The due date must be on or after the invoice date.";
    return;
  }
  if (items.value.some((item) => !item.description.trim() || parseMinor(item.amount) === null)) {
    errorMessage.value = "Complete each line description and enter amounts with up to two decimal places.";
    return;
  }
  if (totalMinor.value < 1n) {
    errorMessage.value = "The invoice total must be greater than zero.";
    return;
  }

  errorMessage.value = "";
  isSaving.value = true;
  const payload: InvoiceInput = {
    project_id: projectId.value,
    invoice_date: invoiceDate.value,
    due_date: dueDate.value || null,
    currency: currency.value,
    items: items.value.map((item) => ({ ...item })),
  };
  const submittedSignature = formSignature.value;
  try {
    const isNew = editingId.value === null;
    const invoice = await request<Invoice>(isNew ? "/invoices" : `/invoices/${editingId.value}`, {
      method: isNew ? "POST" : "PUT",
      body: JSON.stringify(payload),
    });
    editingId.value = invoice.id;
    savedInvoiceId.value = invoice.id;
    savedFormSignature.value = submittedSignature;
    const index = invoices.value.findIndex((item) => item.id === invoice.id);
    if (index === -1) invoices.value.unshift(invoice);
    else invoices.value[index] = invoice;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to save the invoice.";
  } finally {
    isSaving.value = false;
  }
}

async function exportPdf() {
  if (savedInvoiceId.value === null) return;
  errorMessage.value = "";
  isExporting.value = true;
  try {
    const token = localStorage.getItem("crm_token");
    const response = await fetch(`/api/invoices/${savedInvoiceId.value}/pdf`, {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    });
    if (!response.ok) {
      const payload = (await response.json().catch(() => null)) as { error?: string } | null;
      throw new Error(payload?.error ?? "Unable to export the invoice PDF.");
    }
    const blobUrl = URL.createObjectURL(await response.blob());
    const link = document.createElement("a");
    link.href = blobUrl;
    link.download = `${invoices.value.find((invoice) => invoice.id === savedInvoiceId.value)?.invoice_number ?? "invoice"}.pdf`;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to export the invoice PDF.";
  } finally {
    isExporting.value = false;
  }
}

async function deleteInvoice() {
  if (editingId.value === null) return;
  const invoice = invoices.value.find((item) => item.id === editingId.value);
  if (!invoice || !window.confirm(`Delete ${invoice.invoice_number}?`)) return;
  errorMessage.value = "";
  isDeleting.value = true;
  try {
    await request(`/invoices/${invoice.id}`, { method: "DELETE" });
    invoices.value = invoices.value.filter((item) => item.id !== invoice.id);
    isFormVisible.value = false;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to delete the invoice.";
  } finally {
    isDeleting.value = false;
  }
}
</script>

<template>
  <div class="page-content invoice-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">Invoices</p>
        <h1>Bill the work clearly.</h1>
      </div>
      <button v-if="!isFormVisible" class="create-button" type="button" @click="setNewInvoiceDefaults">
        + <span>Create invoice</span>
      </button>
    </div>

    <p v-if="errorMessage" class="error invoice-error" role="alert">{{ errorMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading invoices...</h2></div>

    <form v-if="!isLoading && !isFormVisible" class="invoice-filters" @submit.prevent="applyFilters">
      <div class="invoice-filter-grid">
        <div>
          <label for="invoice-filter-client">Client</label>
          <select id="invoice-filter-client" v-model="filterClientId">
            <option value="">All clients</option>
            <option v-for="client in clients" :key="client.id" :value="String(client.id)">{{ client.title }}</option>
          </select>
        </div>
        <div>
          <label for="invoice-filter-project">Project</label>
          <select id="invoice-filter-project" v-model="filterProjectId">
            <option value="">All projects</option>
            <option v-for="project in projects" :key="project.id" :value="String(project.id)">{{ project.title }}</option>
          </select>
        </div>
        <div>
          <label for="invoice-filter-manager">Project manager</label>
          <select id="invoice-filter-manager" v-model="filterManagerId">
            <option value="">All managers</option>
            <option v-for="manager in managers" :key="manager.id" :value="String(manager.id)">{{ manager.email }}</option>
          </select>
        </div>
        <div>
          <label for="invoice-filter-from">Invoice date from</label>
          <input id="invoice-filter-from" v-model="filterFrom" type="date" />
        </div>
        <div>
          <label for="invoice-filter-to">Invoice date to</label>
          <input id="invoice-filter-to" v-model="filterTo" type="date" />
        </div>
        <div>
          <label for="invoice-filter-currency">Currency</label>
          <select id="invoice-filter-currency" v-model="filterCurrency">
            <option value="">All currencies</option>
            <option v-for="option in currencies" :key="option" :value="option">{{ option }}</option>
          </select>
        </div>
        <div>
          <label for="invoice-filter-min">Minimum total</label>
          <input id="invoice-filter-min" v-model="filterMinimum" type="text" inputmode="decimal" autocomplete="off" />
        </div>
        <div>
          <label for="invoice-filter-max">Maximum total</label>
          <input id="invoice-filter-max" v-model="filterMaximum" type="text" inputmode="decimal" autocomplete="off" />
        </div>
      </div>
      <div class="invoice-filter-actions">
        <button class="edit-button" type="submit">Apply filters</button>
        <button class="edit-button" type="button" @click="resetFilters">Reset</button>
      </div>
    </form>

    <form v-if="!isLoading && isFormVisible" class="article-form invoice-form" @submit.prevent="saveInvoice">
      <div class="article-form-heading">
        <div>
          <p class="card-kicker">{{ editingId === null ? "New invoice" : "Edit invoice" }}</p>
          <h2>{{ editingId === null ? "Set the terms and line items." : "Review invoice details." }}</h2>
          <p v-if="invoices.find((invoice) => invoice.id === savedInvoiceId)?.invoice_number" class="invoice-number">
            {{ invoices.find((invoice) => invoice.id === savedInvoiceId)?.invoice_number }} · Draft
          </p>
        </div>
        <button class="close-button" type="button" aria-label="Close invoice form" @click="closeForm">&#10005;</button>
      </div>

      <label for="invoice-project">Project</label>
      <select id="invoice-project" v-model="projectId" required @change="changeProject">
        <option :value="null" disabled>Select a project</option>
        <option v-for="project in projects.filter((item) => item.client_id !== null)" :key="project.id" :value="project.id">
          {{ project.title }} · {{ project.client_title }}
        </option>
      </select>
      <p v-if="projectId !== null" class="invoice-project-manager">
        {{ projects.find((item) => item.id === projectId)?.manager_email ?? "No project manager assigned" }}
      </p>

      <div class="task-datetime-grid">
        <div>
          <label for="invoice-date">Invoice date</label>
          <input id="invoice-date" v-model="invoiceDate" type="date" required />
        </div>
        <div>
          <label for="invoice-due-date">Due date</label>
          <input id="invoice-due-date" v-model="dueDate" type="date" />
        </div>
      </div>

      <label for="invoice-currency">Currency</label>
      <select id="invoice-currency" v-model="currency">
        <option v-for="option in currencies" :key="option" :value="option">{{ option }}</option>
      </select>

      <section class="invoice-lines" aria-labelledby="invoice-task-lines-title">
        <div class="invoice-section-heading">
          <h3 id="invoice-task-lines-title">Project tasks</h3>
          <div class="invoice-add-task">
            <select v-model="taskToAddId" aria-label="Select task to add">
              <option :value="null">Select task</option>
              <option v-for="task in availableTasks" :key="task.id" :value="task.id">{{ task.title }}</option>
            </select>
            <button class="edit-button" type="button" :disabled="taskToAddId === null" @click="addTaskLine">Add task</button>
          </div>
        </div>
        <p v-if="items.every((item) => item.task_id === null)" class="muted invoice-hint">No project tasks added.</p>
        <div v-for="(item, index) in items.filter((line) => line.task_id !== null)" :key="item.task_id ?? index" class="invoice-line-row">
          <span>{{ item.description }}</span>
          <input v-model="item.amount" type="text" inputmode="decimal" autocomplete="off" :aria-label="`Amount for ${item.description}`" placeholder="0.00" />
          <button class="close-button" type="button" :aria-label="`Remove ${item.description}`" @click="removeLine(items.indexOf(item))">&#10005;</button>
        </div>
      </section>

      <section class="invoice-lines" aria-labelledby="invoice-additional-costs-title">
        <div class="invoice-section-heading">
          <h3 id="invoice-additional-costs-title">Additional costs</h3>
          <button class="edit-button" type="button" @click="addAdditionalCost">Add cost</button>
        </div>
        <div v-for="(item, index) in items.filter((line) => line.task_id === null)" :key="`cost-${index}`" class="invoice-line-row invoice-cost-row">
          <input v-model="item.description" type="text" maxlength="255" aria-label="Additional cost description" placeholder="Cost description" />
          <input v-model="item.amount" type="text" inputmode="decimal" autocomplete="off" :aria-label="`Amount for ${item.description || 'additional cost'}`" placeholder="0.00" />
          <button class="close-button" type="button" :aria-label="`Remove ${item.description || 'additional cost'}`" @click="removeLine(items.indexOf(item))">&#10005;</button>
        </div>
        <p v-if="items.every((item) => item.task_id !== null)" class="muted invoice-hint">No additional costs added.</p>
      </section>

      <div class="invoice-total"><span>Total</span><strong>{{ formattedTotal }} {{ currency }}</strong></div>

      <div class="task-form-actions invoice-form-actions">
        <button class="button article-submit" type="submit" :disabled="isSaving || isDeleting || isExporting">
          {{ isSaving ? "Saving..." : "Save invoice" }} <span aria-hidden="true">&#8594;</span>
        </button>
        <button v-if="savedInvoiceId !== null && savedFormSignature === formSignature" class="edit-button" type="button" :disabled="isExporting" @click="exportPdf">
          {{ isExporting ? "Preparing PDF..." : "Export PDF" }}
        </button>
        <button class="edit-button" type="button" :disabled="isSaving || isDeleting || isExporting" @click="closeForm">Cancel</button>
        <button v-if="editingId !== null" class="edit-button danger-button" type="button" :disabled="isSaving || isDeleting || isExporting" @click="deleteInvoice">
          {{ isDeleting ? "Deleting..." : "Delete" }}
        </button>
      </div>
    </form>

    <div v-if="!isLoading && !isFormVisible && invoices.length === 0" class="empty-page invoice-empty">
      <span class="empty-icon" aria-hidden="true">&#9633;</span>
      <h2>No invoices found.</h2>
      <p class="muted">Adjust the filters or create an invoice for a project.</p>
    </div>

    <div v-if="!isLoading && !isFormVisible && invoices.length > 0" class="article-list invoice-list">
      <article v-for="invoice in invoices" :key="invoice.id" class="article-item invoice-item">
        <div class="invoice-item-main">
          <p class="card-kicker">{{ invoice.invoice_number }} · {{ invoice.status }} · {{ invoice.invoice_date }}</p>
          <h2>{{ invoice.client_name }}</h2>
          <p class="muted">{{ invoice.project_name }} · {{ invoice.manager_name ?? "No manager" }}</p>
          <p v-if="invoice.due_date" class="invoice-due-date">Due {{ invoice.due_date }}</p>
        </div>
        <div class="invoice-item-actions">
          <strong class="invoice-item-total">{{ invoice.total }} {{ invoice.currency }}</strong>
          <button class="edit-button" type="button" @click="editInvoice(invoice)">Edit</button>
        </div>
      </article>
    </div>
  </div>
</template>