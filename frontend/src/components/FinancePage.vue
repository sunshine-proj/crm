<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { getOrganizationDefaultCurrency } from "../lib/organizationSettings";

type Currency = { code: string; name: string; minor_unit: number };
type Account = { id: number; code: string; name: string; type: string; active: boolean };
type Period = { id: number; name: string; starts_on: string; ends_on: string; closed: boolean };
type TaxCode = { id: number; code: string; name: string; rate_percent: string; active: boolean };
type Bill = {
  id: number;
  vendor_name: string;
  vendor_reference: string;
  currency: string;
  total: string;
  paid_total: string;
  balance_due: string;
  bill_date: string;
  due_date: string | null;
  status: string;
};
type TrialBalance = {
  from: string | null;
  to: string | null;
  currency: string;
  accounts: { account_id: number; code: string; name: string; type: string; debit: string; credit: string; balance: string }[];
  total_debits: string;
  total_credits: string;
  balanced: boolean;
  basis: string;
};
type ArApReport = {
  currency: string;
  receivables_total: string;
  receivables: { invoice_id: number; invoice_number: string; client_name: string; due_date: string | null; overdue: boolean; balance_due: string }[];
  payables_total: string;
  payables: { bill_id: number; vendor_name: string; vendor_reference: string; due_date: string | null; overdue: boolean; balance_due: string }[];
  basis: string;
};
type JournalLineInput = { account_id: number | null; debit: string; credit: string; memo: string };

const tabs = ["Ledger", "Receivables & payables", "Tax & currencies"] as const;
type Tab = (typeof tabs)[number];
const tab = ref<Tab>("Ledger");
const currencies = ref<Currency[]>([]);
const accounts = ref<Account[]>([]);
const periods = ref<Period[]>([]);
const taxCodes = ref<TaxCode[]>([]);
const bills = ref<Bill[]>([]);
const trialBalance = ref<TrialBalance | null>(null);
const arApReport = ref<ArApReport | null>(null);
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref("");
const noticeMessage = ref("");

const accountCode = ref("");
const accountName = ref("");
const accountType = ref("asset");
const accountTypes = ["asset", "liability", "equity", "revenue", "expense"];
const periodName = ref("");
const periodStart = ref("");
const periodEnd = ref("");
const journalDate = ref("");
const journalDescription = ref("");
const journalCurrency = ref(getOrganizationDefaultCurrency());
const journalLines = ref<JournalLineInput[]>([
  { account_id: null, debit: "", credit: "", memo: "" },
  { account_id: null, debit: "", credit: "", memo: "" },
]);
const reportCurrency = ref(getOrganizationDefaultCurrency());
const reportFrom = ref("");
const reportTo = ref("");
const taxCode = ref("");
const taxName = ref("");
const taxRate = ref("");
const vendorName = ref("");
const vendorReference = ref("");
const billCurrency = ref(getOrganizationDefaultCurrency());
const billTotal = ref("");
const billDate = ref("");
const billDueDate = ref("");
const paymentAmounts = ref<Record<number, string>>({});
const paymentDates = ref<Record<number, string>>({});
const paymentReferences = ref<Record<number, string>>({});

function parseMinor(value: string): bigint | null {
  const match = /^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/.exec(value);
  if (!match) return null;
  return BigInt(match[1]) * 100n + BigInt((match[2] ?? "").padEnd(2, "0"));
}

function formatMinor(value: bigint): string {
  return `${value / 100n}.${String(value % 100n).padStart(2, "0")}`;
}

