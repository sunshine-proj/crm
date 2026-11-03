<script setup lang="ts">
import { onMounted, ref } from "vue";
import { apiRequest, errorText } from "../lib/api";
import { isAdminToken } from "../lib/session";

type Validation = {
  id: number; country_code: string; scope: "tax" | "numbering"; adviser_name: string; adviser_firm: string | null; reference: string;
  validated_on: string; rounding: string | null; notes: string | null; recorded_by: string; active: boolean;
};
type Country = {
  code: string; name: string; tax_name: string; authority: string; seed_standard_rate: string; tax_code: string; tax_code_exists: boolean;
  tax_validation: Validation | null; numbering_validation: Validation | null;
};
type Overview = {
  organization_country: string | null; tax_active: boolean; tax_rounding: string | null; numbering_active: boolean;
  legal_profile_missing: string[]; countries: Country[]; invoice_content_checklist: string[]; basis: string;
};
type LegalProfile = { legal_name: string | null; registration_number: string | null; vat_number: string | null; address: string | null; email: string | null; payment_instructions: string | null };

const overview = ref<Overview | null>(null);
const profile = ref<LegalProfile>({ legal_name: "", registration_number: "", vat_number: "", address: "", email: "", payment_instructions: "" });
const isAdmin = isAdminToken(localStorage.getItem("crm_token"));
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref("");
const noticeMessage = ref("");
const form = ref({ scope: "tax" as "tax" | "numbering", adviser_name: "", adviser_firm: "", reference: "", validated_on: new Date().toISOString().slice(0, 10), rounding: "per_rate_group", notes: "" });

async function load() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    overview.value = await apiRequest<Overview>("/jurisdiction");
    const loaded = await apiRequest<LegalProfile>("/jurisdiction/legal-profile");
    profile.value = Object.fromEntries(Object.entries(loaded).map(([key, value]) => [key, value ?? ""])) as unknown as LegalProfile;
  } catch (error) {
    errorMessage.value = errorText(error, "Unable to load jurisdiction settings.");
  } finally {
    isLoading.value = false;
  }
}

async function run(action: () => Promise<unknown>, success: string) {
  isSaving.value = true;
  errorMessage.value = "";
  noticeMessage.value = "";
  try {
    await action();
    noticeMessage.value = success;
    await load();
  } catch (error) {
    errorMessage.value = errorText(error, "The request failed.");
  } finally {
    isSaving.value = false;
  }
}

function recordValidation() {
  if (!overview.value?.organization_country) return;
  const body = { ...form.value, country_code: overview.value.organization_country, rounding: form.value.scope === "tax" ? form.value.rounding : null };
  return run(() => apiRequest("/jurisdiction/validations", { method: "POST", body: JSON.stringify(body) }), "Sign-off recorded.");
}

function revoke(validation: Validation) {
  const reason = window.prompt("Why is this sign-off being revoked? Tax or numbering rules for this country switch off immediately.");
  if (!reason?.trim()) return;
  return run(() => apiRequest(`/jurisdiction/validations/${validation.id}/revoke`, { method: "POST", body: JSON.stringify({ reason }) }), "Sign-off revoked.");
}

const createStandardCode = () => run(() => apiRequest("/jurisdiction/standard-tax-code", { method: "POST" }), "Standard tax code created.");
const saveProfile = () => run(() => apiRequest("/jurisdiction/legal-profile", { method: "PUT", body: JSON.stringify(profile.value) }), "Organisation details saved.");

onMounted(load);
</script>

