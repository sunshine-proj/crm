<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { getOrganizationDefaultCurrency } from "../lib/organizationSettings";
import { dealStages, groupDeals, isOpenStage, totalsByCurrency, type DealStage } from "../lib/deals";

type Deal = {
  id: number;
  client_id: number;
  client_title: string;
  title: string;
  stage: DealStage;
  probability: number;
  amount: string | null;
  currency: string | null;
  expected_close_date: string | null;
  owner_id: number | null;
  owner_email: string | null;
  lost_reason: string | null;
};
type Client = { id: number; title: string };
type Person = { id: number; email: string };
type StageChange = { from_stage: string | null; to_stage: string; actor: string; note: string | null; created_at: string };
type Summary = { stages: { stage: DealStage; count: number; unvalued_count: number; values: { currency: string; amount: string; weighted_amount: string }[] }[]; basis: string };

const deals = ref<Deal[]>([]);
const clients = ref<Client[]>([]);
const people = ref<Person[]>([]);
const summary = ref<Summary | null>(null);
const history = ref<{ dealId: number; changes: StageChange[] } | null>(null);
const isLoading = ref(true);
const isSaving = ref(false);
const isFormVisible = ref(false);
const errorMessage = ref("");
const search = ref("");
const showClosed = ref(true);

const clientId = ref<number | null>(null);
const title = ref("");
const amount = ref("");
const currency = ref(getOrganizationDefaultCurrency());
const closeDate = ref("");
const ownerId = ref<number | null>(null);

const visibleDeals = computed(() => {
  const term = search.value.trim().toLocaleLowerCase();
  return deals.value.filter((deal) => (showClosed.value || isOpenStage(deal.stage)) && (!term || `${deal.title} ${deal.client_title}`.toLocaleLowerCase().includes(term)));
});
const columns = computed(() => groupDeals(visibleDeals.value));
const shownStages = computed(() => dealStages.filter((stage) => showClosed.value || isOpenStage(stage.stage)));
const forecast = computed(() => (summary.value?.stages ?? []).filter((stage) => isOpenStage(stage.stage)));

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    [deals.value, clients.value, people.value, summary.value] = await Promise.all([
      apiRequest<Deal[]>("/deals"),
      apiRequest<Client[]>("/clients"),
      apiRequest<Person[]>("/projects/managers"),
      apiRequest<Summary>("/deals/summary"),
    ]);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load deals.");
  } finally {
    isLoading.value = false;
  }
}

async function refreshSummary() {
  summary.value = await apiRequest<Summary>("/deals/summary");
}

function openForm() {
  clientId.value = clients.value[0]?.id ?? null;
  title.value = "";
  amount.value = "";
  closeDate.value = "";
  ownerId.value = null;
  currency.value = getOrganizationDefaultCurrency();
  errorMessage.value = "";
  isFormVisible.value = true;
}

async function createDeal() {
  if (clientId.value === null || !title.value.trim()) return;
  isSaving.value = true;
  errorMessage.value = "";
  try {
    const body: Record<string, unknown> = { client_id: clientId.value, title: title.value.trim() };
    if (amount.value.trim()) Object.assign(body, { amount: amount.value.trim(), currency: currency.value });
    if (closeDate.value) body.expected_close_date = closeDate.value;
    if (ownerId.value !== null) body.owner_id = ownerId.value;
    deals.value.unshift(await apiRequest<Deal>("/deals", { method: "POST", body: JSON.stringify(body) }));
    await refreshSummary();
    isFormVisible.value = false;
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to create the deal.");
  } finally {
    isSaving.value = false;
  }
}

async function moveDeal(deal: Deal, stage: DealStage) {
  if (stage === deal.stage) return;
  let note: string | null = null;
  if (stage === "lost") {
    note = window.prompt("Why was this deal lost?");
    if (!note?.trim()) return;
  } else if (stage === "won" && !window.confirm(`Mark "${deal.title}" as won? Closed deals cannot be changed.`)) return;
  errorMessage.value = "";
  try {
    const saved = await apiRequest<Deal>(`/deals/${deal.id}/stage`, { method: "POST", body: JSON.stringify({ stage, note }) });
    Object.assign(deal, saved);
    await refreshSummary();
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to change the deal stage.");
  }
}

async function showHistory(deal: Deal) {
  if (history.value?.dealId === deal.id) {
    history.value = null;
    return;
  }
  try {
    history.value = { dealId: deal.id, changes: await apiRequest<StageChange[]>(`/deals/${deal.id}/history`) };
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load deal history.");
  }
}

