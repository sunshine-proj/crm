<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { apiRequest, errorText } from "../lib/api";

const props = defineProps<{ documentId: number; versionNumbers: number[] }>();

type Comment = { id: number; version_number: number; author: string; quote: string | null; body: string; created_at: string; resolved_at: string | null; resolved_by: string | null; can_resolve: boolean };
type Comparison = { from: number; to: number; comparable: boolean; reason: string | null; segments: { op: "equal" | "added" | "removed"; text: string }[]; added: number; removed: number };

const comments = ref<Comment[]>([]);
const errorMessage = ref("");
const isSaving = ref(false);
const versionNumber = ref<number | null>(null);
const quote = ref("");
const body = ref("");
const showResolved = ref(false);
const compareFrom = ref<number | null>(null);
const compareTo = ref<number | null>(null);
const comparison = ref<Comparison | null>(null);

const visible = computed(() => comments.value.filter((comment) => showResolved.value || !comment.resolved_at));
const openCount = computed(() => comments.value.filter((comment) => !comment.resolved_at).length);

function resetVersions() {
  versionNumber.value = props.versionNumbers[0] ?? null;
  compareTo.value = props.versionNumbers[0] ?? null;
  compareFrom.value = props.versionNumbers[1] ?? null;
}

async function load() {
  try {
    comments.value = await apiRequest<Comment[]>(`/dms/documents/${props.documentId}/comments`);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load comments.");
  }
}

async function add() {
  if (versionNumber.value === null || !body.value.trim()) return;
  isSaving.value = true;
  errorMessage.value = "";
  try {
    const payload = { version_number: versionNumber.value, body: body.value.trim(), quote: quote.value.trim() || null };
    comments.value.push(await apiRequest<Comment>(`/dms/documents/${props.documentId}/comments`, { method: "POST", body: JSON.stringify(payload) }));
    body.value = quote.value = "";
    await load();
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to add the comment.");
  } finally {
    isSaving.value = false;
  }
}

async function resolve(comment: Comment) {
  try {
    Object.assign(comment, await apiRequest<Comment>(`/dms/documents/${props.documentId}/comments/${comment.id}/resolve`, { method: "POST" }));
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to resolve the comment.");
  }
}

async function compare() {
  if (compareFrom.value === null || compareTo.value === null) return;
  errorMessage.value = "";
  try {
    comparison.value = await apiRequest<Comparison>(`/dms/documents/${props.documentId}/versions/compare?from=${compareFrom.value}&to=${compareTo.value}`);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to compare the versions.");
  }
}

function formatWhen(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

watch(() => props.versionNumbers.join(","), resetVersions);
onMounted(() => {
  resetVersions();
  void load();
});
</script>

<template>
  <section class="detail-card" aria-labelledby="comments-title">
    <div class="org-row">
      <h2 id="comments-title">Comments <span class="board-count">{{ openCount }} open</span></h2>
      <label class="pm-all-projects-toggle"><input v-model="showResolved" type="checkbox" /> Show resolved</label>
    </div>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="visible.length === 0" class="muted">No {{ showResolved ? "" : "open " }}comments.</p>
    <ul v-else class="detail-list">
      <li v-for="comment in visible" :key="comment.id" class="dms-version" :class="{ 'is-archived': comment.resolved_at }">
        <div class="org-row">
          <span><span class="org-kind">v{{ comment.version_number }}</span> <strong>{{ comment.author }}</strong> <span class="muted">· {{ formatWhen(comment.created_at) }}</span></span>
          <button v-if="comment.can_resolve" class="edit-button" type="button" @click="resolve(comment)">Resolve</button>
        </div>
        <blockquote v-if="comment.quote" class="approval-quote">{{ comment.quote }}</blockquote>
        <p class="dms-prewrap approval-comment-body">{{ comment.body }}</p>
        <span v-if="comment.resolved_at" class="muted">Resolved by {{ comment.resolved_by }}</span>
      </li>
    </ul>
    <form v-if="versionNumbers.length" class="org-inline-form" @submit.prevent="add">
      <label for="comment-version">On version</label>
      <select id="comment-version" v-model.number="versionNumber">
        <option v-for="number in versionNumbers" :key="number" :value="number">v{{ number }}</option>
      </select>
      <label for="comment-quote">Passage (optional)</label>
      <input id="comment-quote" v-model="quote" type="text" maxlength="500" placeholder="Paste the sentence you mean" />
      <label for="comment-body">Comment</label>
      <textarea id="comment-body" v-model="body" rows="2" maxlength="4000" required></textarea>
      <button class="edit-button" type="submit" :disabled="isSaving || !body.trim()">Add comment</button>
    </form>

    <template v-if="versionNumbers.length > 1">
      <h3 class="org-subheading">Compare versions</h3>
      <form class="org-actions" @submit.prevent="compare">
        <label><span class="visually-hidden">From version</span><select v-model.number="compareFrom"><option v-for="number in versionNumbers" :key="number" :value="number">v{{ number }}</option></select></label>
        <span aria-hidden="true">&rarr;</span>
        <label><span class="visually-hidden">To version</span><select v-model.number="compareTo"><option v-for="number in versionNumbers" :key="number" :value="number">v{{ number }}</option></select></label>
        <button class="edit-button" type="submit" :disabled="compareFrom === compareTo">Compare</button>
      </form>
      <template v-if="comparison">
        <p v-if="!comparison.comparable" class="muted">{{ comparison.reason }}</p>
        <template v-else>
          <p class="muted">v{{ comparison.from }} → v{{ comparison.to }}: {{ comparison.added }} sentence(s) added, {{ comparison.removed }} removed.</p>
          <div class="dms-diff" role="region" aria-label="Differences">
            <p v-for="(segment, index) in comparison.segments" :key="index" :class="`dms-diff-${segment.op}`">
              <span class="visually-hidden">{{ segment.op === "added" ? "Added:" : segment.op === "removed" ? "Removed:" : "" }}</span>{{ segment.text }}
            </p>
          </div>
        </template>
      </template>
    </template>
  </section>
</template>
