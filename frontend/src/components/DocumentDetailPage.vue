<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { apiRequest, apiUpload, downloadFile, errorText, previewFile } from "../lib/api";
import { detailHref } from "../lib/route";
import { personLabel, treeRows, type OrgUnit, type Person } from "../lib/org";
import {
  eventLabel,
  fieldLabel,
  formatBytes,
  linkKindLabels,
  parseTags,
  statusLabels,
  statusTone,
  type Confidentiality,
  type DocumentDetail,
  type DocumentSummary,
  type LinkKind,
} from "../lib/documents";

const props = defineProps<{ documentId: number }>();

type Named = { id: number; title: string };

const document = ref<DocumentDetail | null>(null);
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref("");
const notice = ref("");

const units = ref<OrgUnit[]>([]);
const people = ref<Person[]>([]);
const clients = ref<Named[]>([]);
const projects = ref<Named[]>([]);

const isEditing = ref(false);
const editTitle = ref("");
const editSummary = ref("");
const editOwnerUnitId = ref<number | null>(null);
const editConfidentiality = ref<Confidentiality>("general");
const editEffectiveOn = ref("");
const editExpiresOn = ref("");
const editReviewOn = ref("");
const editTags = ref("");
const editClientId = ref<number | null>(null);
const editProjectId = ref<number | null>(null);

const versionFile = ref<File | null>(null);
const versionComment = ref("");
const versionCheckIn = ref(true);
const versionInput = ref<HTMLInputElement | null>(null);
const attachmentInput = ref<HTMLInputElement | null>(null);

const linkQuery = ref("");
const linkResults = ref<DocumentSummary[]>([]);
const linkKind = ref<LinkKind>("refers_to");
const readerToAdd = ref<number | null>(null);

const permissions = computed(() => document.value?.permissions);
const unitOptions = computed(() => treeRows(units.value));
const readerCandidates = computed(() => people.value.filter((person) => person.id !== document.value?.author.id && !document.value?.readers.some((reader) => reader.id === person.id)));
const lockedByOther = computed(() => !!document.value?.checked_out_by && !permissions.value?.can_edit && document.value.permissions.can_manage);
const history = computed(() => [...(document.value?.events ?? [])].reverse());

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    document.value = await apiRequest<DocumentDetail>(`/dms/documents/${props.documentId}`);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load the document.");
  } finally {
    isLoading.value = false;
  }
}

/** Runs an action that returns the updated document (or nothing), then shows the result. */
async function act(action: () => Promise<DocumentDetail | void>, success: string, fallback: string) {
  isSaving.value = true;
  errorMessage.value = "";
  notice.value = "";
  try {
    const updated = await action();
    if (updated) document.value = updated;
    else await load();
    notice.value = success;
    return true;
  } catch (error) {
    errorMessage.value = errorText(error, fallback);
    return false;
  } finally {
    isSaving.value = false;
  }
}

const post = (path: string, body: unknown = {}) => apiRequest<DocumentDetail>(`/dms/documents/${props.documentId}${path}`, { method: "POST", body: JSON.stringify(body) });

async function openEdit() {
  const current = document.value;
  if (!current) return;
  editTitle.value = current.title;
  editSummary.value = current.summary ?? "";
  editOwnerUnitId.value = current.owner_unit?.id ?? null;
  editConfidentiality.value = current.confidentiality;
  editEffectiveOn.value = current.effective_on ?? "";
  editExpiresOn.value = current.expires_on ?? "";
  editReviewOn.value = current.review_on ?? "";
  editTags.value = current.tags.join(", ");
  editClientId.value = current.client?.id ?? null;
  editProjectId.value = current.project?.id ?? null;
  isEditing.value = true;
  try {
    if (units.value.length === 0) units.value = await apiRequest<OrgUnit[]>("/org/units");
    if (clients.value.length === 0) [clients.value, projects.value] = await Promise.all([apiRequest<Named[]>("/clients"), apiRequest<Named[]>("/projects")]);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load the choices for this form.");
  }
}

async function saveEdit() {
  const body: Record<string, unknown> = {
    title: editTitle.value.trim(),
    summary: editSummary.value.trim(),
    confidentiality: editConfidentiality.value,
    tags: parseTags(editTags.value),
  };
  const optional: [string, number | string | null][] = [
    ["owner_unit", editOwnerUnitId.value],
    ["effective_on", editEffectiveOn.value || null],
    ["expires_on", editExpiresOn.value || null],
    ["review_on", editReviewOn.value || null],
    ["client", editClientId.value],
    ["project", editProjectId.value],
  ];
  for (const [field, value] of optional) {
    const key = field === "owner_unit" || field === "client" || field === "project" ? `${field}_id` : field;
    if (value === null) body[`clear_${field}`] = true;
    else body[key] = value;
  }
  if (await act(() => apiRequest<DocumentDetail>(`/dms/documents/${props.documentId}`, { method: "PATCH", body: JSON.stringify(body) }), "Details saved.", "Unable to save the details.")) {
    isEditing.value = false;
  }
}

