<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { personLabel } from "../lib/org";
import {
  actionLabel,
  emptyStage,
  roundStatusLabels,
  stagesFromTemplate,
  stepKindLabels,
  stepStatusLabels,
  stepTone,
  submitPayload,
  validateRoute,
  type ApprovalCandidates,
  type ApprovalHistory,
  type ApprovalStep,
  type CandidatePerson,
  type DraftStage,
} from "../lib/approvals";

const props = defineProps<{ documentId: number; typeName: string }>();
const emit = defineEmits<{ changed: [] }>();

const today = new Date().toISOString().slice(0, 10);
const history = ref<ApprovalHistory | null>(null);
const candidates = ref<ApprovalCandidates | null>(null);
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref("");
const notice = ref("");

const isBuilding = ref(false);
const stages = ref<DraftStage[]>([]);
const submitComment = ref("");
const decisionComments = ref<Record<number, string>>({});
const forwardFor = ref<number | null>(null);
const forwardCandidates = ref<CandidatePerson[]>([]);
const forwardTo = ref<number | null>(null);
const forwardDue = ref("");
const reviewerId = ref<number | null>(null);
const reviewerDue = ref("");
const showOlder = ref(false);

const current = computed(() => history.value?.rounds[0] ?? null);
const older = computed(() => history.value?.rounds.slice(1) ?? []);
const permissions = computed(() => history.value?.permissions ?? {});
const superiorIds = computed(() => candidates.value?.superiors.map((person) => person.id) ?? []);
const problems = computed(() => validateRoute(stages.value, superiorIds.value, today));
const actionable = computed(() => (current.value?.status === "active" ? current.value.stages.flatMap((stage) => stage.steps).filter((step) => step.can_act) : []));
const hasTemplate = computed(() => (candidates.value?.template.length ?? 0) > 0);

async function load() {
  isLoading.value = true;
  try {
    history.value = await apiRequest<ApprovalHistory>(`/dms/documents/${props.documentId}/approval`);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load the approval.");
  } finally {
    isLoading.value = false;
  }
}

async function loadCandidates() {
  if (!candidates.value) candidates.value = await apiRequest<ApprovalCandidates>(`/dms/documents/${props.documentId}/approval/candidates`);
}

async function run(action: () => Promise<ApprovalHistory | unknown>, success: string, fallback: string) {
  isSaving.value = true;
  errorMessage.value = "";
  notice.value = "";
  try {
    const result = await action();
    if (result && typeof result === "object" && "rounds" in result) history.value = result as ApprovalHistory;
    else await load();
    notice.value = success;
    emit("changed");
    return true;
  } catch (error) {
    errorMessage.value = errorText(error, fallback);
    return false;
  } finally {
    isSaving.value = false;
  }
}

async function openBuilder() {
  errorMessage.value = "";
  try {
    await loadCandidates();
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load the people you can send this to.");
    return;
  }
  stages.value = hasTemplate.value ? stagesFromTemplate(candidates.value!.template) : [emptyStage()];
  submitComment.value = "";
  isBuilding.value = true;
}

function peopleFor(kind: string): CandidatePerson[] {
  return kind === "review" ? (candidates.value?.colleagues ?? []) : (candidates.value?.superiors ?? []);
}

function optionLabel(person: CandidatePerson): string {
  return person.level ? `Level ${person.level} · ${personLabel({ email: person.email, position_title: person.position_title })}` : personLabel({ email: person.email, position_title: person.position_title });
}

function onKindChange(step: DraftStage["steps"][number]) {
  if (step.kind !== "review" && step.assigneeId !== null && !superiorIds.value.includes(step.assigneeId)) step.assigneeId = null;
}

async function submit(useStages: boolean) {
  const body = useStages ? submitPayload(stages.value, submitComment.value) : submitComment.value.trim() ? { comment: submitComment.value.trim() } : {};
  if (await run(() => apiRequest<ApprovalHistory>(`/dms/documents/${props.documentId}/approval/submit`, { method: "POST", body: JSON.stringify(body) }), "Sent for approval.", "Unable to send the document for approval.")) {
    isBuilding.value = false;
  }
}

