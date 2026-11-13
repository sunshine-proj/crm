<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { apiRequest, apiUpload, errorText } from "../lib/api";
import { isDmsAdminToken } from "../lib/session";
import { detailHref } from "../lib/route";
import { treeRows, type OrgUnit } from "../lib/org";
import { documentQuery, emptyFilters, parseTags, statusLabels, statusTone, type Confidentiality, type DocumentDetail, type DocumentStatus, type DocumentSummary, type DocumentTypeInfo } from "../lib/documents";
import DocumentTypesPanel from "./DocumentTypesPanel.vue";
import ApprovalInbox from "./ApprovalInbox.vue";
import AcknowledgementInbox from "./AcknowledgementInbox.vue";
import AcknowledgementCampaigns from "./AcknowledgementCampaigns.vue";
import DisposalsPanel from "./DisposalsPanel.vue";
import DmsReportsPanel from "./DmsReportsPanel.vue";

type Page = { items: DocumentSummary[]; total: number; limit: number; offset: number };

const PAGE_SIZE = 25;
const isClerk = isDmsAdminToken(localStorage.getItem("crm_token"));

const view = ref<"registry" | "inbox" | "acknowledge" | "campaigns" | "types" | "retention" | "reports">("registry");
const ackCounts = ref<{ to_acknowledge: number; overdue: number }>({ to_acknowledge: 0, overdue: 0 });
const counts = ref<{ awaiting: number; overdue: number; consultations: number }>({ awaiting: 0, overdue: 0, consultations: 0 });
const types = ref<DocumentTypeInfo[]>([]);
const units = ref<OrgUnit[]>([]);
const page = ref<Page>({ items: [], total: 0, limit: PAGE_SIZE, offset: 0 });
const filters = ref(emptyFilters());
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref("");
const isFormOpen = ref(false);

const typeId = ref<number | null>(null);
const title = ref("");
const summary = ref("");
const ownerUnitId = ref<number | null>(null);
const confidentiality = ref<Confidentiality>("general");
const effectiveOn = ref("");
const expiresOn = ref("");
const tags = ref("");
const file = ref<File | null>(null);

const activeTypes = computed(() => types.value.filter((type) => type.active));
const unitOptions = computed(() => treeRows(units.value));
const selectedType = computed(() => types.value.find((type) => type.id === typeId.value) ?? null);
const lastShown = computed(() => Math.min(page.value.offset + page.value.items.length, page.value.total));

async function loadReferenceData() {
  [types.value, units.value] = await Promise.all([apiRequest<DocumentTypeInfo[]>("/dms/types"), apiRequest<OrgUnit[]>("/org/units")]);
}

async function search(offset = 0) {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    page.value = await apiRequest<Page>(`/dms/documents?${documentQuery(filters.value, PAGE_SIZE, offset)}`);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load documents.");
  } finally {
    isLoading.value = false;
  }
}

function resetFilters() {
  filters.value = emptyFilters();
  void search();
}

function openForm() {
  typeId.value = activeTypes.value[0]?.id ?? null;
  confidentiality.value = activeTypes.value[0]?.default_confidentiality ?? "general";
  title.value = summary.value = effectiveOn.value = expiresOn.value = tags.value = "";
  ownerUnitId.value = null;
  file.value = null;
  errorMessage.value = "";
  isFormOpen.value = true;
}

function onTypeChange() {
  confidentiality.value = selectedType.value?.default_confidentiality ?? "general";
}