function formatWhen(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

onMounted(load);
</script>

<template>
  <div class="page-content">
    <div class="page-heading">
      <div>
        <p class="eyebrow">Deals</p>
        <h1>The sales pipeline.</h1>
      </div>
      <button v-if="!isFormVisible" class="create-button" type="button" :disabled="clients.length === 0" @click="openForm">+ <span>Add deal</span></button>
    </div>

    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading the pipeline...</h2></div>

    <template v-else>
      <form v-if="isFormVisible" class="article-form" @submit.prevent="createDeal">
        <div class="article-form-heading">
          <div><p class="card-kicker">New deal</p><h2>Track an opportunity.</h2></div>
          <button class="close-button" type="button" aria-label="Close deal form" @click="isFormVisible = false">&#10005;</button>
        </div>
        <label for="deal-client">Client</label>
        <select id="deal-client" v-model.number="clientId" required>
          <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.title }}</option>
        </select>
        <label for="deal-title">Deal title</label>
        <input id="deal-title" v-model="title" type="text" maxlength="255" required placeholder="Website rebuild" />
        <label for="deal-amount">Expected value (optional)</label>
        <div class="inline-fields">
          <input id="deal-amount" v-model="amount" type="text" inputmode="decimal" placeholder="0.00" />
          <select v-model="currency" aria-label="Currency"><option>EUR</option><option>GBP</option><option>USD</option></select>
        </div>
        <label for="deal-close">Expected close date (optional)</label>
        <input id="deal-close" v-model="closeDate" type="date" />
        <label for="deal-owner">Owner (optional)</label>
        <select id="deal-owner" v-model.number="ownerId">
          <option :value="null">Unassigned</option>
          <option v-for="person in people" :key="person.id" :value="person.id">{{ person.email }}</option>
        </select>
        <button class="button article-submit" type="submit" :disabled="isSaving">{{ isSaving ? "Saving..." : "Create deal" }} <span aria-hidden="true">&#8594;</span></button>
      </form>

      <section v-if="forecast.length" class="pm-filters" aria-label="Open pipeline forecast">
        <div v-for="stage in forecast" :key="stage.stage">
          <strong>{{ dealStages.find((item) => item.stage === stage.stage)?.label }}</strong>
          <span class="muted"> {{ stage.count }} deals</span>
          <div v-for="value in stage.values" :key="value.currency" class="deal-column-total">
            {{ value.amount }} {{ value.currency }} · weighted {{ value.weighted_amount }}
          </div>
        </div>
      </section>
      <p v-if="summary" class="muted">{{ summary.basis }}</p>

      <div class="pm-filters">
        <div><label for="deal-search">Search deals</label><input id="deal-search" v-model="search" type="search" placeholder="Deal or client..." /></div>
        <label class="pm-all-projects-toggle"><input v-model="showClosed" type="checkbox" /> Show won and lost</label>
      </div>

      <div v-if="deals.length === 0" class="empty-page">
        <span class="empty-icon" aria-hidden="true">&#8644;</span>
        <h2>No deals yet.</h2>
        <p class="muted">Add the first opportunity to start the pipeline.</p>
      </div>

      <div v-else class="board-columns">
        <section v-for="stage in shownStages" :key="stage.stage" class="board-column" :aria-label="`${stage.label}, ${columns[stage.stage].length} deals`">
          <h3>{{ stage.label }} <span class="board-count">{{ columns[stage.stage].length }}</span></h3>
          <div v-for="total in totalsByCurrency(columns[stage.stage])" :key="total.currency" class="deal-column-total">{{ total.amount }} {{ total.currency }}</div>
          <article v-for="deal in columns[stage.stage]" :key="deal.id" class="deal-card">
            <strong>{{ deal.title }}</strong>
            <span class="muted">{{ deal.client_title }}</span>
            <span v-if="deal.amount">{{ deal.amount }} {{ deal.currency }}</span>
            <span v-if="deal.expected_close_date" class="muted">Close {{ deal.expected_close_date }}</span>
            <span v-if="deal.owner_email" class="muted">{{ deal.owner_email }}</span>
            <span v-if="deal.lost_reason" class="muted">Lost: {{ deal.lost_reason }}</span>
            <label v-if="isOpenStage(deal.stage)" class="board-move">
              <span class="visually-hidden">Move {{ deal.title }} to</span>
              <select :value="deal.stage" @change="moveDeal(deal, ($event.target as HTMLSelectElement).value as DealStage)">
                <option v-for="option in dealStages" :key="option.stage" :value="option.stage">{{ option.label }}</option>
              </select>
            </label>
            <button class="edit-button" type="button" @click="showHistory(deal)">{{ history?.dealId === deal.id ? "Hide history" : "History" }}</button>
            <ol v-if="history?.dealId === deal.id" class="detail-list">
              <li v-for="(change, index) in history.changes" :key="index">
                {{ change.from_stage ?? "created" }} &rarr; {{ change.to_stage }}
                <span class="muted"> · {{ formatWhen(change.created_at) }} · {{ change.actor }}<template v-if="change.note"> · {{ change.note }}</template></span>
              </li>
            </ol>
          </article>
        </section>
      </div>
    </template>
  </div>
</template>
