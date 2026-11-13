<script setup lang="ts">
import { onMounted, ref } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { requirableFields, type Confidentiality, type DocumentTypeInfo } from "../lib/documents";

const emit = defineEmits<{ changed: [] }>();

const types = ref<DocumentTypeInfo[]>([]);
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref("");
const notice = ref("");
const editingId = ref<number | null>(null);
const isFormOpen = ref(false);

const code = ref("");
const name = ref("");
const description = ref("");
const pattern = ref("{CODE}-{YYYY}-{SEQ:4}");
const requiresApproval = ref(true);
const confidentiality = ref<Confidentiality>("general");
const retentionYears = ref<number | null>(null);
const requiredFields = ref<string[]>([]);
const active = ref(true);

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    types.value = await apiRequest<DocumentTypeInfo[]>("/dms/types");
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load document types.");
  } finally {
    isLoading.value = false;
  }
}

function openForm(type?: DocumentTypeInfo) {
  editingId.value = type?.id ?? null;
  code.value = type?.code ?? "";
  name.value = type?.name ?? "";
  description.value = type?.description ?? "";
  pattern.value = type?.number_pattern ?? "{CODE}-{YYYY}-{SEQ:4}";
  requiresApproval.value = type?.requires_approval ?? true;
  confidentiality.value = type?.default_confidentiality ?? "general";
  retentionYears.value = type?.retention_years ?? null;
  requiredFields.value = [...(type?.required_fields ?? [])];
  active.value = type?.active ?? true;
  notice.value = "";
  isFormOpen.value = true;
}

async function save() {
  isSaving.value = true;
  errorMessage.value = "";
  const body: Record<string, unknown> = {
    name: name.value.trim(),
    description: description.value.trim(),
    number_pattern: pattern.value.trim(),
    requires_approval: requiresApproval.value,
    default_confidentiality: confidentiality.value,
    required_fields: requiredFields.value,
    active: active.value,
  };
  if (retentionYears.value) body.retention_years = retentionYears.value;
  else body.clear_retention_years = true;
  try {
    if (editingId.value === null) {
      await apiRequest("/dms/types", { method: "POST", body: JSON.stringify({ ...body, code: code.value.trim() }) });
      notice.value = "Document type created.";
    } else {
      await apiRequest(`/dms/types/${editingId.value}`, { method: "PATCH", body: JSON.stringify(body) });
      notice.value = "Document type saved.";
    }
    isFormOpen.value = false;
    await load();
    emit("changed");
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to save the document type.");
  } finally {
    isSaving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <section class="detail-card" aria-labelledby="dms-types-title">
    <div class="org-row">
      <h2 id="dms-types-title">Document types</h2>
      <button v-if="!isFormOpen" class="edit-button" type="button" @click="openForm()">+ Type</button>
    </div>
    <p class="muted">Types set the registration number format, whether approval is needed, the fields required before registration and the retention period.</p>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="notice" class="settings-notice" role="status">{{ notice }}</p>

    <form v-if="isFormOpen" class="org-inline-form" @submit.prevent="save">
      <div class="inline-fields">
        <div>
          <label for="type-code">Code</label>
          <input id="type-code" v-model="code" type="text" maxlength="16" required placeholder="ORDER" :disabled="editingId !== null" />
        </div>
        <div><label for="type-name">Name</label><input id="type-name" v-model="name" type="text" maxlength="255" required placeholder="Order" /></div>
      </div>
      <label for="type-description">Description (optional)</label>
      <input id="type-description" v-model="description" type="text" maxlength="1000" />
      <label for="type-pattern">Number pattern</label>
      <input id="type-pattern" v-model="pattern" type="text" maxlength="64" required aria-describedby="type-pattern-help" />
      <p id="type-pattern-help" class="muted org-hint">Tokens: {CODE} type code, {YYYY} or {YY} year, {UNIT} owner unit code, {SEQ} or {SEQ:4} yearly counter. A year and the counter are required.</p>
      <div class="inline-fields">
        <div>
          <label for="type-confidentiality">Default confidentiality</label>
          <select id="type-confidentiality" v-model="confidentiality">
            <option value="general">General</option>
            <option value="confidential">Confidential</option>
          </select>
        </div>
        <div><label for="type-retention">Retention (years, optional)</label><input id="type-retention" v-model.number="retentionYears" type="number" min="1" max="100" /></div>
      </div>
      <label class="pm-all-projects-toggle"><input v-model="requiresApproval" type="checkbox" /> Requires approval before registration</label>
      <fieldset class="dms-fieldset">
        <legend>Required before registration</legend>
        <label v-for="item in requirableFields" :key="item.field" class="pm-all-projects-toggle"><input v-model="requiredFields" type="checkbox" :value="item.field" /> {{ item.label }}</label>
      </fieldset>
      <label class="pm-all-projects-toggle"><input v-model="active" type="checkbox" /> Active (offered for new documents)</label>
      <div class="org-actions">
        <button class="edit-button" type="submit" :disabled="isSaving">{{ isSaving ? "Saving..." : editingId === null ? "Create type" : "Save type" }}</button>
        <button class="edit-button" type="button" @click="isFormOpen = false">Cancel</button>
      </div>
    </form>

    <p v-if="isLoading" class="muted">Loading...</p>
    <ul v-else class="detail-list">
      <li v-for="type in types" :key="type.id" class="org-row" :class="{ 'is-archived': !type.active }">
        <span>
          <strong>{{ type.name }}</strong> <span class="org-kind">{{ type.code }}</span>
          <span class="muted"> · e.g. {{ type.number_example }} · {{ type.requires_approval ? "approval required" : "no approval" }}<template v-if="type.retention_years"> · keep {{ type.retention_years }} years</template><template v-if="!type.active"> · inactive</template></span>
        </span>
        <button class="edit-button" type="button" @click="openForm(type)">Edit</button>
      </li>
    </ul>
  </section>
</template>