const journalDebitTotal = computed(() => journalLines.value.reduce((sum, line) => sum + (parseMinor(line.debit) ?? 0n), 0n));
const journalCreditTotal = computed(() => journalLines.value.reduce((sum, line) => sum + (parseMinor(line.credit) ?? 0n), 0n));
const journalBalanced = computed(() => journalDebitTotal.value > 0n && journalDebitTotal.value === journalCreditTotal.value);

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = localStorage.getItem("crm_token");
  const response = await fetch(`/api${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });
  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: string } | null;
    throw new Error(payload?.error ?? "Unable to complete the finance request.");
  }
  return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}

async function loadFinance() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    const [loadedCurrencies, loadedAccounts, loadedPeriods, loadedTaxCodes, loadedBills] = await Promise.all([
      request<Currency[]>("/finance/currencies"),
      request<Account[]>("/finance/accounts"),
      request<Period[]>("/finance/periods"),
      request<TaxCode[]>("/finance/tax-codes"),
      request<Bill[]>("/finance/payables"),
    ]);
    currencies.value = loadedCurrencies;
    accounts.value = loadedAccounts;
    periods.value = loadedPeriods;
    taxCodes.value = loadedTaxCodes;
    bills.value = loadedBills;
    await Promise.all([loadTrialBalance(), loadArAp()]);
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load finance records.";
  } finally {
    isLoading.value = false;
  }
}

onMounted(loadFinance);

async function submit<T>(path: string, body: unknown, successMessage: string, method = "POST"): Promise<T | null> {
  isSaving.value = true;
  errorMessage.value = "";
  noticeMessage.value = "";
  try {
    const result = await request<T>(path, { method, body: JSON.stringify(body) });
    noticeMessage.value = successMessage;
    return result;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to save the finance record.";
    return null;
  } finally {
    isSaving.value = false;
  }
}

async function createAccount() {
  if (!accountCode.value.trim() || !accountName.value.trim()) {
    errorMessage.value = "Enter an account code and name.";
    return;
  }
  const account = await submit<Account>("/finance/accounts", { code: accountCode.value, name: accountName.value, type: accountType.value }, "Ledger account created.");
  if (account) {
    accounts.value.push(account);
    accounts.value.sort((left, right) => left.code.localeCompare(right.code));
    accountCode.value = "";
    accountName.value = "";
  }
}

async function createPeriod() {
  const period = await submit<Period>("/finance/periods", { name: periodName.value, starts_on: periodStart.value, ends_on: periodEnd.value }, "Fiscal period created.");
  if (period) {
    periods.value.push(period);
    periods.value.sort((left, right) => left.starts_on.localeCompare(right.starts_on));
    periodName.value = "";
    periodStart.value = "";
    periodEnd.value = "";
  }
}

async function closePeriod(period: Period) {
  const updated = await submit<Period>(`/finance/periods/${period.id}/close`, {}, `${period.name} closed.`);
  if (updated) periods.value = periods.value.map((item) => item.id === updated.id ? updated : item);
}

function addJournalLine() {
  journalLines.value.push({ account_id: null, debit: "", credit: "", memo: "" });
}

function removeJournalLine(index: number) {
  if (journalLines.value.length > 2) journalLines.value.splice(index, 1);
}

async function postJournalEntry() {
  if (!journalDate.value || !journalDescription.value.trim() || !journalBalanced.value) {
    errorMessage.value = "Provide an entry date and description, then balance the debit and credit totals.";
    return;
  }
  const payloadLines = [];
  for (const line of journalLines.value) {
    const debit = parseMinor(line.debit);
    const credit = parseMinor(line.credit);
    if (line.account_id === null || debit === null || credit === null || ((debit > 0n) === (credit > 0n))) {
      errorMessage.value = "Each line needs an account and exactly one positive debit or credit amount.";
      return;
    }
    if (debit > BigInt(Number.MAX_SAFE_INTEGER) || credit > BigInt(Number.MAX_SAFE_INTEGER)) {
      errorMessage.value = "Journal line amount is too large.";
      return;
    }
    payloadLines.push({ account_id: line.account_id, debit_minor: Number(debit), credit_minor: Number(credit), memo: line.memo.trim() || journalDescription.value.trim() });
  }
  const entry = await submit<{ entry_number: string }>("/finance/journal-entries", {
    entry_date: journalDate.value,
    description: journalDescription.value,
    currency: journalCurrency.value,
    lines: payloadLines,
  }, "Journal entry posted.");
  if (entry) {
    journalDescription.value = "";
    journalLines.value = [
      { account_id: null, debit: "", credit: "", memo: "" },
      { account_id: null, debit: null as unknown as string, credit: "", memo: "" },
    ];
    await loadTrialBalance();
  }
}

async function loadTrialBalance() {
  const params = new URLSearchParams({ currency: reportCurrency.value });
  if (reportFrom.value) params.set("from", reportFrom.value);
  if (reportTo.value) params.set("to", reportTo.value);
  try {
    trialBalance.value = await request<TrialBalance>(`/finance/reports/trial-balance?${params.toString()}`);
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load trial balance.";
  }
}

async function loadArAp() {
  try {
    arApReport.value = await request<ArApReport>(`/finance/reports/receivables-payables?currency=${reportCurrency.value}`);
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load receivables and payables.";
  }
}

async function createTaxCode() {
  const tax = await submit<TaxCode>("/finance/tax-codes", { code: taxCode.value, name: taxName.value, rate_percent: taxRate.value }, "Tax code configured.");
  if (tax) {
    taxCodes.value.push(tax);
    taxCodes.value.sort((left, right) => left.code.localeCompare(right.code));
    taxCode.value = "";
    taxName.value = "";
    taxRate.value = "";
  }
}

async function createBill() {
  if (billTotal.value === "" || parseMinor(billTotal.value) === null) {
    errorMessage.value = "Enter a valid bill total with at most two decimals.";
    return;
  }
  const bill = await submit<Bill>("/finance/payables", {
    vendor_name: vendorName.value,
    vendor_reference: vendorReference.value,
    currency: billCurrency.value,
    total: billTotal.value,
    bill_date: billDate.value,
    due_date: billDueDate.value || null,
  }, "Vendor bill recorded.");
  if (bill) {
    bills.value.unshift(bill);
    vendorName.value = "";
    vendorReference.value = "";
    billTotal.value = "";
    billDueDate.value = "";
    await loadArAp();
  }
}

async function recordBillPayment(bill: Bill) {
  const amount = paymentAmounts.value[bill.id] ?? "";
  if (parseMinor(amount) === null || parseMinor(amount)! < 1n) {
    errorMessage.value = "Enter a positive payment amount.";
    return;
  }
  const payment = await submit(`/finance/payables/${bill.id}/payments`, {
    amount,
    paid_at: paymentDates.value[bill.id] || new Date().toISOString().slice(0, 10),
    reference: paymentReferences.value[bill.id] || null,
  }, `Payment recorded for ${bill.vendor_name}.`);
  if (payment) {
    await loadFinance();
    await loadArAp();
  }
}
</script>

<template>
  <div class="page-content finance-page">
    <div class="page-heading">
      <div><p class="eyebrow">Finance operations</p><h1>Keep every entry accounted for.</h1></div>
    </div>

    <nav class="sales-tabs finance-tabs" aria-label="Finance sections">
      <button v-for="item in tabs" :key="item" type="button" :class="{ active: tab === item }" :aria-pressed="tab === item" @click="tab = item; errorMessage = ''; noticeMessage = ''">{{ item }}</button>
    </nav>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="noticeMessage" class="sales-notice" role="status">{{ noticeMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading finance records...</h2></div>

    <template v-else-if="tab === 'Ledger'">
      <section class="finance-section">
        <div class="finance-section-heading"><div><p class="card-kicker">Chart of accounts</p><h2>Account structure</h2></div></div>
        <form class="finance-inline-form" @submit.prevent="createAccount">
          <div><label for="account-code">Code</label><input id="account-code" v-model="accountCode" maxlength="32" required /></div>
          <div><label for="account-name">Name</label><input id="account-name" v-model="accountName" maxlength="160" required /></div>
          <div><label for="account-type">Type</label><select id="account-type" v-model="accountType"><option v-for="type in accountTypes" :key="type">{{ type }}</option></select></div>
          <button class="edit-button" type="submit" :disabled="isSaving">Add account</button>
        </form>
        <div v-if="accounts.length" class="finance-table-wrap"><table class="finance-table"><thead><tr><th>Code</th><th>Account</th><th>Type</th><th>State</th></tr></thead><tbody><tr v-for="account in accounts" :key="account.id"><td>{{ account.code }}</td><td>{{ account.name }}</td><td>{{ account.type }}</td><td>{{ account.active ? "Active" : "Inactive" }}</td></tr></tbody></table></div>
        <p v-else class="muted">No ledger accounts configured.</p>
      </section>

      <section class="finance-section">
        <div class="finance-section-heading"><div><p class="card-kicker">Fiscal periods</p><h2>Open posting windows</h2></div></div>
        <form class="finance-inline-form" @submit.prevent="createPeriod">
          <div><label for="period-name">Name</label><input id="period-name" v-model="periodName" maxlength="80" placeholder="FY2026" required /></div>
          <div><label for="period-start">Starts</label><input id="period-start" v-model="periodStart" type="date" required /></div>
          <div><label for="period-end">Ends</label><input id="period-end" v-model="periodEnd" type="date" required /></div>
          <button class="edit-button" type="submit" :disabled="isSaving">Add period</button>
        </form>
        <div v-if="periods.length" class="finance-period-list"><article v-for="period in periods" :key="period.id"><div><strong>{{ period.name }}</strong><span>{{ period.starts_on }} to {{ period.ends_on }}</span></div><button v-if="!period.closed" class="edit-button danger-button" type="button" :disabled="isSaving" @click="closePeriod(period)">Close period</button><span v-else class="finance-closed">Closed</span></article></div>
        <p v-else class="muted">No fiscal periods configured.</p>
      </section>

      <section class="finance-section">
        <div class="finance-section-heading"><div><p class="card-kicker">General ledger</p><h2>Post a balanced journal entry</h2></div></div>
        <form class="finance-journal-form" @submit.prevent="postJournalEntry">
          <div class="finance-inline-form">
            <div><label for="journal-date">Entry date</label><input id="journal-date" v-model="journalDate" type="date" required /></div>
            <div><label for="journal-description">Description</label><input id="journal-description" v-model="journalDescription" maxlength="255" required /></div>
            <div><label for="journal-currency">Currency</label><select id="journal-currency" v-model="journalCurrency"><option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }}</option></select></div>
          </div>
          <div class="finance-journal-lines"><div class="finance-journal-header"><span>Account and memo</span><span>Debit</span><span>Credit</span><span></span></div>
            <div v-for="(line, index) in journalLines" :key="index" class="finance-journal-line">
              <div class="finance-journal-account"><select v-model.number="line.account_id" :aria-label="`Account for journal line ${index + 1}`"><option :value="null" disabled>Select account</option><option v-for="account in accounts.filter((item) => item.active)" :key="account.id" :value="account.id">{{ account.code }} · {{ account.name }}</option></select><input v-model="line.memo" :aria-label="`Memo for journal line ${index + 1}`" placeholder="Line memo" /></div>
              <input v-model="line.debit" inputmode="decimal" :aria-label="`Debit for journal line ${index + 1}`" placeholder="0.00" />
              <input v-model="line.credit" inputmode="decimal" :aria-label="`Credit for journal line ${index + 1}`" placeholder="0.00" />
              <button class="close-button" type="button" :disabled="journalLines.length <= 2" :aria-label="`Remove journal line ${index + 1}`" @click="removeJournalLine(index)">&#10005;</button>
            </div>
          </div>
          <div class="finance-journal-summary"><button class="edit-button" type="button" @click="addJournalLine">Add line</button><span>Debits <strong>{{ formatMinor(journalDebitTotal) }} {{ journalCurrency }}</strong></span><span>Credits <strong>{{ formatMinor(journalCreditTotal) }} {{ journalCurrency }}</strong></span><span :class="journalBalanced ? 'finance-balanced' : 'finance-unbalanced'">{{ journalBalanced ? "Balanced" : "Not balanced" }}</span></div>
          <div class="task-form-actions"><button class="button article-submit" type="submit" :disabled="isSaving || !journalBalanced || accounts.filter((item) => item.active).length < 2">{{ isSaving ? "Posting..." : "Post journal entry" }}</button></div>
        </form>
      </section>

      <section class="finance-section">
        <div class="finance-section-heading"><div><p class="card-kicker">Financial report</p><h2>Trial balance</h2></div></div>
        <form class="finance-report-filters" @submit.prevent="loadTrialBalance"><div><label for="trial-currency">Currency</label><select id="trial-currency" v-model="reportCurrency"><option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }}</option></select></div><div><label for="trial-from">From</label><input id="trial-from" v-model="reportFrom" type="date" /></div><div><label for="trial-to">To</label><input id="trial-to" v-model="reportTo" type="date" /></div><button class="edit-button" type="submit">Run report</button></form>
        <div v-if="trialBalance" class="finance-report-result"><p class="finance-report-basis">{{ trialBalance.basis }} <strong :class="trialBalance.balanced ? 'finance-balanced' : 'finance-unbalanced'">{{ trialBalance.balanced ? "Balanced" : "Out of balance" }}</strong></p><div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>Account</th><th>Debit</th><th>Credit</th><th>Net debit</th></tr></thead><tbody><tr v-for="account in trialBalance.accounts" :key="account.account_id"><td>{{ account.code }} · {{ account.name }}</td><td>{{ account.debit }} {{ reportCurrency }}</td><td>{{ account.credit }} {{ reportCurrency }}</td><td>{{ account.balance }} {{ reportCurrency }}</td></tr><tr class="finance-table-total"><th>Total</th><th>{{ trialBalance.total_debits }} {{ reportCurrency }}</th><th>{{ trialBalance.total_credits }} {{ reportCurrency }}</th><th></th></tr></tbody></table></div></div>
      </section>
    </template>

    <template v-else-if="tab === 'Receivables & payables'">
      <section class="finance-section">
        <div class="finance-section-heading"><div><p class="card-kicker">Accounts payable</p><h2>Record a vendor bill</h2></div></div>
        <form class="finance-inline-form finance-bill-form" @submit.prevent="createBill">
          <div><label for="vendor-name">Vendor</label><input id="vendor-name" v-model="vendorName" maxlength="255" required /></div><div><label for="vendor-reference">Reference</label><input id="vendor-reference" v-model="vendorReference" maxlength="80" required /></div><div><label for="bill-currency">Currency</label><select id="bill-currency" v-model="billCurrency"><option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }}</option></select></div><div><label for="bill-total">Total</label><input id="bill-total" v-model="billTotal" inputmode="decimal" placeholder="0.00" required /></div><div><label for="bill-date">Bill date</label><input id="bill-date" v-model="billDate" type="date" required /></div><div><label for="bill-due">Due date</label><input id="bill-due" v-model="billDueDate" type="date" /></div><button class="edit-button" type="submit" :disabled="isSaving">Record bill</button>
        </form>
      </section>

      <section class="finance-section">
        <div class="finance-section-heading"><div><p class="card-kicker">Operational balances</p><h2>Receivables and payables</h2></div></div>
        <form class="finance-report-filters" @submit.prevent="loadArAp"><div><label for="arap-currency">Currency</label><select id="arap-currency" v-model="reportCurrency"><option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }}</option></select></div><button class="edit-button" type="submit">Refresh balances</button></form>
        <p class="finance-report-basis">{{ arApReport?.basis ?? "Balances are derived from issued invoices, invoice receipts, vendor bills, and bill payments. This is not a GL reconciliation." }}</p>
        <div class="finance-arap-grid">
          <section><div class="finance-section-heading"><div><p class="card-kicker">Accounts receivable</p><h2>{{ arApReport?.receivables_total ?? "0.00" }} {{ reportCurrency }}</h2></div></div><div v-if="arApReport?.receivables.length" class="finance-balance-list"><article v-for="item in arApReport.receivables" :key="item.invoice_id"><div><strong>{{ item.client_name }}</strong><span>{{ item.invoice_number }} · {{ item.due_date ?? "No due date" }}<em v-if="item.overdue">Overdue</em></span></div><strong>{{ item.balance_due }} {{ reportCurrency }}</strong></article></div><p v-else class="muted">No outstanding receivables.</p></section>
          <section><div class="finance-section-heading"><div><p class="card-kicker">Accounts payable</p><h2>{{ arApReport?.payables_total ?? "0.00" }} {{ reportCurrency }}</h2></div></div><div v-if="arApReport?.payables.length" class="finance-balance-list"><article v-for="item in arApReport.payables" :key="item.bill_id"><div><strong>{{ item.vendor_name }}</strong><span>{{ item.vendor_reference }} · {{ item.due_date ?? "No due date" }}<em v-if="item.overdue">Overdue</em></span></div><strong>{{ item.balance_due }} {{ reportCurrency }}</strong></article></div><p v-else class="muted">No outstanding payables.</p></section>
        </div>
      </section>

      <section class="finance-section">
        <div class="finance-section-heading"><div><p class="card-kicker">Vendor bills</p><h2>Payment tracking</h2></div></div>
        <div v-if="bills.length" class="finance-bill-list"><article v-for="bill in bills" :key="bill.id"><div class="finance-bill-heading"><div><p class="card-kicker">{{ bill.vendor_reference }} · {{ bill.status }} · {{ bill.bill_date }}</p><h3>{{ bill.vendor_name }}</h3><span>{{ bill.paid_total }} paid · {{ bill.balance_due }} due · {{ bill.currency }}</span></div><strong>{{ bill.total }} {{ bill.currency }}</strong></div><form v-if="bill.balance_due !== '0.00'" class="finance-inline-form finance-payment-form" @submit.prevent="recordBillPayment(bill)"><div><label :for="`bill-payment-${bill.id}`">Payment amount</label><input :id="`bill-payment-${bill.id}`" v-model="paymentAmounts[bill.id]" inputmode="decimal" placeholder="0.00" required /></div><div><label :for="`bill-payment-date-${bill.id}`">Payment date</label><input :id="`bill-payment-date-${bill.id}`" v-model="paymentDates[bill.id]" type="date" required /></div><div><label :for="`bill-payment-ref-${bill.id}`">Reference</label><input :id="`bill-payment-ref-${bill.id}`" v-model="paymentReferences[bill.id]" maxlength="120" /></div><button class="edit-button" type="submit" :disabled="isSaving">Record payment</button></form></article></div><p v-else class="muted">No vendor bills recorded.</p>
      </section>
    </template>

    <template v-else>
      <section class="finance-section">
        <div class="finance-section-heading"><div><p class="card-kicker">Tax configuration</p><h2>Reference rates</h2></div></div>
        <p class="finance-report-basis">Rates are stored as configuration only. They are not automatically applied to products, quotes, invoices, or filings.</p>
        <form class="finance-inline-form" @submit.prevent="createTaxCode"><div><label for="tax-code">Code</label><input id="tax-code" v-model="taxCode" maxlength="32" required /></div><div><label for="tax-name">Name</label><input id="tax-name" v-model="taxName" maxlength="160" required /></div><div><label for="tax-rate">Rate (%)</label><input id="tax-rate" v-model="taxRate" inputmode="decimal" placeholder="21.00" required /></div><button class="edit-button" type="submit" :disabled="isSaving">Add tax code</button></form>
        <div v-if="taxCodes.length" class="finance-table-wrap"><table class="finance-table"><thead><tr><th>Code</th><th>Name</th><th>Rate</th><th>State</th></tr></thead><tbody><tr v-for="tax in taxCodes" :key="tax.id"><td>{{ tax.code }}</td><td>{{ tax.name }}</td><td>{{ tax.rate_percent }}%</td><td>{{ tax.active ? "Active" : "Inactive" }}</td></tr></tbody></table></div><p v-else class="muted">No tax codes configured.</p>
      </section>
      <section class="finance-section"><div class="finance-section-heading"><div><p class="card-kicker">Currencies</p><h2>Supported transaction currencies</h2></div></div><div class="finance-currency-list"><article v-for="currency in currencies" :key="currency.code"><strong>{{ currency.code }}</strong><span>{{ currency.name }}</span><span>{{ currency.minor_unit }} minor digits</span></article></div><p class="finance-report-basis">Transactions and reports remain in their original currency. Exchange rates, revaluation, and cross-currency consolidation are not implemented.</p></section>
    </template>
  </div>
</template>