async function uploadVersion() {
  if (!versionFile.value) return;
  const form = new FormData();
  form.append("file", versionFile.value);
  if (versionComment.value.trim()) form.append("comment", versionComment.value.trim());
  if (versionCheckIn.value) form.append("check_in", "1");
  if (await act(() => apiUpload<DocumentDetail>(`/dms/documents/${props.documentId}/versions`, form), "New version uploaded.", "Unable to upload the version.")) {
    versionFile.value = null;
    versionComment.value = "";
    if (versionInput.value) versionInput.value.value = "";
  }
}

async function uploadAttachment(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  const form = new FormData();
  form.append("file", file);
  await act(() => apiUpload<DocumentDetail>(`/dms/documents/${props.documentId}/attachments`, form), "Attachment added.", "Unable to add the attachment.");
  if (attachmentInput.value) attachmentInput.value.value = "";
}

async function removeAttachment(id: number, name: string) {
  if (!window.confirm(`Remove the attachment "${name}"?`)) return;
  await act(() => apiRequest<void>(`/dms/documents/${props.documentId}/attachments/${id}`, { method: "DELETE" }), "Attachment removed.", "Unable to remove the attachment.");
}

async function checkOut(force = false) {
  if (force && !window.confirm(`Take over the check-out from ${document.value?.checked_out_by?.email}? Their unsaved work may be lost; this is recorded.`)) return;
  await act(() => post("/checkout", { force }), "Checked out: others cannot edit until you check in.", "Unable to check the document out.");
}

async function register() {
  if (!window.confirm("Register this document? It receives its number and can no longer be edited.")) return;
  await act(() => post("/register"), "Registered.", "Unable to register the document.");
}

async function archive() {
  if (!window.confirm("Archive this document? It stays readable but is no longer current.")) return;
  await act(() => post("/archive"), "Archived.", "Unable to archive the document.");
}

async function remove() {
  if (!document.value || !window.confirm(`Delete the draft "${document.value.title}" with all its files?`)) return;
  isSaving.value = true;
  try {
    await apiRequest(`/dms/documents/${props.documentId}`, { method: "DELETE" });
    window.location.hash = "";
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to delete the document.");
  } finally {
    isSaving.value = false;
  }
}

async function searchLinkTargets() {
  if (!linkQuery.value.trim()) return;
  try {
    const page = await apiRequest<{ items: DocumentSummary[] }>(`/dms/documents?q=${encodeURIComponent(linkQuery.value.trim())}&limit=10`);
    linkResults.value = page.items.filter((item) => item.id !== props.documentId);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to search documents.");
  }
}

async function addLink(targetId: number) {
  if (await act(() => post("/links", { target_id: targetId, kind: linkKind.value }), "Link added.", "Unable to add the link.")) {
    linkResults.value = [];
    linkQuery.value = "";
  }
}

async function removeLink(id: number) {
  await act(() => apiRequest<void>(`/dms/documents/${props.documentId}/links/${id}`, { method: "DELETE" }), "Link removed.", "Unable to remove the link.");
}

async function loadPeople() {
  if (people.value.length === 0) {
    try {
      people.value = await apiRequest<Person[]>("/org/people");
    } catch (error) {
      errorMessage.value = errorText(error, "Unable to load people.");
    }
  }
}

async function setReaders(ids: number[]) {
  await act(
    () => apiRequest<DocumentDetail>(`/dms/documents/${props.documentId}/readers`, { method: "PUT", body: JSON.stringify({ user_ids: ids }) }),
    "Readers updated.",
    "Unable to change the readers.",
  );
  readerToAdd.value = null;
}

function fileDownload(path: string, name: string) {
  downloadFile(`/dms/documents/${props.documentId}${path}`, name).catch((error: unknown) => {
    errorMessage.value = errorText(error, "Unable to download the file.");
  });
}

function filePreview(path: string) {
  previewFile(`/dms/documents/${props.documentId}${path}?inline=1`).catch((error: unknown) => {
    errorMessage.value = errorText(error, "Unable to open the file.");
  });
}