<template>
  <section class="settings-card" aria-labelledby="jurisdiction-heading">
    <div class="settings-card-heading"><div><p class="card-kicker">Tax and invoicing rules</p><h2 id="jurisdiction-heading">Jurisdiction</h2></div></div>
    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="noticeMessage" class="settings-notice" role="status">{{ noticeMessage }}</p>
    <p v-if="isLoading" class="muted">Loading...</p>

    <template v-else-if="overview">
      <p class="muted">{{ overview.basis }}</p>
      <dl class="settings-summary">
        <div><dt>Country</dt><dd>{{ overview.organization_country ?? "Not selected" }}</dd></div>
        <div><dt>Tax on invoices</dt><dd>{{ overview.tax_active ? `On (rounding: ${overview.tax_rounding?.replace("_", " ")})` : "Off: no validated sign-off" }}</dd></div>
        <div><dt>Sequential numbering</dt><dd>{{ overview.numbering_active ? "On (gapless)" : "Off: no validated sign-off" }}</dd></div>
      </dl>
      <p v-if="overview.legal_profile_missing.length" class="error">Before invoices can be issued under validated rules, provide {{ overview.legal_profile_missing.join(", ") }}.</p>

      <h3>Supported countries</h3>
      <ul class="detail-list">
        <li v-for="country in overview.countries" :key="country.code">
          <strong>{{ country.code }} · {{ country.name }}</strong> <span class="muted">{{ country.tax_name }}, seed standard rate {{ country.seed_standard_rate }}% (to be confirmed) · {{ country.authority }}</span>
          <div v-for="scope in ['tax_validation', 'numbering_validation'] as const" :key="scope">
            <span class="status-pill">{{ scope === "tax_validation" ? "Tax" : "Numbering" }}</span>
            <template v-if="country[scope]">
              signed off by {{ country[scope]!.adviser_name }}<template v-if="country[scope]!.adviser_firm"> ({{ country[scope]!.adviser_firm }})</template>, {{ country[scope]!.validated_on }}, ref. {{ country[scope]!.reference }}
              <button v-if="isAdmin" class="edit-button danger-button" type="button" :disabled="isSaving" @click="revoke(country[scope]!)">Revoke</button>
            </template>
            <span v-else class="muted">no sign-off recorded</span>
          </div>
        </li>
      </ul>

      <template v-if="isAdmin && overview.organization_country">
        <h3>Record an adviser's sign-off for {{ overview.organization_country }}</h3>
        <p class="muted">Record this only after a qualified accountant or tax adviser has confirmed the rules. The application stores who vouched for them; it cannot check their advice.</p>
        <form class="settings-form" @submit.prevent="recordValidation">
          <label for="jv-scope">Scope</label>
          <select id="jv-scope" v-model="form.scope"><option value="tax">Tax on invoices (rates, rounding)</option><option value="numbering">Invoice and credit-note numbering</option></select>
          <template v-if="form.scope === 'tax'">
            <label for="jv-rounding">Rounding confirmed by the adviser</label>
            <select id="jv-rounding" v-model="form.rounding"><option value="per_rate_group">Once per tax rate on the invoice</option><option value="per_line">On every line</option></select>
          </template>
          <label for="jv-adviser">Adviser name</label><input id="jv-adviser" v-model="form.adviser_name" maxlength="160" required />
          <label for="jv-firm">Firm (optional)</label><input id="jv-firm" v-model="form.adviser_firm" maxlength="160" />
          <label for="jv-reference">Opinion or engagement reference</label><input id="jv-reference" v-model="form.reference" maxlength="255" required />
          <label for="jv-date">Date of the confirmation</label><input id="jv-date" v-model="form.validated_on" type="date" required />
          <label for="jv-notes">Notes (optional)</label><textarea id="jv-notes" v-model="form.notes" rows="2" maxlength="1000"></textarea>
          <button class="button" type="submit" :disabled="isSaving">Record sign-off</button>
        </form>
        <button v-if="!overview.countries.find((c) => c.code === overview!.organization_country)?.tax_code_exists" class="edit-button" type="button" :disabled="isSaving" @click="createStandardCode">Create the standard tax code ({{ overview.countries.find((c) => c.code === overview!.organization_country)?.tax_code }})</button>
      </template>
      <p v-else-if="isAdmin" class="muted">Select the organisation country above to record sign-offs.</p>

      <h3>Issuing organisation</h3>
      <p class="muted">These details are printed on invoices and credit notes and frozen on each document when it is issued.</p>
      <form v-if="isAdmin" class="settings-form" @submit.prevent="saveProfile">
        <label for="lp-name">Legal name</label><input id="lp-name" v-model="profile.legal_name" maxlength="255" />
        <label for="lp-reg">Registration number</label><input id="lp-reg" v-model="profile.registration_number" maxlength="64" />
        <label for="lp-vat">VAT number</label><input id="lp-vat" v-model="profile.vat_number" maxlength="32" />
        <label for="lp-address">Address</label><textarea id="lp-address" v-model="profile.address" rows="3" maxlength="2000"></textarea>
        <label for="lp-email">Contact e-mail</label><input id="lp-email" v-model="profile.email" type="email" maxlength="180" />
        <label for="lp-pay">Payment instructions</label><textarea id="lp-pay" v-model="profile.payment_instructions" rows="3" maxlength="2000"></textarea>
        <button class="button" type="submit" :disabled="isSaving">Save organisation details</button>
      </form>
      <dl v-else class="settings-summary"><div><dt>Legal name</dt><dd>{{ profile.legal_name || "—" }}</dd></div><div><dt>VAT number</dt><dd>{{ profile.vat_number || "—" }}</dd></div></dl>

      <h3>Invoice content checklist for your adviser</h3>
      <ul class="detail-list"><li v-for="item in overview.invoice_content_checklist" :key="item">{{ item }}</li></ul>
    </template>
  </section>
</template>