function onFileChange(event: Event) {
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

async function createDocument() {
  if (typeId.value === null || !title.value.trim()) return;
  isSaving.value = true;
  errorMessage.value = "";
  try {
    const body: Record<string, unknown> = { type_id: typeId.value, title: title.value.trim(), confidentiality: confidentiality.value, tags: parseTags(tags.value) };
    if (summary.value.trim()) body.summary = summary.value.trim();
    if (ownerUnitId.value !== null) body.owner_unit_id = ownerUnitId.value;
    if (effectiveOn.value) body.effective_on = effectiveOn.value;
    if (expiresOn.value) body.expires_on = expiresOn.value;
    const created = await apiRequest<DocumentDetail>("/dms/documents", { method: "POST", body: JSON.stringify(body) });
    if (file.value) {
      const form = new FormData();
      form.append("file", file.value);
      form.append("comment", "Initial version");
      try {
        await apiUpload(`/dms/documents/${created.id}/versions`, form);
      } catch (error) {
        // The draft exists; send the author to it so they can retry the upload there.
        window.alert(`The document was created, but the file was not uploaded: ${errorText(error, "upload failed")}`);
      }
    }
    window.location.hash = detailHref("document", created.id);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to create the document.");
  } finally {
    isSaving.value = false;
  }
}

function formatWhen(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(new Date(value));
}

async function loadCounts() {
  try {
    [counts.value, ackCounts.value] = await Promise.all([
      apiRequest<typeof counts.value>("/dms/approvals/counts"),
      apiRequest<typeof ackCounts.value>("/dms/acknowledgements/counts"),
    ]);
  } catch {
    // The counter is a hint; the inbox tab loads the real list.
  }
}

onMounted(async () => {
  void loadCounts();
  try {
    await loadReferenceData();
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load document types.");
  }
  await search();
});
</script>

<template>
  <div class="page-content">
    <div class="page-heading">
      <div>
        <p class="eyebrow">Documents</p>
        <h1>The document registry.</h1>
      </div>
      <button v-if="view === 'registry' && !isFormOpen" class="create-button" type="button" :disabled="activeTypes.length === 0" @click="openForm">+ <span>New document</span></button>
    </div>

    <div class="view-tabs" role="tablist" aria-label="Documents views">
      <button type="button" role="tab" :aria-selected="view === 'registry'" @click="view = 'registry'">Registry</button>
      <button type="button" role="tab" :aria-selected="view === 'inbox'" @click="view = 'inbox'">
        Awaiting me <span v-if="counts.awaiting" class="notification-badge" :aria-label="`${counts.awaiting} awaiting, ${counts.overdue} overdue`">{{ counts.awaiting }}</span>
      </button>
      <button type="button" role="tab" :aria-selected="view === 'acknowledge'" @click="view = 'acknowledge'">
        To acknowledge <span v-if="ackCounts.to_acknowledge" class="notification-badge" :aria-label="`${ackCounts.to_acknowledge} to acknowledge, ${ackCounts.overdue} overdue`">{{ ackCounts.to_acknowledge }}</span>
      </button>
      <button type="button" role="tab" :aria-selected="view === 'campaigns'" @click="view = 'campaigns'">Acknowledgement status</button>
      <button v-if="isClerk" type="button" role="tab" :aria-selected="view === 'types'" @click="view = 'types'">Document types</button>
      <button v-if="isClerk" type="button" role="tab" :aria-selected="view === 'retention'" @click="view = 'retention'">Retention</button>
      <button v-if="isClerk" type="button" role="tab" :aria-selected="view === 'reports'" @click="view = 'reports'">Reports</button>
    </div>

    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>

    <DocumentTypesPanel v-if="view === 'types'" @changed="loadReferenceData" />
    <ApprovalInbox v-else-if="view === 'inbox'" @counted="(count) => (counts.awaiting = count)" />
    <AcknowledgementInbox v-else-if="view === 'acknowledge'" @counted="(count) => (ackCounts.to_acknowledge = count)" />
    <AcknowledgementCampaigns v-else-if="view === 'campaigns'" />
    <DisposalsPanel v-else-if="view === 'retention'" />
    <DmsReportsPanel v-else-if="view === 'reports'" />

    <template v-else>
      <form v-if="isFormOpen" class="article-form" @submit.prevent="createDocument">
        <div class="article-form-heading">
          <div><p class="card-kicker">New document</p><h2>Start a draft.</h2></div>
          <button class="close-button" type="button" aria-label="Close document form" @click="isFormOpen = false">&#10005;</button>
        </div>
        <div class="inline-fields">
          <div>
            <label for="doc-type">Type</label>
            <select id="doc-type" v-model.number="typeId" required @change="onTypeChange">
              <option v-for="type in activeTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
            </select>
          </div>
          <div>
            <label for="doc-confidentiality">Confidentiality</label>
            <select id="doc-confidentiality" v-model="confidentiality">
              <option value="general">General</option>
              <option value="confidential">Confidential (named readers only)</option>
            </select>
          </div>
        </div>
        <p v-if="selectedType" class="muted org-hint">
          Numbered like {{ selectedType.number_example }}. {{ selectedType.requires_approval ? "Needs approval before registration." : "Can be registered without approval." }}
        </p>
        <label for="doc-title">Title</label>
        <input id="doc-title" v-model="title" type="text" maxlength="255" required placeholder="Travel expense policy" />
        <label for="doc-summary">Summary (optional)</label>
        <textarea id="doc-summary" v-model="summary" rows="3" maxlength="5000"></textarea>
        <label for="doc-owner">Owner unit</label>
        <select id="doc-owner" v-model.number="ownerUnitId">
          <option :value="null">My unit</option>
          <option v-for="row in unitOptions" :key="row.unit.id" :value="row.unit.id">{{ "  ".repeat(row.level) }}{{ row.unit.name }}</option>
        </select>
        <div class="inline-fields">
          <div><label for="doc-effective">Effective from (optional)</label><input id="doc-effective" v-model="effectiveOn" type="date" /></div>
          <div><label for="doc-expires">Expires (optional)</label><input id="doc-expires" v-model="expiresOn" type="date" :min="effectiveOn || undefined" /></div>
        </div>
        <label for="doc-tags">Tags (comma-separated, optional)</label>
        <input id="doc-tags" v-model="tags" type="text" placeholder="travel, finance" />
        <label for="doc-file">File (optional, up to 10 MB)</label>
        <input id="doc-file" type="file" accept=".pdf,.docx,.xlsx,.odt,.txt,.csv,.jpg,.jpeg,.png" @change="onFileChange" />
        <button class="button article-submit" type="submit" :disabled="isSaving || typeId === null">{{ isSaving ? "Creating..." : "Create draft" }} <span aria-hidden="true">&#8594;</span></button>
      </form>

      <form class="pm-filters dms-filters" aria-label="Search documents" @submit.prevent="search()">
        <div class="dms-filter-wide"><label for="dms-q">Search</label><input id="dms-q" v-model="filters.q" type="search" placeholder="Number, title, text…" /></div>
        <div>
          <label for="dms-type">Type</label>
          <select id="dms-type" v-model.number="filters.typeId">
            <option :value="null">All types</option>
            <option v-for="type in types" :key="type.id" :value="type.id">{{ type.name }}</option>
          </select>
        </div>
        <div>
          <label for="dms-status">Status</label>
          <select id="dms-status" v-model="filters.status">
            <option value="">Any status</option>
            <option v-for="(label, status) in statusLabels" :key="status" :value="status">{{ label }}</option>
          </select>
        </div>
        <div>
          <label for="dms-unit">Owner unit</label>
          <select id="dms-unit" v-model.number="filters.ownerUnitId">
            <option :value="null">Any unit</option>
            <option v-for="row in unitOptions" :key="row.unit.id" :value="row.unit.id">{{ "  ".repeat(row.level) }}{{ row.unit.name }}</option>
          </select>
        </div>
        <div><label for="dms-tag">Tag</label><input id="dms-tag" v-model="filters.tag" type="text" /></div>
        <div><label for="dms-from">Created from</label><input id="dms-from" v-model="filters.from" type="date" /></div>
        <div><label for="dms-to">to</label><input id="dms-to" v-model="filters.to" type="date" /></div>
        <label class="pm-all-projects-toggle"><input v-model="filters.mine" type="checkbox" /> Only mine</label>
        <div class="org-actions">
          <button class="edit-button" type="submit">Search</button>
          <button class="edit-button" type="button" @click="resetFilters">Reset</button>
        </div>
      </form>

      <div v-if="isLoading" class="empty-page"><h2>Loading documents...</h2></div>
      <div v-else-if="page.items.length === 0" class="empty-page">
        <span class="empty-icon" aria-hidden="true">&#9636;</span>
        <h2>No documents found.</h2>
        <p class="muted">{{ page.total === 0 && !filters.q ? "Create the first draft to start the registry." : "Try other filters." }}</p>
      </div>
      <template v-else>
        <p class="muted" aria-live="polite">Showing {{ page.offset + 1 }}–{{ lastShown }} of {{ page.total }}</p>
        <ul class="dms-list">
          <li v-for="document in page.items" :key="document.id" class="dms-row">
            <a class="dms-row-main" :href="detailHref('document', document.id)">
              <span class="dms-number">{{ document.registration_number ?? "Unregistered" }}</span>
              <strong>{{ document.title }}</strong>
              <span class="muted">{{ document.type.name }}<template v-if="document.owner_unit"> · {{ document.owner_unit.name }}</template> · {{ document.author.email }} · updated {{ formatWhen(document.updated_at) }}</span>
            </a>
            <span class="dms-row-meta">
              <span class="status-pill" :class="`dms-tone-${statusTone(document.status)}`">{{ statusLabels[document.status as DocumentStatus] }}</span>
              <span v-if="document.confidentiality === 'confidential'" class="org-kind">Confidential</span>
              <span v-if="document.checked_out_by" class="org-kind" :title="`Checked out by ${document.checked_out_by.email}`">Checked out</span>
              <span class="muted">{{ document.current_version ? `v${document.current_version.number}` : "no file" }}</span>
            </span>
          </li>
        </ul>
        <div class="org-actions">
          <button class="edit-button" type="button" :disabled="page.offset === 0" @click="search(Math.max(0, page.offset - PAGE_SIZE))">&larr; Previous</button>
          <button class="edit-button" type="button" :disabled="lastShown >= page.total" @click="search(page.offset + PAGE_SIZE)">Next &rarr;</button>
        </div>
      </template>
    </template>
  </div>
</template>
