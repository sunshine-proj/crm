<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { getOrganizationDefaultCurrency } from "../lib/organizationSettings";
import EntityNotesPanel from "./EntityNotesPanel.vue";
import { isValidDateRange } from "../lib/planning";
import { detailHref } from "../lib/route";

type Project = {
  id: number;
  title: string;
  description: string;
  client_id: number | null;
  client_title: string | null;
  manager_id: number | null;
  manager_email: string | null;
  status: ProjectStatus;
  start_date: string | null;
  end_date: string | null;
  budget_amount: string | null;
  budget_currency: string | null;
  hourly_rate: string | null;
  billing_currency: string | null;
  created_at: string;
  updated_at: string;
};

type Client = { id: number; title: string };
type Manager = { id: number; email: string };
type ProjectMilestone = { id: number; project_id: number; title: string; description: string; due_date: string | null; amount: string | null; currency: string | null; status: string; invoice_id: number | null };
type ProjectStatus = "planned" | "active" | "on_hold" | "completed" | "cancelled";
type ProjectInput = Pick<Project, "title" | "description" | "client_id" | "manager_id" | "status" | "start_date" | "end_date" | "budget_amount" | "budget_currency" | "hourly_rate" | "billing_currency">;

const projectStatuses: { value: ProjectStatus; label: string }[] = [
  { value: "planned", label: "Planned" },
  { value: "active", label: "Active" },
  { value: "on_hold", label: "On hold" },
  { value: "completed", label: "Completed" },
  { value: "cancelled", label: "Cancelled" },
];

const isFormVisible = ref(false);
const projects = ref<Project[]>([]);
const clients = ref<Client[]>([]);
const managers = ref<Manager[]>([]);
const editingId = ref<number | null>(null);
const title = ref("");
const description = ref("");
const clientId = ref<number | null>(null);
const managerId = ref<number | null>(null);
const status = ref<ProjectStatus>("planned");
const startDate = ref("");
const endDate = ref("");
const budgetAmount = ref("");
const budgetCurrency = ref(getOrganizationDefaultCurrency());
const hourlyRate = ref("");
const billingCurrency = ref(getOrganizationDefaultCurrency());
const errorMessage = ref("");
const isLoading = ref(true);
const isSaving = ref(false);
const isDeleting = ref(false);
const expandedProjectId = ref<number | null>(null);
const milestones = ref<ProjectMilestone[]>([]);
const milestoneTitle = ref("");
const milestoneDescription = ref("");
const milestoneDueDate = ref("");
const milestoneAmount = ref("");
const milestoneCurrency = ref(getOrganizationDefaultCurrency());
const searchQuery = ref("");
const statusFilter = ref("");
const visibleProjects = computed(() => projects.value.filter((project) => {
  const query = searchQuery.value.trim().toLocaleLowerCase();
  const matchesQuery = query === "" || `${project.title} ${project.description} ${project.client_title ?? ""} ${project.manager_email ?? ""}`.toLocaleLowerCase().includes(query);
  return matchesQuery && (!statusFilter.value || project.status === statusFilter.value);
}));

function formatProjectDate(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
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
    throw new Error(payload?.error ?? "Unable to complete the project request.");
  }

  return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}

