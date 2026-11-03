<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { getOrganizationDefaultCurrency } from "../lib/organizationSettings";

type Account = { id: number; name: string; iban: string | null; currency: string; ledger_account_id: number | null; active: boolean };
type LedgerAccount = { id: number; code: string; name: string; type: string; active: boolean };
type ImportRecord = { id: number; filename: string; format: string; opening_balance: string | null; closing_balance: string | null; period_from: string | null; period_to: string | null; imported_lines: number; skipped_duplicates: number; imported_at: string; imported_by: string };
type Line = { id: number; booking_date: string; amount: string; currency: string; reference: string; counterparty: string | null; state: "unmatched" | "matched" | "ignored"; match_type: string | null; match_id: number | null; note: string | null; matched_by: string | null };
type Suggestion = { type: string; id: number; score: number; reasons: string[]; description: string; date: string; amount: string };
type Report = {
  period: { from: string; to: string }; statement_lines: { total: number; matched: number; ignored: number; unmatched: number }; reconciled: boolean; basis: string;
  recorded_receipts_without_bank_line: { id: number; date: string; amount: string; invoice_number: string | null; reference: string | null }[];
  recorded_vendor_payments_without_bank_line: { id: number; date: string; amount: string; vendor: string; bill_reference: string }[];
};

const accounts = ref<Account[]>([]);
const ledgerAccounts = ref<LedgerAccount[]>([]);
const selectedId = ref<number | null>(null);
const imports = ref<ImportRecord[]>([]);
const lines = ref<Line[]>([]);
const report = ref<Report | null>(null);
const suggestionsFor = ref<{ lineId: number; items: Suggestion[] } | null>(null);
const stateFilter = ref<"" | "unmatched" | "matched" | "ignored">("unmatched");
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref("");
const noticeMessage = ref("");

const newName = ref("");
const newIban = ref("");
const newCurrency = ref(getOrganizationDefaultCurrency());
const newLedgerId = ref<number | null>(null);
const importFormat = ref<"camt053" | "csv">("camt053");
const periodFrom = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10));
const periodTo = ref(new Date().toISOString().slice(0, 10));
const selected = computed(() => accounts.value.find((account) => account.id === selectedId.value) ?? null);

async function run(action: () => Promise<unknown>, success?: string) {
  isSaving.value = true;
  errorMessage.value = "";
  noticeMessage.value = "";
  try {
    await action();
    if (success) noticeMessage.value = success;
  } catch (error) {
    errorMessage.value = errorText(error, "The request failed.");
  } finally {
    isSaving.value = false;
  }
}

async function loadAccounts() {
  isLoading.value = true;
  try {
    [accounts.value, ledgerAccounts.value] = await Promise.all([apiRequest<Account[]>("/bank/accounts"), apiRequest<LedgerAccount[]>("/finance/accounts")]);
    if (selectedId.value === null) selectedId.value = accounts.value[0]?.id ?? null;
    await loadSelected();
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load bank accounts.");
  } finally {
    isLoading.value = false;
  }
}

async function loadSelected() {
  suggestionsFor.value = null;
  if (selectedId.value === null) return;
  const query = stateFilter.value ? `?state=${stateFilter.value}` : "";
  [imports.value, lines.value] = await Promise.all([
    apiRequest<ImportRecord[]>(`/bank/accounts/${selectedId.value}/imports`),
    apiRequest<Line[]>(`/bank/accounts/${selectedId.value}/lines${query}`),
  ]);
}

const createAccount = () => run(async () => {
  const created = await apiRequest<Account>("/bank/accounts", { method: "POST", body: JSON.stringify({ name: newName.value, iban: newIban.value || null, currency: newCurrency.value, ledger_account_id: newLedgerId.value }) });
  newName.value = "";
  newIban.value = "";
  selectedId.value = created.id;
  await loadAccounts();
}, "Bank account added.");

async function onFile(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file || selectedId.value === null) return;
  const format = file.name.toLowerCase().endsWith(".xml") ? "camt053" : "csv";
  importFormat.value = format;
  await run(async () => {
    const result = await apiRequest<ImportRecord>(`/bank/accounts/${selectedId.value}/imports`, { method: "POST", body: JSON.stringify({ filename: file.name, format, content: await file.text() }) });
    noticeMessage.value = `Imported ${result.imported_lines} transaction(s); ${result.skipped_duplicates} duplicate(s) skipped.`;
    await loadSelected();
  });
  (event.target as HTMLInputElement).value = "";
}