async function decide(step: ApprovalStep, decision: "approve" | "reject" | "return" | "review") {
  const comment = (decisionComments.value[step.id] ?? "").trim();
  if ((decision === "reject" || decision === "return" || decision === "review") && !comment) {
    errorMessage.value = decision === "review" ? "Write your review first." : "Give a reason so the author knows what to change.";
    return;
  }
  if (decision === "reject" && !window.confirm("Reject the document? The approval ends and the author must send it again.")) return;
  const labels = { approve: "Approved.", reject: "Rejected.", return: "Returned to the author.", review: "Review sent." };
  if (await run(() => apiRequest<ApprovalHistory>(`/dms/approvals/steps/${step.id}/decision`, { method: "POST", body: JSON.stringify({ decision, comment: comment || null }) }), labels[decision], "Unable to record the decision.")) {
    decisionComments.value[step.id] = "";
  }
}

async function openForward(step: ApprovalStep) {
  forwardFor.value = step.id;
  forwardTo.value = null;
  forwardDue.value = "";
  try {
    forwardCandidates.value = await apiRequest<CandidatePerson[]>(`/dms/approvals/steps/${step.id}/forward-candidates`);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load your subordinates.");
  }
}

async function forward(step: ApprovalStep) {
  if (forwardTo.value === null) return;
  const body = { assignee_id: forwardTo.value, due_on: forwardDue.value || null, comment: (decisionComments.value[step.id] ?? "").trim() || null };
  if (await run(() => apiRequest<ApprovalHistory>(`/dms/approvals/steps/${step.id}/forward`, { method: "POST", body: JSON.stringify(body) }), "Review requested; the decision stays with you.", "Unable to request the review.")) {
    forwardFor.value = null;
  }
}

async function addReviewer() {
  if (reviewerId.value === null) return;
  const body = { assignee_id: reviewerId.value, due_on: reviewerDue.value || null };
  if (await run(() => apiRequest<ApprovalHistory>(`/dms/documents/${props.documentId}/approval/reviewers`, { method: "POST", body: JSON.stringify(body) }), "Reviewer added to the current stage.", "Unable to add the reviewer.")) {
    reviewerId.value = null;
    reviewerDue.value = "";
  }
}

async function openReviewerForm() {
  try {
    await loadCandidates();
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load colleagues.");
  }
}

async function withdraw() {
  const comment = window.prompt("Withdraw from approval? Optionally say why:");
  if (comment === null) return;
  await run(() => apiRequest<ApprovalHistory>(`/dms/documents/${props.documentId}/approval/withdraw`, { method: "POST", body: JSON.stringify({ comment }) }), "Withdrawn from approval.", "Unable to withdraw.");
}