async function loadProjects() {
  errorMessage.value = "";
  isLoading.value = true;

  try {
    const [loadedProjects, loadedClients, loadedManagers] = await Promise.all([
      request<Project[]>("/projects"),
      request<Client[]>("/clients"),
      request<Manager[]>("/projects/managers"),
    ]);
    projects.value = loadedProjects;
    clients.value = loadedClients;
    managers.value = loadedManagers;
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
  clientId.value = clients.value[0]?.id ?? null;
  managerId.value = null;
  status.value = "planned";
  startDate.value = "";
  endDate.value = "";
  budgetAmount.value = "";
  budgetCurrency.value = getOrganizationDefaultCurrency();
  hourlyRate.value = "";
  billingCurrency.value = getOrganizationDefaultCurrency();
  errorMessage.value = "";
  isFormVisible.value = true;
}

function editProject(project: Project) {
  editingId.value = project.id;
  title.value = project.title;
  description.value = project.description;
  clientId.value = project.client_id;
  managerId.value = project.manager_id;
  status.value = project.status;
  startDate.value = project.start_date ?? "";
  endDate.value = project.end_date ?? "";
  budgetAmount.value = project.budget_amount ?? "";
  budgetCurrency.value = project.budget_currency ?? "EUR";
  hourlyRate.value = project.hourly_rate ?? "";
  billingCurrency.value = project.billing_currency ?? "EUR";
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
    client_id: clientId.value,
    manager_id: managerId.value,
    status: status.value,
    start_date: startDate.value || null,
    end_date: endDate.value || null,
    budget_amount: budgetAmount.value.trim() || null,
    budget_currency: budgetAmount.value.trim() ? budgetCurrency.value : null,
    hourly_rate: hourlyRate.value.trim() || null,
    billing_currency: hourlyRate.value.trim() ? billingCurrency.value : null,
  };

  if (!project.title || !project.description || project.client_id === null) {
    errorMessage.value = "Complete the title, description, and client before saving.";
    return;
  }
  if (!isValidDateRange(project.start_date, project.end_date)) {
    errorMessage.value = "The end date must be on or after the start date.";
    return;
  }
  if (project.budget_amount !== null && !/^(0|[1-9]\d*)(?:\.\d{1,2})?$/.test(project.budget_amount)) {
    errorMessage.value = "Project budget must be a non-negative amount with at most two decimal places.";
    return;
  }
  if (project.hourly_rate !== null && !/^(0|[1-9]\d*)(?:\.\d{1,2})?$/.test(project.hourly_rate)) {
    errorMessage.value = "Project hourly rate must be a non-negative amount with at most two decimal places.";
    return;
  }

  errorMessage.value = "";
  isSaving.value = true;

  try {
    const savedProject = await request<Project>(editingId.value === null ? "/projects" : `/projects/${editingId.value}`, {
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
    await request(`/projects/${project.id}`, { method: "DELETE" });
    projects.value = projects.value.filter((item) => item.id !== project.id);
    isFormVisible.value = false;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to delete the project.";
  } finally {
    isDeleting.value = false;
  }
}

  async function toggleProjectDetails(project: Project) {
    if (expandedProjectId.value === project.id) {
      expandedProjectId.value = null;
      return;
    }
    errorMessage.value = "";
    try {
      milestones.value = await request<ProjectMilestone[]>(`/projects/${project.id}/milestones`);
      expandedProjectId.value = project.id;
    } catch (error) {
      errorMessage.value = error instanceof Error ? error.message : "Unable to load project details.";
    }
  }

  async function createMilestone(project: Project) {
    if (!milestoneTitle.value.trim() || (milestoneAmount.value && !/^(0|[1-9]\d*)(?:\.\d{1,2})?$/.test(milestoneAmount.value))) {
      errorMessage.value = "Enter a milestone title and a valid optional amount.";
      return;
    }
    isSaving.value = true;
    errorMessage.value = "";
    try {
      const milestone = await request<ProjectMilestone>(`/projects/${project.id}/milestones`, {
        method: "POST",
        body: JSON.stringify({
          title: milestoneTitle.value.trim(),
          description: milestoneDescription.value.trim(),
          due_date: milestoneDueDate.value || null,
          amount: milestoneAmount.value.trim() || null,
          currency: milestoneAmount.value.trim() ? milestoneCurrency.value : null,
        }),
      });
      milestones.value.push(milestone);
      milestoneTitle.value = "";
      milestoneDescription.value = "";
      milestoneDueDate.value = "";
      milestoneAmount.value = "";
    } catch (error) {
      errorMessage.value = error instanceof Error ? error.message : "Unable to create milestone.";
    } finally {
      isSaving.value = false;
    }
  }

  async function completeMilestone(milestone: ProjectMilestone) {
    try {
      const updated = await request<ProjectMilestone>(`/projects/milestones/${milestone.id}/complete`, { method: "POST" });
      milestones.value = milestones.value.map((item) => item.id === updated.id ? updated : item);
    } catch (error) {
      errorMessage.value = error instanceof Error ? error.message : "Unable to complete milestone.";
    }
  }

  async function invoiceMilestone(milestone: ProjectMilestone) {
    try {
      const invoice = await request<{ id: number }>(`/invoices/from-milestone/${milestone.id}`, {
        method: "POST",
        body: JSON.stringify({ invoice_date: new Date().toISOString().slice(0, 10) }),
      });
      milestones.value = milestones.value.map((item) => item.id === milestone.id ? { ...item, status: "invoiced", invoice_id: invoice.id } : item);
    } catch (error) {
      errorMessage.value = error instanceof Error ? error.message : "Unable to create milestone invoice.";
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

      <label for="project-client">Client</label>
      <select id="project-client" v-model="clientId" required>
        <option :value="null" disabled>Select a client</option>
        <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.title }}</option>
      </select>

      <label for="project-manager">Responsible project manager</label>
      <select id="project-manager" v-model="managerId">
        <option :value="null">Unassigned</option>
        <option v-for="manager in managers" :key="manager.id" :value="manager.id">{{ manager.email }}</option>
      </select>

      <label for="project-status">Status</label>
      <select id="project-status" v-model="status">
        <option v-for="option in projectStatuses" :key="option.value" :value="option.value">{{ option.label }}</option>
      </select>

      <div class="task-datetime-grid">
        <div>
          <label for="project-start">Planned start</label>
          <input id="project-start" v-model="startDate" type="date" />
        </div>
        <div>
          <label for="project-end">Planned end</label>
          <input id="project-end" v-model="endDate" type="date" />
        </div>
      </div>

      <div class="task-datetime-grid">
        <div>
          <label for="project-hourly-rate">Billable hourly rate</label>
          <input id="project-hourly-rate" v-model="hourlyRate" inputmode="decimal" placeholder="Optional" />
        </div>
        <div>
          <label for="project-billing-currency">Billing currency</label>
          <select id="project-billing-currency" v-model="billingCurrency" :disabled="!hourlyRate">
            <option value="EUR">EUR</option>
            <option value="GBP">GBP</option>
            <option value="USD">USD</option>
          </select>
        </div>
      </div>

      <div class="task-datetime-grid">
        <div>
          <label for="project-budget">Approved budget</label>
          <input id="project-budget" v-model="budgetAmount" inputmode="decimal" placeholder="Optional" />
        </div>
        <div>
          <label for="project-budget-currency">Budget currency</label>
          <select id="project-budget-currency" v-model="budgetCurrency" :disabled="!budgetAmount">
            <option value="EUR">EUR</option>
            <option value="GBP">GBP</option>
            <option value="USD">USD</option>
          </select>
        </div>
      </div>

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
      <div class="task-filter-row"><label for="project-search">Search projects</label><input id="project-search" v-model="searchQuery" type="search" placeholder="Title, client, manager..." /><label for="project-status-filter">Status</label><select id="project-status-filter" v-model="statusFilter"><option value="">All statuses</option><option v-for="option in projectStatuses" :key="option.value" :value="option.value">{{ option.label }}</option></select></div>
      <article v-for="project in visibleProjects" :key="project.id" class="article-item project-item">
        <div>
          <p class="card-kicker">{{ project.client_title ?? "Unlinked legacy project" }} · {{ project.status.replace("_", " ") }} · {{ project.manager_email ?? "No manager" }}</p>
          <h2><a :href="detailHref('project', project.id)">{{ project.title }}</a></h2>
          <p class="muted">{{ project.description }}</p>
          <p v-if="project.budget_amount !== null" class="card-kicker">Budget {{ project.budget_amount }} {{ project.budget_currency }}</p>
          <p v-if="project.hourly_rate !== null" class="card-kicker">Billable {{ project.hourly_rate }} / hour {{ project.billing_currency }}</p>
          <p v-if="project.start_date || project.end_date" class="card-kicker">
            {{ project.start_date || "Start not set" }} to {{ project.end_date || "End not set" }}
          </p>
          <EntityNotesPanel :entity-type="'project'" :entity-id="project.id" />
          <button class="edit-button" type="button" @click="toggleProjectDetails(project)">{{ expandedProjectId === project.id ? "Hide delivery details" : "Delivery details" }}</button>
          <section v-if="expandedProjectId === project.id" class="project-milestones">
            <h3>Milestones and billing</h3>
            <form class="finance-inline-form" @submit.prevent="createMilestone(project)">
              <div><label :for="`milestone-title-${project.id}`">Milestone</label><input :id="`milestone-title-${project.id}`" v-model="milestoneTitle" maxlength="255" required /></div>
              <div><label :for="`milestone-description-${project.id}`">Description</label><input :id="`milestone-description-${project.id}`" v-model="milestoneDescription" maxlength="5000" /></div>
              <div><label :for="`milestone-due-${project.id}`">Due date</label><input :id="`milestone-due-${project.id}`" v-model="milestoneDueDate" type="date" /></div>
              <div><label :for="`milestone-amount-${project.id}`">Invoice amount</label><input :id="`milestone-amount-${project.id}`" v-model="milestoneAmount" inputmode="decimal" /></div>
              <div><label :for="`milestone-currency-${project.id}`">Currency</label><select :id="`milestone-currency-${project.id}`" v-model="milestoneCurrency"><option>EUR</option><option>GBP</option><option>USD</option></select></div>
              <button class="edit-button" type="submit" :disabled="isSaving">Add milestone</button>
            </form>
            <ul v-if="milestones.length" class="milestone-list"><li v-for="milestone in milestones" :key="milestone.id"><div><strong>{{ milestone.title }}</strong><span>{{ milestone.due_date ?? "No due date" }} · {{ milestone.status }}<span v-if="milestone.amount"> · {{ milestone.amount }} {{ milestone.currency }}</span></span><p v-if="milestone.description">{{ milestone.description }}</p></div><div><button v-if="milestone.status === 'planned'" class="edit-button" type="button" @click="completeMilestone(milestone)">Complete</button><button v-if="milestone.status === 'completed' && milestone.amount !== null" class="edit-button" type="button" @click="invoiceMilestone(milestone)">Create draft invoice</button><span v-if="milestone.invoice_id">Invoice #{{ milestone.invoice_id }}</span></div></li></ul>
            <p v-else class="muted">No milestones yet.</p>
          </section>
        </div>
        <button class="edit-button" type="button" @click="editProject(project)">Edit</button>
      </article>
      <p v-if="visibleProjects.length === 0" class="muted">No projects match your filters.</p>
    </div>
  </div>
</template>