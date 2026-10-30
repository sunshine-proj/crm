<script setup lang="ts">
import { onMounted, ref } from "vue";
import { setOrganizationDefaultCurrency } from "../lib/organizationSettings";

type Country = { code: string; name: string; default_currency: string };
type Currency = { code: string; name: string };
type SettingsHistoryEntry = {
  id: number;
  previous_country_code: string | null;
  previous_currency: string | null;
  previous_retention_years: number;
  new_country_code: string | null;
  new_currency: string | null;
  new_retention_years: number;
  actor: string;
  changed_at: string;
};
type OrganizationSettings = {
  country_code: string | null;
  country_name: string | null;
  default_currency: string | null;
  retention_years: number;
  updated_at: string | null;
  updated_by: string | null;
  countries: Country[];
  currencies: Currency[];
  deployment_model: string;
  finance_scope: string;
  jurisdiction_profile_status: string;
  tax_rules_status: string;
  can_manage: boolean;
  history: SettingsHistoryEntry[];
};

const settings = ref<OrganizationSettings | null>(null);
const countryCode = ref("");
const defaultCurrency = ref("");
const retentionYears = ref(10);
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref("");
const noticeMessage = ref("");

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
    throw new Error(payload?.error ?? "Unable to complete the settings request.");
  }
  return (await response.json()) as T;
}

async function loadSettings() {
  isLoading.value = true;
  errorMessage.value = "";
  try {
    const loaded = await request<OrganizationSettings>("/settings");
    settings.value = loaded;
    countryCode.value = loaded.country_code ?? "";
    defaultCurrency.value = loaded.default_currency ?? "";
    setOrganizationDefaultCurrency(loaded.default_currency);
    retentionYears.value = loaded.retention_years;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load organization settings.";
  } finally {
    isLoading.value = false;
  }
}

onMounted(loadSettings);

async function saveSettings() {
  if (!countryCode.value || !defaultCurrency.value || retentionYears.value < 1 || retentionYears.value > 100) {
    errorMessage.value = "Choose a country and currency and enter a retention period from 1 to 100 years.";
    return;
  }

  isSaving.value = true;
  errorMessage.value = "";
  noticeMessage.value = "";
  try {
    settings.value = await request<OrganizationSettings>("/settings", {
      method: "PUT",
      body: JSON.stringify({
        country_code: countryCode.value,
        default_currency: defaultCurrency.value,
        retention_years: retentionYears.value,
      }),
    });
    setOrganizationDefaultCurrency(settings.value.default_currency);
    countryCode.value = settings.value.country_code ?? "";
    defaultCurrency.value = settings.value.default_currency ?? "";
    retentionYears.value = settings.value.retention_years;
    noticeMessage.value = "Organization settings saved.";
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to save organization settings.";
  } finally {
    isSaving.value = false;
  }
}

async function restoreSettings(entry: SettingsHistoryEntry) {
  if (!window.confirm("Restore the country, currency, and retention settings from this history entry?")) return;

  isSaving.value = true;
  errorMessage.value = "";
  noticeMessage.value = "";
  try {
    settings.value = await request<OrganizationSettings>(`/settings/history/${entry.id}/restore`, { method: "POST" });
    setOrganizationDefaultCurrency(settings.value.default_currency);
    countryCode.value = settings.value.country_code ?? "";
    defaultCurrency.value = settings.value.default_currency ?? "";
    retentionYears.value = settings.value.retention_years;
    noticeMessage.value = "Previous settings restored. The restoration was added to the audit history.";
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to restore the previous settings.";
  } finally {
    isSaving.value = false;
  }
}

function countryName(code: string | null): string {
  return settings.value?.countries.find((country) => country.code === code)?.name ?? "Not selected";
}

function formatDate(value: string): string {
  return new Date(value).toLocaleString();
}
</script>

