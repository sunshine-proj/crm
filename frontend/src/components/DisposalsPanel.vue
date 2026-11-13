<script setup lang="ts">
import { onMounted, ref } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { detailHref } from "../lib/route";
import { isAdminToken } from "../lib/session";
import type { Disposal, EligibleDocument } from "../lib/records";

const isAdmin = isAdminToken(localStorage.getItem("crm_token"));
const eligible = ref<EligibleDocument[]>([]);
const disposals = ref<Disposal[]>([]);
const status = ref<"proposed" | "approved" | "rejected" | "">("proposed");
const errorMessage = ref("");
const notice = ref("");
const isSaving = ref(false);
const proposingId = ref<number | null>(null);
const decidingId = ref<number | null>(null);
const text = ref("");

async function load() {
  errorMessage.value = "";
  try {
    [eligible.value, disposals.value] = await Promise.all([
      apiRequest<EligibleDocument[]>("/dms/disposals/eligible"),
      apiRequest<Disposal[]>(`/dms/disposals${status.value ? `?status=${status.value}` : ""}`),
    ]);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load retention data.");
  }
}

async function run(action: () => Promise<unknown>, success: string) {
  isSaving.value = true;
  errorMessage.value = notice.value = "";
  try {
    await action();
    notice.value = success;
    proposingId.value = decidingId.value = null;
    text.value = "";
    await load();
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to complete the action.");
  } finally {
    isSaving.value = false;
  }
}

function propose(document: EligibleDocument) {
  void run(() => apiRequest(`/dms/documents/${document.id}/disposal`, { method: "POST", body: JSON.stringify({ reason: text.value.trim() }) }), `Disposal of "${document.title}" proposed.`);
}

function decide(disposal: Disposal, decision: "approve" | "reject") {
  if (decision === "approve" && !window.confirm(`Destroy the files and content of "${disposal.document.title}" permanently? The registration entry and hashes stay as the record.`)) return;
  void run(() => apiRequest(`/dms/disposals/${disposal.id}/${decision}`, { method: "POST", body: JSON.stringify({ comment: text.value.trim() || null }) }), decision === "approve" ? "Disposal approved; the content is destroyed." : "Disposal rejected.");
}

function startDecision(disposal: Disposal) {
  decidingId.value = disposal.id;
  proposingId.value = null;
  text.value = "";
}

function formatDay(value: string | null) {
  return value ? new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(new Date(value.length === 10 ? `${value}T00:00:00` : value)) : "—";
}

onMounted(load);
</script>

<template>
  <section aria-labelledby="disposal-title">
    <h2 id="disposal-title" class="visually-hidden">Retention and disposal</h2>
    <p class="muted">Archived documents are kept for their type's retention period, counted from the archive date. Past it, a registry clerk proposes disposal and an administrator other than the proposer decides. Documents under legal hold are never offered.</p>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="notice" class="settings-notice" role="status">{{ notice }}</p>

    <h3 class="org-subheading">Due for disposal</h3>
    <p v-if="eligible.length === 0" class="muted">Nothing is past its retention period.</p>
    <ul v-else class="dms-list">
      <li v-for="document in eligible" :key="document.id" class="dms-row">
        <a class="dms-row-main" :href="detailHref('document', document.id)">
          <span class="dms-number">{{ document.registration_number }} · {{ document.type }}</span>
          <strong>{{ document.title }}</strong>
          <span class="muted">Archived {{ formatDay(document.archived_at) }} · kept {{ document.retention_years }} year(s), until {{ formatDay(document.retain_until) }}</span>
        </a>
        <span class="dms-row-meta">
          <button v-if="proposingId !== document.id" class="edit-button" type="button" @click="(proposingId = document.id), (decidingId = null), (text = '')">Propose disposal</button>
        </span>
        <form v-if="proposingId === document.id" class="approval-inline" @submit.prevent="propose(document)">
          <label :for="`propose-${document.id}`" class="visually-hidden">Reason for disposing of {{ document.title }}</label>
          <input :id="`propose-${document.id}`" v-model="text" type="text" maxlength="1000" required placeholder="Reason or disposal schedule" />
          <button class="edit-button danger-button" type="submit" :disabled="isSaving || !text.trim()">Propose</button>
          <button class="edit-button" type="button" @click="proposingId = null">Cancel</button>
        </form>
      </li>
    </ul>

    <div class="org-row">
      <h3 class="org-subheading">Disposals</h3>
      <label><span class="visually-hidden">Show</span>
        <select v-model="status" @change="load">
          <option value="proposed">Awaiting a decision</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
          <option value="">All</option>
        </select>
      </label>
    </div>
    <p v-if="disposals.length === 0" class="muted">None.</p>
    <ul v-else class="dms-list">
      <li v-for="disposal in disposals" :key="disposal.id" class="dms-row">
        <a class="dms-row-main" :href="detailHref('document', disposal.document.id)">
          <span class="dms-number">{{ disposal.document.registration_number }} · {{ disposal.document.type }}</span>
          <strong>{{ disposal.document.title }}</strong>
          <span class="muted">Proposed by {{ disposal.proposed_by }} on {{ formatDay(disposal.proposed_at) }}: {{ disposal.reason }}</span>
          <span v-if="disposal.decided_by" class="muted">{{ disposal.status === "approved" ? "Approved" : "Rejected" }} by {{ disposal.decided_by }} on {{ formatDay(disposal.decided_at) }}<template v-if="disposal.decision_comment">: {{ disposal.decision_comment }}</template></span>
          <span v-if="disposal.manifest.versions" class="muted">Destroyed: {{ disposal.manifest.versions.length }} version(s), {{ disposal.manifest.attachments?.length ?? 0 }} attachment(s)</span>
        </a>
        <span class="dms-row-meta">
          <span class="status-pill" :class="disposal.status === 'approved' ? 'dms-tone-closed' : disposal.status === 'rejected' ? 'dms-tone-attention' : 'dms-tone-progress'">{{ disposal.status }}</span>
          <button v-if="disposal.can_decide && decidingId !== disposal.id" class="edit-button" type="button" @click="startDecision(disposal)">Decide</button>
          <span v-else-if="isAdmin && disposal.status === 'proposed' && !disposal.can_decide" class="muted">Another administrator decides your proposal.</span>
        </span>
        <form v-if="decidingId === disposal.id" class="approval-inline" @submit.prevent>
          <label :for="`decide-${disposal.id}`" class="visually-hidden">Comment on the disposal of {{ disposal.document.title }}</label>
          <input :id="`decide-${disposal.id}`" v-model="text" type="text" maxlength="4000" placeholder="Comment (required to reject)" />
          <button class="edit-button danger-button" type="button" :disabled="isSaving" @click="decide(disposal, 'approve')">Approve and destroy</button>
          <button class="edit-button" type="button" :disabled="isSaving || !text.trim()" @click="decide(disposal, 'reject')">Reject</button>
          <button class="edit-button" type="button" @click="decidingId = null">Cancel</button>
        </form>
      </li>
    </ul>
  </section>
</template>