function formatWhen(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

function formatDay(value: string | null) {
  return value ? new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(new Date(`${value}T00:00:00`)) : "—";
}

function eventDetail(details: Record<string, string | number | boolean | null>): string {
  return Object.entries(details)
    .filter(([key]) => key !== "sha256")
    .map(([key, value]) => `${key.replace(/_/g, " ")}: ${String(value)}`)
    .join(" · ");
}

onMounted(async () => {
  await load();
  if (document.value?.permissions.can_manage) void loadPeople();
});
</script>

<template>
  <div class="page-content">
    <a class="detail-back" href="#">&larr; All documents</a>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="notice" class="settings-notice" role="status">{{ notice }}</p>
    <div v-if="isLoading && !document" class="empty-page"><h2>Loading the document...</h2></div>

    <template v-else-if="document">
      <div class="page-heading">
        <div>
          <p class="eyebrow">
            {{ document.type.name }}
            <span class="status-pill" :class="`dms-tone-${statusTone(document.status)}`">{{ statusLabels[document.status] }}</span>
            <span v-if="document.confidentiality === 'confidential'" class="org-kind">Confidential</span>
          </p>
          <h1>{{ document.title }}</h1>
          <p class="dms-number dms-number-large">{{ document.registration_number ?? "Not registered yet" }}</p>
        </div>
        <button class="edit-button" type="button" @click="fileDownload('/card.pdf', `${document.registration_number ?? `document-${document.id}`}-card.pdf`)">Registration card (PDF)</button>
      </div>

      <section class="detail-card wide dms-actions" aria-label="Document actions">
        <p v-if="document.checked_out_by" class="muted">
          Checked out by <strong>{{ document.checked_out_by.email }}</strong> since {{ formatWhen(document.checked_out_at ?? document.updated_at) }}.
        </p>
        <p v-if="document.missing_required_fields.length && document.permissions.can_manage" class="settings-warning">
          Required before registration: {{ document.missing_required_fields.map(fieldLabel).join(", ") }}.
        </p>
        <div class="org-actions">
          <button v-if="permissions?.can_check_out" class="edit-button" type="button" :disabled="isSaving" @click="checkOut()">Check out for editing</button>
          <button v-if="permissions?.can_check_in" class="edit-button" type="button" :disabled="isSaving" @click="act(() => post('/checkin'), 'Checked in.', 'Unable to check in.')">Check in</button>
          <button v-if="lockedByOther && permissions?.can_register" class="edit-button danger-button" type="button" :disabled="isSaving" @click="checkOut(true)">Take over check-out</button>
          <button v-if="permissions?.can_edit && !isEditing" class="edit-button" type="button" @click="openEdit">Edit details</button>
          <button v-if="permissions?.can_register" class="edit-button" type="button" :disabled="isSaving || document.missing_required_fields.length > 0 || !document.current_version" @click="register">Register</button>
          <button v-if="permissions?.can_archive" class="edit-button" type="button" :disabled="isSaving" @click="archive">Archive</button>
          <button v-if="permissions?.can_delete" class="edit-button danger-button" type="button" :disabled="isSaving" @click="remove">Delete draft</button>
        </div>
      </section>

      <div class="detail-grid">
        <section class="detail-card" aria-labelledby="doc-details-title">
          <h2 id="doc-details-title">Details</h2>
          <form v-if="isEditing" class="org-inline-form" @submit.prevent="saveEdit">
            <label for="edit-title">Title</label>
            <input id="edit-title" v-model="editTitle" type="text" maxlength="255" required />
            <label for="edit-summary">Summary</label>
            <textarea id="edit-summary" v-model="editSummary" rows="3" maxlength="5000"></textarea>
            <div class="inline-fields">
              <div>
                <label for="edit-owner">Owner unit</label>
                <select id="edit-owner" v-model.number="editOwnerUnitId">
                  <option :value="null">None</option>
                  <option v-for="row in unitOptions" :key="row.unit.id" :value="row.unit.id">{{ "  ".repeat(row.level) }}{{ row.unit.name }}</option>
                </select>
              </div>
              <div>
                <label for="edit-confidentiality">Confidentiality</label>
                <select id="edit-confidentiality" v-model="editConfidentiality">
                  <option value="general">General</option>
                  <option value="confidential">Confidential</option>
                </select>
              </div>
            </div>
            <div class="inline-fields">
              <div><label for="edit-effective">Effective from</label><input id="edit-effective" v-model="editEffectiveOn" type="date" /></div>
              <div><label for="edit-expires">Expires</label><input id="edit-expires" v-model="editExpiresOn" type="date" /></div>
              <div><label for="edit-review">Review on</label><input id="edit-review" v-model="editReviewOn" type="date" /></div>
            </div>
            <div class="inline-fields">
              <div>
                <label for="edit-client">Client</label>
                <select id="edit-client" v-model.number="editClientId">
                  <option :value="null">None</option>
                  <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.title }}</option>
                </select>
              </div>
              <div>
                <label for="edit-project">Project</label>
                <select id="edit-project" v-model.number="editProjectId">
                  <option :value="null">None</option>
                  <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.title }}</option>
                </select>
              </div>
            </div>
            <label for="edit-tags">Tags (comma-separated)</label>
            <input id="edit-tags" v-model="editTags" type="text" />
            <div class="org-actions">
              <button class="edit-button" type="submit" :disabled="isSaving">Save details</button>
              <button class="edit-button" type="button" @click="isEditing = false">Cancel</button>
            </div>
          </form>
          <dl v-else>
            <dt>Author</dt><dd>{{ document.author.email }}</dd>
            <dt>Owner unit</dt><dd>{{ document.owner_unit?.name ?? "—" }}</dd>
            <dt>Summary</dt><dd class="dms-prewrap">{{ document.summary ?? "—" }}</dd>
            <dt>Effective</dt><dd>{{ formatDay(document.effective_on) }}</dd>
            <dt>Expires</dt><dd>{{ formatDay(document.expires_on) }}</dd>
            <dt>Review</dt><dd>{{ formatDay(document.review_on) }}</dd>
            <dt>Registered</dt><dd>{{ document.registered_at ? `${formatWhen(document.registered_at)} by ${document.registered_by}` : "—" }}</dd>
            <dt>Client</dt><dd><a v-if="document.client" :href="detailHref('client', document.client.id)">{{ document.client.title }}</a><template v-else>—</template></dd>
            <dt>Project</dt><dd><a v-if="document.project" :href="detailHref('project', document.project.id)">{{ document.project.title }}</a><template v-else>—</template></dd>
            <dt>Tags</dt><dd>{{ document.tags.length ? document.tags.join(", ") : "—" }}</dd>
          </dl>
        </section>

        <section class="detail-card" aria-labelledby="doc-versions-title">
          <h2 id="doc-versions-title">Versions</h2>
          <form v-if="permissions?.can_edit" class="org-inline-form" @submit.prevent="uploadVersion">
            <label for="version-file">{{ document.current_version ? "Upload a new version" : "Upload the document file" }}</label>
            <input id="version-file" ref="versionInput" type="file" accept=".pdf,.docx,.xlsx,.odt,.txt,.csv,.jpg,.jpeg,.png" required @change="versionFile = ($event.target as HTMLInputElement).files?.[0] ?? null" />
            <label for="version-comment">What changed (optional)</label>
            <input id="version-comment" v-model="versionComment" type="text" maxlength="500" />
            <label v-if="document.checked_out_by" class="pm-all-projects-toggle"><input v-model="versionCheckIn" type="checkbox" /> Check in after upload</label>
            <button class="edit-button" type="submit" :disabled="isSaving || !versionFile">Upload version</button>
            <p v-if="document.status === 'approved'" class="muted org-hint">A new version returns the document to draft: the approval covered the previous file.</p>
          </form>
          <p v-if="document.versions.length === 0" class="muted">No file yet.</p>
          <ol v-else class="detail-list">
            <li v-for="version in document.versions" :key="version.number" class="dms-version">
              <div class="org-row">
                <span><strong>v{{ version.number }}</strong> {{ version.original_name }} <span class="muted">· {{ formatBytes(version.size_bytes) }}</span></span>
                <span class="org-actions">
                  <button v-if="version.previewable" class="edit-button" type="button" @click="filePreview(`/versions/${version.number}/file`)">Preview</button>
                  <button class="edit-button" type="button" @click="fileDownload(`/versions/${version.number}/file`, version.original_name)">Download</button>
                </span>
              </div>
              <span class="muted">{{ version.uploaded_by }} · {{ formatWhen(version.created_at) }}<template v-if="version.comment"> · {{ version.comment }}</template></span>
              <code class="dms-hash" :title="`SHA-256 ${version.sha256}`">SHA-256 {{ version.sha256.slice(0, 16) }}…</code>
            </li>
          </ol>
        </section>

        <section class="detail-card" aria-labelledby="doc-attachments-title">
          <h2 id="doc-attachments-title">Attachments</h2>
          <p v-if="document.attachments.length === 0" class="muted">None.</p>
          <ul v-else class="detail-list">
            <li v-for="attachment in document.attachments" :key="attachment.id" class="org-row">
              <span>{{ attachment.original_name }} <span class="muted">· {{ formatBytes(attachment.size_bytes) }} · {{ attachment.uploaded_by }}</span></span>
              <span class="org-actions">
                <button class="edit-button" type="button" @click="fileDownload(`/attachments/${attachment.id}/file`, attachment.original_name)">Download</button>
                <button v-if="permissions?.can_edit" class="edit-button danger-button" type="button" :disabled="isSaving" @click="removeAttachment(attachment.id, attachment.original_name)">Remove</button>
              </span>
            </li>
          </ul>
          <label v-if="permissions?.can_edit" class="dms-upload">
            <span>Add an attachment</span>
            <input ref="attachmentInput" type="file" accept=".pdf,.docx,.xlsx,.odt,.txt,.csv,.jpg,.jpeg,.png" :disabled="isSaving" @change="uploadAttachment" />
          </label>
        </section>

        <section class="detail-card" aria-labelledby="doc-links-title">
          <h2 id="doc-links-title">Related documents</h2>
          <p v-if="document.links.length === 0" class="muted">None.</p>
          <ul v-else class="detail-list">
            <li v-for="link in document.links" :key="link.id" class="org-row">
              <span>
                <span class="muted">{{ linkKindLabels[link.kind][link.direction] }}</span>
                <a :href="detailHref('document', link.document.id)">{{ link.document.registration_number ?? "Unregistered" }} · {{ link.document.title }}</a>
              </span>
              <button v-if="link.direction === 'outgoing' && permissions?.can_manage && document.status !== 'archived'" class="edit-button" type="button" :disabled="isSaving" @click="removeLink(link.id)">Remove</button>
            </li>
          </ul>
          <form v-if="permissions?.can_manage && document.status !== 'archived'" class="org-inline-form" @submit.prevent="searchLinkTargets">
            <div class="inline-fields">
              <div>
                <label for="link-kind">This document</label>
                <select id="link-kind" v-model="linkKind">
                  <option value="refers_to">refers to</option>
                  <option value="amends">amends</option>
                  <option value="replaces">replaces</option>
                </select>
              </div>
              <div><label for="link-query">Find document</label><input id="link-query" v-model="linkQuery" type="search" placeholder="Number or title" /></div>
            </div>
            <button class="edit-button" type="submit">Find</button>
            <ul v-if="linkResults.length" class="detail-list">
              <li v-for="result in linkResults" :key="result.id" class="org-row">
                <span>{{ result.registration_number ?? "Unregistered" }} · {{ result.title }}</span>
                <button class="edit-button" type="button" :disabled="isSaving" @click="addLink(result.id)">Link</button>
              </li>
            </ul>
          </form>
        </section>

        <section class="detail-card" aria-labelledby="doc-readers-title">
          <h2 id="doc-readers-title">Access</h2>
          <p class="muted">
            {{ document.confidentiality === "confidential"
              ? "Confidential: only the author, the readers below and registry clerks can open it."
              : "General: heads of the owner unit see the draft; everyone can read it once registered. Readers below can always open it." }}
          </p>
          <ul v-if="document.readers.length" class="detail-list">
            <li v-for="reader in document.readers" :key="reader.id" class="org-row">
              <span>{{ reader.email }}</span>
              <button v-if="permissions?.can_manage && document.status !== 'archived'" class="edit-button" type="button" :disabled="isSaving" @click="setReaders(document.readers.filter((item) => item.id !== reader.id).map((item) => item.id))">Remove</button>
            </li>
          </ul>
          <p v-else class="muted">No named readers.</p>
          <form v-if="permissions?.can_manage && document.status !== 'archived'" class="org-inline-form" @submit.prevent="readerToAdd !== null && setReaders([...document.readers.map((item) => item.id), readerToAdd])">
            <label for="reader-add">Add reader</label>
            <select id="reader-add" v-model.number="readerToAdd" required>
              <option :value="null" disabled>Choose a person</option>
              <option v-for="person in readerCandidates" :key="person.id" :value="person.id">{{ personLabel(person) }}</option>
            </select>
            <button class="edit-button" type="submit" :disabled="isSaving || readerToAdd === null">Add</button>
          </form>
        </section>

        <section class="detail-card" aria-labelledby="doc-history-title">
          <h2 id="doc-history-title">History</h2>
          <ol class="detail-list dms-history">
            <li v-for="event in history" :key="event.id">
              <strong>{{ eventLabel(event.action) }}</strong>
              <span class="muted"> · {{ event.actor }} · {{ formatWhen(event.created_at) }}</span>
              <span v-if="eventDetail(event.details)" class="muted dms-event-detail">{{ eventDetail(event.details) }}</span>
            </li>
          </ol>
        </section>
      </div>
    </template>
  </div>
</template>