function formatWhen(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

function formatDay(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(new Date(`${value}T00:00:00`));
}

onMounted(load);
defineExpose({ load });
</script>

<template>
  <section class="detail-card wide" aria-labelledby="approval-title">
    <div class="org-row">
      <h2 id="approval-title">Approval</h2>
      <span class="org-actions">
        <span v-if="current" class="status-pill" :class="`dms-tone-${current.status === 'approved' ? 'done' : current.status === 'active' ? 'progress' : current.status === 'withdrawn' ? 'closed' : 'attention'}`">
          Round {{ current.round }} · {{ roundStatusLabels[current.status] }}
        </span>
        <button v-if="permissions.can_submit && !isBuilding" class="edit-button" type="button" :disabled="isSaving" @click="openBuilder">{{ current ? "Send again" : "Send for approval" }}</button>
        <button v-if="permissions.can_withdraw" class="edit-button danger-button" type="button" :disabled="isSaving" @click="withdraw">Withdraw</button>
      </span>
    </div>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="notice" class="settings-notice" role="status">{{ notice }}</p>
    <p v-if="isLoading" class="muted">Loading...</p>
    <p v-else-if="!current && !isBuilding" class="muted">Not sent for approval yet.{{ permissions.can_submit ? "" : " Upload a file and check the document in first." }}</p>

    <form v-if="isBuilding" class="org-inline-form" aria-label="Approval route" @submit.prevent="submit(true)">
      <p class="muted org-hint">
        Stages run one after another; everyone in a stage is asked at the same time. Approvals and signatures go to your superiors; reviews can go to any colleague.
      </p>
      <div v-if="current || hasTemplate" class="org-actions">
        <button v-if="current" class="edit-button" type="button" :disabled="isSaving" @click="submit(false)">Re-run the previous route</button>
        <button v-if="hasTemplate" class="edit-button" type="button" @click="stages = stagesFromTemplate(candidates!.template)">Use the {{ typeName }} route</button>
      </div>
      <fieldset v-for="(stage, stageIndex) in stages" :key="stageIndex" class="dms-fieldset approval-stage-editor">
        <legend>Stage {{ stageIndex + 1 }}</legend>
        <div class="org-row">
          <label class="approval-inline">
            <span>Completes when</span>
            <select v-model="stage.rule">
              <option value="all">everyone agrees</option>
              <option value="any">anyone approves</option>
            </select>
          </label>
          <button v-if="stages.length > 1" class="edit-button" type="button" @click="stages.splice(stageIndex, 1)">Remove stage</button>
        </div>
        <div v-for="(step, stepIndex) in stage.steps" :key="stepIndex" class="approval-step-editor">
          <label>
            <span class="visually-hidden">Role in stage {{ stageIndex + 1 }}</span>
            <select v-model="step.kind" @change="onKindChange(step)">
              <option value="approve">Approve</option>
              <option value="sign">Sign</option>
              <option value="review">Review</option>
            </select>
          </label>
          <label>
            <span class="visually-hidden">Person</span>
            <select v-model.number="step.assigneeId" required>
              <option :value="null" disabled>{{ step.kind === "review" ? "Choose a colleague" : "Choose a superior" }}</option>
              <option v-for="person in peopleFor(step.kind)" :key="person.id" :value="person.id">{{ optionLabel(person) }}</option>
            </select>
          </label>
          <label>
            <span class="visually-hidden">Due date</span>
            <input v-model="step.dueOn" type="date" :min="today" aria-label="Due date (optional)" />
          </label>
          <button v-if="stage.steps.length > 1" class="close-button" type="button" aria-label="Remove participant" @click="stage.steps.splice(stepIndex, 1)">&#10005;</button>
        </div>
        <button class="edit-button" type="button" @click="stage.steps.push({ assigneeId: null, kind: 'review', dueOn: '' })">+ Participant</button>
      </fieldset>
      <p v-if="candidates && candidates.superiors.length === 0" class="settings-warning">You have no superiors in the organization structure, so only reviews are possible. Ask an administrator to place you in the structure.</p>
      <button v-if="stages.length < 10" class="edit-button" type="button" @click="stages.push(emptyStage())">+ Stage</button>
      <label for="approval-comment">Message to the participants (optional)</label>
      <textarea id="approval-comment" v-model="submitComment" rows="2" maxlength="4000"></textarea>
      <ul v-if="problems.length" class="detail-list approval-problems" aria-live="polite">
        <li v-for="problem in problems" :key="problem">{{ problem }}</li>
      </ul>
      <div class="org-actions">
        <button class="edit-button" type="submit" :disabled="isSaving || problems.length > 0">{{ isSaving ? "Sending..." : "Send for approval" }}</button>
        <button class="edit-button" type="button" @click="isBuilding = false">Cancel</button>
      </div>
    </form>

    <template v-if="current">
      <p class="muted">Version {{ current.version_number }} sent by {{ current.created_by }} on {{ formatWhen(current.created_at) }}.</p>
      <ol class="approval-timeline">
        <li v-for="stage in current.stages" :key="stage.id" class="approval-stage" :class="`is-${stage.status}`">
          <div class="org-row">
            <strong>Stage {{ stage.position }}</strong>
            <span class="muted">{{ stage.rule === "all" ? "everyone agrees" : "anyone approves" }} · {{ stage.status }}</span>
          </div>
          <ul class="detail-list">
            <li v-for="step in stage.steps" :key="step.id" class="approval-step" :class="{ 'is-consultation': step.kind === 'consult' }">
              <div class="org-row">
                <span>
                  <span class="org-kind">{{ stepKindLabels[step.kind] }}</span>
                  <strong>{{ step.assignee.email }}</strong>
                  <span class="muted"><template v-if="step.assignee.position"> · {{ step.assignee.position }}</template><template v-if="step.assignee.unit">, {{ step.assignee.unit }}</template></span>
                </span>
                <span class="org-actions">
                  <span v-if="step.due_on" class="muted" :class="{ 'approval-overdue': step.is_overdue }">due {{ formatDay(step.due_on) }}</span>
                  <span class="status-pill" :class="`dms-tone-${stepTone(step.status)}`">{{ stepStatusLabels[step.status] }}</span>
                </span>
              </div>
              <p v-if="step.decided_at" class="muted approval-decision">
                {{ formatWhen(step.decided_at) }} · {{ step.decided_by }}<template v-if="step.on_behalf_of"> on behalf of {{ step.on_behalf_of }}</template>
              </p>
              <p v-if="step.comment" class="approval-comment">“{{ step.comment }}”</p>

              <div v-if="step.can_act" class="org-inline-form approval-act">
                <p v-if="step.acting_for" class="muted">You act as deputy for {{ step.acting_for }}.</p>
                <label :for="`decision-${step.id}`">{{ step.kind === "consult" ? "Your review" : "Comment" }}{{ step.kind === "consult" ? "" : " (required to reject or return)" }}</label>
                <textarea :id="`decision-${step.id}`" v-model="decisionComments[step.id]" rows="2" maxlength="4000"></textarea>
                <div class="org-actions">
                  <template v-if="step.kind === 'consult'">
                    <button class="edit-button" type="button" :disabled="isSaving" @click="decide(step, 'review')">Send review</button>
                  </template>
                  <template v-else>
                    <button class="edit-button" type="button" :disabled="isSaving" @click="decide(step, 'approve')">{{ step.kind === "sign" ? "Sign" : step.kind === "review" ? "Agree" : "Approve" }}</button>
                    <button class="edit-button" type="button" :disabled="isSaving" @click="decide(step, 'return')">Return for revision</button>
                    <button class="edit-button danger-button" type="button" :disabled="isSaving" @click="decide(step, 'reject')">Reject</button>
                    <button class="edit-button" type="button" :disabled="isSaving" @click="openForward(step)">Ask a subordinate</button>
                  </template>
                </div>
                <div v-if="forwardFor === step.id" class="org-inline-form">
                  <label :for="`forward-${step.id}`">Ask for a review (the decision stays with you)</label>
                  <select :id="`forward-${step.id}`" v-model.number="forwardTo">
                    <option :value="null" disabled>{{ forwardCandidates.length ? "Choose a subordinate" : "You have no subordinates" }}</option>
                    <option v-for="person in forwardCandidates" :key="person.id" :value="person.id">{{ personLabel(person) }}</option>
                  </select>
                  <input v-model="forwardDue" type="date" :min="today" aria-label="Review due date (optional)" />
                  <div class="org-actions">
                    <button class="edit-button" type="button" :disabled="isSaving || forwardTo === null" @click="forward(step)">Request review</button>
                    <button class="edit-button" type="button" @click="forwardFor = null">Cancel</button>
                  </div>
                </div>
              </div>
            </li>
          </ul>
        </li>
      </ol>

      <form v-if="current.status === 'active' && permissions.can_withdraw" class="org-inline-form" @submit.prevent="addReviewer" @focusin.once="openReviewerForm">
        <label for="add-reviewer">Add a reviewer to the current stage</label>
        <select id="add-reviewer" v-model.number="reviewerId" @focus="openReviewerForm">
          <option :value="null" disabled>Choose a colleague</option>
          <option v-for="person in candidates?.colleagues ?? []" :key="person.id" :value="person.id">{{ personLabel(person) }}</option>
        </select>
        <input v-model="reviewerDue" type="date" :min="today" aria-label="Reviewer due date (optional)" />
        <button class="edit-button" type="submit" :disabled="isSaving || reviewerId === null">Add reviewer</button>
      </form>

      <details class="approval-log">
        <summary>Approval log ({{ current.actions.length }})</summary>
        <ol class="detail-list">
          <li v-for="action in current.actions" :key="action.id">
            <strong>{{ actionLabel(action.action) }}</strong>
            <span class="muted"> · {{ action.actor }}<template v-if="action.on_behalf_of"> for {{ action.on_behalf_of }}</template> · {{ formatWhen(action.created_at) }} · v{{ action.version_number }}</span>
            <span v-if="action.comment" class="muted dms-event-detail">{{ action.comment }}</span>
          </li>
        </ol>
      </details>

      <template v-if="older.length">
        <button class="edit-button" type="button" @click="showOlder = !showOlder">{{ showOlder ? "Hide" : "Show" }} earlier rounds ({{ older.length }})</button>
        <ul v-if="showOlder" class="detail-list">
          <li v-for="round in older" :key="round.id">
            <strong>Round {{ round.round }}</strong> <span class="muted">· v{{ round.version_number }} · {{ roundStatusLabels[round.status] }}<template v-if="round.completed_at"> · {{ formatWhen(round.completed_at) }}</template></span>
            <ul class="detail-list">
              <li v-for="step in round.stages.flatMap((stage) => stage.steps).filter((item) => item.comment || item.decided_by)" :key="step.id" class="muted">
                {{ stepKindLabels[step.kind] }} · {{ step.assignee.email }} · {{ stepStatusLabels[step.status] }}<template v-if="step.comment"> · “{{ step.comment }}”</template>
              </li>
            </ul>
          </li>
        </ul>
      </template>
    </template>
  </section>
</template>