const autoMatch = () => run(async () => {
  const result = await apiRequest<{ matched: number; left_open: number }>(`/bank/accounts/${selectedId.value}/auto-match`, { method: "POST" });
  noticeMessage.value = `${result.matched} line(s) matched automatically; ${result.left_open} left for review.`;
  await loadSelected();
});

const showSuggestions = (line: Line) => run(async () => {
  suggestionsFor.value = { lineId: line.id, items: await apiRequest<Suggestion[]>(`/bank/lines/${line.id}/suggestions`) };
});

const confirmMatch = (line: Line, suggestion: Suggestion) => run(async () => {
  await apiRequest(`/bank/lines/${line.id}/match`, { method: "POST", body: JSON.stringify({ type: suggestion.type, id: suggestion.id }) });
  await loadSelected();
}, "Line matched.");

const unmatch = (line: Line) => run(async () => {
  await apiRequest(`/bank/lines/${line.id}/unmatch`, { method: "POST" });
  await loadSelected();
}, "Match removed.");

function ignore(line: Line) {
  const reason = window.prompt("Why is this line not matched to a recorded payment (for example a bank fee)?");
  if (!reason?.trim()) return;
  return run(async () => {
    await apiRequest(`/bank/lines/${line.id}/ignore`, { method: "POST", body: JSON.stringify({ reason }) });
    await loadSelected();
  }, "Line ignored with a recorded reason.");
}

const runReport = () => run(async () => {
  report.value = await apiRequest<Report>(`/bank/accounts/${selectedId.value}/reconciliation?from=${periodFrom.value}&to=${periodTo.value}`);
});

onMounted(loadAccounts);
</script>

