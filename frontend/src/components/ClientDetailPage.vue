<script setup lang="ts">
import { onMounted, ref, watch } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { detailHref } from "../lib/route";
import ClientActivityPanel from "./ClientActivityPanel.vue";
import ClientContactsPanel from "./ClientContactsPanel.vue";
import EntityNotesPanel from "./EntityNotesPanel.vue";
import RecordChangeHistory from "./RecordChangeHistory.vue";

const props = defineProps<{ clientId: number }>();

type Client = {
  id: number; title: string; notes: string; legal_name: string | null; registration_number: string | null; vat_number: string | null;
  country_code: string | null; billing_address: string | null; billing_email: string | null; website: string | null; industry: string | null;
  account_status: string; financial_details_restricted: boolean;
};
type Project = { id: number; title: string; status: string; start_date: string | null; end_date: string | null; manager_email: string | null; client_id: number | null };
type Deal = { id: number; title: string; stage: string; amount: string | null; currency: string | null };
type Invoice = { id: number; invoice_number: string | null; invoice_date: string; due_date: string | null; currency: string; total: string; balance_due: string; status: string; project_name: string | null };

const client = ref<Client | null>(null);
const projects = ref<Project[]>([]);
const deals = ref<Deal[]>([]);
const invoices = ref<Invoice[]>([]);
const isLoading = ref(true);
const errorMessage = ref("");

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    client.value = await apiRequest<Client>(`/clients/${props.clientId}`);
    const [allProjects, clientDeals, clientInvoices] = await Promise.all([
      apiRequest<Project[]>("/projects"),
      apiRequest<Deal[]>(`/deals?client_id=${props.clientId}`),
      apiRequest<Invoice[]>(`/invoices?client_id=${props.clientId}`),
    ]);
    projects.value = allProjects.filter((project) => project.client_id === props.clientId);
    deals.value = clientDeals;
    invoices.value = clientInvoices;
  } catch (error) {
    client.value = null;
    errorMessage.value = errorText(error, "Unable to load the client.");
  } finally {
    isLoading.value = false;
  }
}

onMounted(load);
watch(() => props.clientId, load);
</script>

<template>
  <div class="page-content">
    <a class="detail-back" href="#">&larr; All clients</a>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading client...</h2></div>

    <template v-else-if="client">
      <div class="page-heading">
        <div>
          <p class="eyebrow">Client <span class="status-pill">{{ client.account_status }}</span></p>
          <h1>{{ client.title }}</h1>
        </div>
      </div>

      <div class="detail-grid">
        <section class="detail-card">
          <h2>Account</h2>
          <dl>
            <dt>Legal name</dt><dd>{{ client.legal_name ?? "—" }}</dd>
            <dt>Country</dt><dd>{{ client.country_code ?? "—" }}</dd>
            <dt>Industry</dt><dd>{{ client.industry ?? "—" }}</dd>
            <dt>Website</dt><dd><a v-if="client.website" :href="client.website" target="_blank" rel="noopener noreferrer">{{ client.website }}</a><template v-else>—</template></dd>
            <template v-if="!client.financial_details_restricted">
              <dt>Registration no.</dt><dd>{{ client.registration_number ?? "—" }}</dd>
              <dt>VAT no.</dt><dd>{{ client.vat_number ?? "—" }}</dd>
              <dt>Billing e-mail</dt><dd>{{ client.billing_email ?? "—" }}</dd>
              <dt>Billing address</dt><dd>{{ client.billing_address ?? "—" }}</dd>
            </template>
          </dl>
          <p v-if="client.financial_details_restricted" class="muted">Tax and billing details are visible to finance users and administrators.</p>
          <p class="muted">{{ client.notes }}</p>
        </section>

        <section class="detail-card">
          <h2>Projects <span class="muted">({{ projects.length }})</span></h2>
          <p v-if="projects.length === 0" class="muted">No projects linked to this client.</p>
          <ul v-else class="detail-list">
            <li v-for="project in projects" :key="project.id">
              <a :href="detailHref('project', project.id)">{{ project.title }}</a>
              <span class="status-pill">{{ project.status.replace("_", " ") }}</span>
              <span class="muted"> {{ project.start_date ?? "?" }} &rarr; {{ project.end_date ?? "?" }}<template v-if="project.manager_email"> · {{ project.manager_email }}</template></span>
            </li>
          </ul>
        </section>

        <section class="detail-card">
          <h2>Deals <span class="muted">({{ deals.length }})</span></h2>
          <p v-if="deals.length === 0" class="muted">No deals for this client.</p>
          <ul v-else class="detail-list">
            <li v-for="deal in deals" :key="deal.id">{{ deal.title }} <span class="status-pill">{{ deal.stage }}</span><span v-if="deal.amount" class="muted"> {{ deal.amount }} {{ deal.currency }}</span></li>
          </ul>
        </section>

        <section class="detail-card">
          <h2>Invoices <span class="muted">({{ invoices.length }})</span></h2>
          <p v-if="invoices.length === 0" class="muted">No invoices for this client.</p>
          <ul v-else class="detail-list">
            <li v-for="invoice in invoices" :key="invoice.id">
              {{ invoice.invoice_number ?? `Draft #${invoice.id}` }} <span class="status-pill">{{ invoice.status.replace("_", " ") }}</span>
              <span class="muted"> {{ invoice.total }} {{ invoice.currency }} · due balance {{ invoice.balance_due }}<template v-if="invoice.project_name"> · {{ invoice.project_name }}</template></span>
            </li>
          </ul>
        </section>

        <div class="detail-card wide"><ClientContactsPanel :client-id="client.id" /></div>
        <div class="detail-card wide"><ClientActivityPanel :client-id="client.id" :client-title="client.title" /></div>
        <div class="detail-card wide"><EntityNotesPanel entity-type="client" :entity-id="client.id" /></div>
        <RecordChangeHistory class="wide" record-type="client" :record-id="client.id" />
      </div>
    </template>
  </div>
</template>
