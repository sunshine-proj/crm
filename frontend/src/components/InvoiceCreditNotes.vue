<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { apiRequest, downloadFile, errorText } from "../lib/api";

const props = defineProps<{ invoiceId: number; status: string; taxCodes: string[] }>();
const emit = defineEmits<{ changed: [] }>();

type CreditNote = {
  id: number; credit_note_number: string; issue_date: string; reason: string; currency: string;
  net_total: string; tax_total: string; total: string; issued_by: string;
};
type CreditLine = { description: string; amount: string; tax_code: string };

const notes = ref<CreditNote[]>([]);
const reason = ref("");
const creditDate = ref(new Date().toISOString().slice(0, 10));
const lines = ref<CreditLine[]>([{ description: "", amount: "", tax_code: props.taxCodes[0] ?? "" }]);
const isSaving = ref(false);
const errorMessage = ref("");
const noticeMessage = ref("");
const canCredit = computed(() => ["issued", "partially_paid"].includes(props.status));

async function load() {
  try {
    notes.value = await apiRequest<CreditNote[]>(`/invoices/${props.invoiceId}/credit-notes`);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load credit notes.");
  }
}

async function issue(wholeInvoice: boolean) {
  errorMessage.value = "";
  noticeMessage.value = "";
  if (!reason.value.trim()) {
    errorMessage.value = "Enter the reason for the credit note.";
    return;
  }
  if (wholeInvoice && !window.confirm("Credit the whole invoice? This cannot be undone; issued documents are never edited.")) return;
  isSaving.value = true;
  try {
    const items = wholeInvoice ? [] : lines.value.filter((line) => line.description.trim() || line.amount.trim()).map((line) => ({ description: line.description, amount: line.amount, tax_code: line.tax_code || null }));
    if (!wholeInvoice && items.length === 0) throw new Error("Add at least one line to credit.");
    const note = await apiRequest<CreditNote>(`/invoices/${props.invoiceId}/credit-notes`, { method: "POST", body: JSON.stringify({ reason: reason.value, credit_date: creditDate.value, items }) });
    noticeMessage.value = `Credit note ${note.credit_note_number} issued for ${note.total} ${note.currency}.`;
    reason.value = "";
    lines.value = [{ description: "", amount: "", tax_code: props.taxCodes[0] ?? "" }];
    await load();
    emit("changed");
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to issue the credit note.");
  } finally {
    isSaving.value = false;
  }
}

async function pdf(note: CreditNote) {
  try {
    await downloadFile(`/credit-notes/${note.id}/pdf`, `${note.credit_note_number}.pdf`);
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to download the credit note.");
  }
}

onMounted(load);
watch(() => props.invoiceId, load);
</script>

<template>
  <section class="invoice-payment-panel" aria-label="Credit notes">
    <h3>Credit notes</h3>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="noticeMessage" class="sales-notice" role="status">{{ noticeMessage }}</p>
    <ul v-if="notes.length" class="detail-list">
      <li v-for="note in notes" :key="note.id">
        <strong>{{ note.credit_note_number }}</strong> · {{ note.issue_date }} · {{ note.total }} {{ note.currency }}
        <span class="muted"> (net {{ note.net_total }}, tax {{ note.tax_total }}) · {{ note.reason }} · {{ note.issued_by }}</span>
        <button class="edit-button" type="button" @click="pdf(note)">PDF</button>
      </li>
    </ul>
    <p v-else class="muted">No credit notes. An issued invoice is never edited; correct it with a credit note.</p>

    <form v-if="canCredit" class="sales-form-grid" @submit.prevent="issue(false)">
      <div><label for="credit-reason">Reason</label><input id="credit-reason" v-model="reason" maxlength="500" /></div>
      <div><label for="credit-date">Credit note date</label><input id="credit-date" v-model="creditDate" type="date" /></div>
      <div v-for="(line, index) in lines" :key="index" class="invoice-line-row">
        <input v-model="line.description" maxlength="255" :aria-label="`Credited line ${index + 1} description`" placeholder="What is credited" />
        <input v-model="line.amount" inputmode="decimal" :aria-label="`Credited line ${index + 1} net amount`" placeholder="Net amount" />
        <select v-if="taxCodes.length" v-model="line.tax_code" :aria-label="`Credited line ${index + 1} tax code`"><option v-for="code in taxCodes" :key="code" :value="code">{{ code }}</option></select>
      </div>
      <div class="task-form-actions">
        <button class="edit-button" type="button" @click="lines.push({ description: '', amount: '', tax_code: taxCodes[0] ?? '' })">Add line</button>
        <button class="button" type="submit" :disabled="isSaving">{{ isSaving ? "Issuing..." : "Issue credit note" }}</button>
        <button class="edit-button danger-button" type="button" :disabled="isSaving || notes.length > 0" @click="issue(true)">Credit whole invoice</button>
      </div>
    </form>
    <p v-else-if="['paid', 'credited'].includes(status)" class="muted">Nothing is left owed on this invoice, so it cannot be credited (refunding received money is not supported).</p>
  </section>
</template>