<template>
  <div class="page-content">
    <div class="page-heading"><div><p class="eyebrow">Bank</p><h1>Match the bank to the books.</h1></div></div>
    <p class="muted">Import statements as camt.053 XML or CSV files and match each line to a recorded customer receipt or vendor payment. Nothing connects to your bank, and no payments are made or posted from here.</p>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="noticeMessage" class="sales-notice" role="status">{{ noticeMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading bank accounts...</h2></div>

    <template v-else>
      <section class="detail-card">
        <h2>Bank accounts</h2>
        <label for="bank-select">Account</label>
        <select id="bank-select" v-model.number="selectedId" @change="run(loadSelected)"><option :value="null" disabled>Select an account</option><option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.name }} ({{ account.currency }})</option></select>
        <form class="inventory-form-grid" @submit.prevent="createAccount">
          <div><label for="bank-name">New account name</label><input id="bank-name" v-model="newName" maxlength="120" required /></div>
          <div><label for="bank-iban">IBAN (optional)</label><input id="bank-iban" v-model="newIban" maxlength="42" /></div>
          <div><label for="bank-currency">Currency</label><select id="bank-currency" v-model="newCurrency"><option>EUR</option><option>GBP</option><option>USD</option></select></div>
          <div><label for="bank-ledger">Cash account in the ledger</label><select id="bank-ledger" v-model.number="newLedgerId"><option :value="null">None</option><option v-for="account in ledgerAccounts.filter((a) => a.type === 'asset' && a.active)" :key="account.id" :value="account.id">{{ account.code }} · {{ account.name }}</option></select></div>
          <button class="button" type="submit" :disabled="isSaving">Add account</button>
        </form>
      </section>

      <template v-if="selected">
        <section class="detail-card">
          <h2>Import a statement</h2>
          <label for="bank-file">Statement file (.xml camt.053 or .csv with date and amount columns)</label>
          <input id="bank-file" type="file" accept=".xml,.csv,text/csv,text/xml" :disabled="isSaving" @change="onFile" />
          <ul v-if="imports.length" class="detail-list">
            <li v-for="item in imports.slice(0, 5)" :key="item.id">{{ item.filename }} · {{ item.period_from }} → {{ item.period_to }} · {{ item.imported_lines }} new, {{ item.skipped_duplicates }} duplicate<template v-if="item.closing_balance"> · closing {{ item.closing_balance }}</template></li>
          </ul>
        </section>

        <section class="detail-card wide">
          <h2>Statement lines</h2>
          <div class="pm-filters">
            <div><label for="bank-state">Show</label><select id="bank-state" v-model="stateFilter" @change="run(loadSelected)"><option value="unmatched">Unmatched</option><option value="matched">Matched</option><option value="ignored">Ignored</option><option value="">All</option></select></div>
            <button class="edit-button" type="button" :disabled="isSaving" @click="autoMatch">Auto-match by reference</button>
          </div>
          <p v-if="lines.length === 0" class="muted">No statement lines in this view.</p>
          <div v-else class="finance-table-wrap">
            <table class="finance-table">
              <thead><tr><th>Date</th><th>Amount</th><th>Details</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <template v-for="line in lines" :key="line.id">
                  <tr>
                    <td>{{ line.booking_date }}</td><td>{{ line.amount }} {{ line.currency }}</td>
                    <td>{{ line.reference }}<span v-if="line.counterparty" class="muted"> · {{ line.counterparty }}</span></td>
                    <td>{{ line.state }}<span v-if="line.note" class="muted"> ({{ line.note }})</span><span v-if="line.match_type" class="muted"> {{ line.match_type.replace("_", " ") }} #{{ line.match_id }}</span></td>
                    <td>
                      <button v-if="line.state === 'unmatched'" class="edit-button" type="button" :disabled="isSaving" @click="showSuggestions(line)">Find match</button>
                      <button v-if="line.state === 'unmatched'" class="edit-button" type="button" :disabled="isSaving" @click="ignore(line)">Ignore</button>
                      <button v-if="line.state !== 'unmatched'" class="edit-button" type="button" :disabled="isSaving" @click="unmatch(line)">Undo</button>
                    </td>
                  </tr>
                  <tr v-if="suggestionsFor?.lineId === line.id">
                    <td colspan="5">
                      <p v-if="suggestionsFor.items.length === 0" class="muted">No recorded payment has this exact amount within a week. Record the payment first, or ignore the line.</p>
                      <div v-for="item in suggestionsFor.items" :key="`${item.type}-${item.id}`">
                        {{ item.description }} · {{ item.date }} · {{ item.amount }} <span class="muted">(score {{ item.score }}: {{ item.reasons.join(", ") }})</span>
                        <button class="edit-button" type="button" :disabled="isSaving" @click="confirmMatch(line, item)">Match</button>
                      </div>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </section>

        <section class="detail-card wide">
          <h2>Reconciliation</h2>
          <form class="pm-filters" @submit.prevent="runReport">
            <div><label for="rec-from">From</label><input id="rec-from" v-model="periodFrom" type="date" required /></div>
            <div><label for="rec-to">To</label><input id="rec-to" v-model="periodTo" type="date" required /></div>
            <button class="edit-button" type="submit" :disabled="isSaving">Run reconciliation</button>
          </form>
          <template v-if="report">
            <p><strong :class="report.reconciled ? 'finance-balanced' : 'finance-unbalanced'">{{ report.reconciled ? "Reconciled" : "Differences found" }}</strong> · {{ report.statement_lines.total }} statement lines: {{ report.statement_lines.matched }} matched, {{ report.statement_lines.ignored }} ignored, {{ report.statement_lines.unmatched }} unmatched</p>
            <template v-if="report.recorded_receipts_without_bank_line.length">
              <h3>Receipts recorded in the books but not on the statement</h3>
              <ul class="detail-list"><li v-for="item in report.recorded_receipts_without_bank_line" :key="item.id">{{ item.date }} · {{ item.amount }} · invoice {{ item.invoice_number }}<span v-if="item.reference" class="muted"> · {{ item.reference }}</span></li></ul>
            </template>
            <template v-if="report.recorded_vendor_payments_without_bank_line.length">
              <h3>Vendor payments recorded but not on the statement</h3>
              <ul class="detail-list"><li v-for="item in report.recorded_vendor_payments_without_bank_line" :key="item.id">{{ item.date }} · {{ item.amount }} · {{ item.vendor }} {{ item.bill_reference }}</li></ul>
            </template>
            <p class="muted">{{ report.basis }}</p>
          </template>
        </section>
      </template>
    </template>
  </div>
</template>
