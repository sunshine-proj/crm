<script setup lang="ts">
import { computed, ref } from "vue";
import { apiRequest, errorText } from "../lib/api";
import type { DocumentDetail } from "../lib/documents";

const props = defineProps<{ document: DocumentDetail }>();
const emit = defineEmits<{ updated: [document: DocumentDetail]; changed: [] }>();

const form = ref<"" | "hold" | "release" | "review" | "disposal">("");
const text = ref("");
const nextReview = ref("");
const isSaving = ref(false);
const errorMessage = ref("");
const notice = ref("");

const tomorrow = (() => {
  const day = new Date();
  day.setDate(day.getDate() + 1);
  return day.toISOString().slice(0, 10);
})();
const permissions = computed(() => props.document.permissions);
const openDisposal = computed(() => props.document.disposals.find((disposal) => disposal.status === "proposed") ?? null);
const isVisible = computed(
  () =>
    props.document.legal_hold !== null ||
    props.document.retain_until !== null ||
    props.document.disposed_at !== null ||
    props.document.review_on !== null ||
    props.document.disposals.length > 0 ||
    permissions.value.can_place_legal_hold ||
    permissions.value.can_record_review,
);

function open(name: typeof form.value) {
  form.value = form.value === name ? "" : name;
  text.value = "";
  nextReview.value = "";
  errorMessage.value = "";
}

async function submit() {
  isSaving.value = true;
  errorMessage.value = notice.value = "";
  const base = `/dms/documents/${props.document.id}`;
  try {
    if (form.value === "disposal") {
      await apiRequest(`${base}/disposal`, { method: "POST", body: JSON.stringify({ reason: text.value.trim() }) });
      notice.value = "Disposal proposed. An administrator other than you decides it.";
      emit("changed");
    } else {
      const request =
        form.value === "hold"
          ? { path: "/legal-hold", method: "POST", body: { reason: text.value.trim() }, done: "The document is under legal hold." }
          : form.value === "release"
            ? { path: "/legal-hold", method: "DELETE", body: { comment: text.value.trim() || null }, done: "The legal hold is released." }
            : { path: "/review", method: "POST", body: { next_review_on: nextReview.value || null, comment: text.value.trim() || null }, done: "Review recorded." };
      emit("updated", await apiRequest<DocumentDetail>(`${base}${request.path}`, { method: request.method, body: JSON.stringify(request.body) }));
      notice.value = request.done;
    }
    form.value = "";
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to complete the action.");
  } finally {
    isSaving.value = false;
  }
}

function formatDay(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(new Date(value.length === 10 ? `${value}T00:00:00` : value));
}
</script>

<template>
  <section v-if="isVisible" class="detail-card wide" aria-labelledby="records-title">
    <h2 id="records-title">Records management</h2>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="notice" class="settings-notice" role="status">{{ notice }}</p>

    <p v-if="document.disposed_at" class="settings-warning">
      Disposed of on {{ formatDay(document.disposed_at) }} after its retention period. The files and content were destroyed; the registration entry, the file hashes and the history remain as the record.
    </p>
    <p v-if="document.legal_hold" class="settings-warning">
      <strong>Legal hold</strong> since {{ formatDay(document.legal_hold.at) }} by {{ document.legal_hold.by }}: {{ document.legal_hold.reason }}. It cannot be deleted or disposed of.
    </p>
    <dl class="records-facts">
      <template v-if="document.review_on"><dt>Next review</dt><dd>{{ formatDay(document.review_on) }}</dd></template>
      <template v-if="document.retain_until"><dt>Retained until</dt><dd>{{ formatDay(document.retain_until) }}</dd></template>
      <template v-else-if="document.status === 'archived'"><dt>Retention</dt><dd>Kept indefinitely (the type has no retention period)</dd></template>
      <template v-if="openDisposal"><dt>Disposal</dt><dd>Proposed by {{ openDisposal.proposed_by }} on {{ formatDay(openDisposal.proposed_at) }}: {{ openDisposal.reason }}</dd></template>
    </dl>
    <details v-if="document.disposals.some((disposal) => disposal.status !== 'proposed')" class="approval-log">
      <summary>Earlier disposal decisions</summary>
      <ul class="detail-list">
        <li v-for="disposal in document.disposals.filter((item) => item.status !== 'proposed')" :key="disposal.id" class="muted">
          {{ disposal.status === "approved" ? "Approved" : "Rejected" }} by {{ disposal.decided_by }} on {{ formatDay(disposal.decided_at ?? disposal.proposed_at) }}<template v-if="disposal.decision_comment">: {{ disposal.decision_comment }}</template> (proposed by {{ disposal.proposed_by }})
        </li>
      </ul>
    </details>

    <div class="org-actions">
      <button v-if="permissions.can_record_review" class="edit-button" type="button" @click="open('review')">Record review</button>
      <button v-if="permissions.can_place_legal_hold" class="edit-button" type="button" @click="open('hold')">Place legal hold</button>
      <button v-if="permissions.can_release_legal_hold" class="edit-button" type="button" @click="open('release')">Release legal hold</button>
      <button v-if="permissions.can_propose_disposal && !openDisposal" class="edit-button danger-button" type="button" @click="open('disposal')">Propose disposal</button>
    </div>

    <form v-if="form" class="org-inline-form" @submit.prevent="submit">
      <template v-if="form === 'review'">
        <label for="records-next-review">Next review date (leave empty for none)</label>
        <input id="records-next-review" v-model="nextReview" type="date" :min="tomorrow" />
      </template>
      <label for="records-text">{{ form === "hold" ? "Reason (case, request or matter)" : form === "disposal" ? "Reason or disposal schedule" : "Comment (optional)" }}</label>
      <textarea id="records-text" v-model="text" rows="2" :maxlength="form === 'hold' ? 1000 : 4000" :required="form === 'hold' || form === 'disposal'"></textarea>
      <p v-if="form === 'disposal'" class="muted org-hint">After an administrator approves, the files, text, comments and summary are destroyed permanently.</p>
      <div class="org-actions">
        <button class="edit-button" :class="{ 'danger-button': form === 'disposal' }" type="submit" :disabled="isSaving || ((form === 'hold' || form === 'disposal') && !text.trim())">
          {{ form === "hold" ? "Place hold" : form === "release" ? "Release hold" : form === "disposal" ? "Propose disposal" : "Save review" }}
        </button>
        <button class="edit-button" type="button" @click="form = ''">Cancel</button>
      </div>
    </form>
  </section>
</template>