<template>
  <div class="page-content settings-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">Organization setup</p>
        <h1>Settings</h1>
      </div>
    </div>

    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <p v-if="noticeMessage" class="settings-notice" role="status">{{ noticeMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading settings...</h2></div>

    <template v-else-if="settings">
      <section class="settings-card" aria-labelledby="operating-settings-heading">
        <div class="settings-card-heading">
          <div>
            <p class="card-kicker">Operating profile</p>
            <h2 id="operating-settings-heading">Country and business defaults</h2>
          </div>
          <span class="settings-status" :class="{ 'settings-status-warning': settings.jurisdiction_profile_status !== 'verified' }">
            {{ settings.jurisdiction_profile_status === "unverified" ? "Country profile unverified" : "Choose a country" }}
          </span>
        </div>
        <p class="muted">
          Country and currency are defaults for new records. Existing invoices, payments, bills, journal entries, and reports are not rewritten when these settings change.
        </p>

        <form class="settings-form" @submit.prevent="saveSettings">
          <label for="operating-country">Country of operation</label>
          <select id="operating-country" v-model="countryCode" :disabled="!settings.can_manage || isSaving" required>
            <option value="" disabled>Select a country</option>
            <option v-for="country in settings.countries" :key="country.code" :value="country.code">
              {{ country.name }}
            </option>
          </select>

          <label for="default-currency">Default currency for new records</label>
          <select id="default-currency" v-model="defaultCurrency" :disabled="!settings.can_manage || isSaving" required>
            <option value="" disabled>Select a currency</option>
            <option v-for="currency in settings.currencies" :key="currency.code" :value="currency.code">
              {{ currency.code }} - {{ currency.name }}
            </option>
          </select>

          <label for="retention-years">Reference retention period (years)</label>
          <input
            id="retention-years"
            v-model.number="retentionYears"
            type="number"
            min="1"
            max="100"
            :disabled="!settings.can_manage || isSaving"
            required
          />
          <p class="settings-help">
            The configured policy is {{ retentionYears }} years. This value does not automatically delete or archive records; the retention start point and jurisdiction-specific legal rules still require review.
          </p>

          <button v-if="settings.can_manage" class="button settings-save" type="submit" :disabled="isSaving">
            {{ isSaving ? "Saving..." : "Save settings" }}
          </button>
          <p v-else class="settings-help">Only an administrator can change organization-wide settings.</p>
        </form>
      </section>

      <section class="settings-card" aria-labelledby="finance-readiness-heading">
        <div class="settings-card-heading">
          <div>
            <p class="card-kicker">Readiness and controls</p>
            <h2 id="finance-readiness-heading">Current operating boundaries</h2>
          </div>
        </div>
        <dl class="settings-facts">
          <div><dt>Deployment model</dt><dd>One organization per installation</dd></div>
          <div><dt>Tax rules</dt><dd>Not configured for {{ countryName(settings.country_code) }}</dd></div>
          <div><dt>Finance scope</dt><dd>Operational records only; not statutory accounting</dd></div>
          <div><dt>Finance permissions</dt><dd>Existing application roles; finance-specific roles are not configured</dd></div>
          <div><dt>Backup policy</dt><dd>Daily encrypted off-host database and document backups; 35-day retention; quarterly restore tests; 24-hour RPO/RTO</dd></div>
        </dl>
        <p class="settings-warning" role="note">
          Country selection does not enable country-specific tax calculation, compliant invoice issuance, or statutory reporting. Backup automation, off-host storage, and restore testing are not configured by this application. This installation is not tenant-isolated and must not serve multiple organizations.
        </p>
      </section>

      <section v-if="settings.can_manage" class="settings-card" aria-labelledby="settings-history-heading">
        <div class="settings-card-heading">
          <div>
            <p class="card-kicker">Audit and recovery</p>
            <h2 id="settings-history-heading">Settings change history</h2>
          </div>
        </div>
        <p v-if="settings.history.length === 0" class="muted">No organization settings changes have been recorded.</p>
        <ol v-else class="settings-history">
          <li v-for="entry in settings.history" :key="entry.id">
            <div>
              <strong>{{ countryName(entry.new_country_code) }} · {{ entry.new_currency ?? "No currency" }} · {{ entry.new_retention_years }} years</strong>
              <span>{{ formatDate(entry.changed_at) }} by {{ entry.actor }}</span>
            </div>
            <button class="settings-restore" type="button" :disabled="isSaving" @click="restoreSettings(entry)">
              Restore previous
            </button>
          </li>
        </ol>
      </section>
    </template>
  </div>
</template>
