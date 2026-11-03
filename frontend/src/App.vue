<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { isFinanceToken } from "./lib/session";
import DashboardPage from "./components/DashboardPage.vue";
import NewsPage from "./components/NewsPage.vue";
import ClientsPage from "./components/ClientsPage.vue";
import ProjectsPage from "./components/ProjectsPage.vue";
import ProjectManagementPage from "./components/ProjectManagementPage.vue";
import TasksPage from "./components/TasksPage.vue";
import InvoicesPage from "./components/InvoicesPage.vue";
import SalesPage from "./components/SalesPage.vue";
import FinancePage from "./components/FinancePage.vue";
import DealsPage from "./components/DealsPage.vue";
import BankPage from "./components/BankPage.vue";
import ClientDetailPage from "./components/ClientDetailPage.vue";
import ProjectDetailPage from "./components/ProjectDetailPage.vue";
import NotificationCenter from "./components/NotificationCenter.vue";
import { parseDetailRoute, sectionForRoute } from "./lib/route";
import InventoryPage from "./components/InventoryPage.vue";
import OperationsPage from "./components/OperationsPage.vue";
import SettingsPage from "./components/SettingsPage.vue";
import { setOrganizationDefaultCurrency } from "./lib/organizationSettings";

type LoginResponse = {
  token: string;
  user: { email: string };
};

const email = ref("");
const password = ref("");
const token = ref(localStorage.getItem("crm_token"));
const userEmail = ref(localStorage.getItem("crm_user_email"));
const errorMessage = ref("");
const isSubmitting = ref(false);
const activeSection = ref("Dashboard");
const detailRoute = ref(parseDetailRoute(window.location.hash));
window.addEventListener("hashchange", () => {
  detailRoute.value = parseDetailRoute(window.location.hash);
  if (detailRoute.value) activeSection.value = sectionForRoute(detailRoute.value);
});
if (detailRoute.value) activeSection.value = sectionForRoute(detailRoute.value);

function openSection(section: string) {
  activeSection.value = section;
  if (window.location.hash) window.history.replaceState(null, "", window.location.pathname + window.location.search);
  detailRoute.value = null;
}
const organizationSettingsReady = ref(false);
const organizationSettingsError = ref("");
const allSections = ["Dashboard", "News", "Clients", "Deals", "Projects", "Project Management", "Tasks", "Sales", "Inventory", "Operations", "Invoices", "Finance", "Bank", "Settings"];
const isFinance = computed(() => isFinanceToken(token.value));
const sections = computed(() => allSections.filter((section) => (section !== "Finance" && section !== "Bank") || isFinance.value));
const pageComponents = {
  Dashboard: DashboardPage,
  News: NewsPage,
  Clients: ClientsPage,
  Projects: ProjectsPage,
  "Project Management": ProjectManagementPage,
  Deals: DealsPage,
  Tasks: TasksPage,
  Sales: SalesPage,
  Invoices: InvoicesPage,
  Finance: FinancePage,
  Bank: BankPage,
  Inventory: InventoryPage,
  Operations: OperationsPage,
  Settings: SettingsPage,
};

function hasValidToken(value: string | null) {
  if (!value) return false;

  try {
    const payload = JSON.parse(atob(value.split(".")[1])) as { exp?: number };
    return !payload.exp || payload.exp * 1000 > Date.now();
  } catch {
    return false;
  }
}

const isSignedIn = computed(() => hasValidToken(token.value));

watch(isSignedIn, () => {
  void loadOrganizationSettings();
}, { immediate: true });

async function loadOrganizationSettings() {
  organizationSettingsReady.value = false;
  organizationSettingsError.value = "";
  if (!isSignedIn.value) {
    organizationSettingsReady.value = true;
    return;
  }

  try {
    const response = await fetch("/api/settings", {
      headers: { Authorization: `Bearer ${token.value}` },
    });
    if (!response.ok) {
      throw new Error("Unable to load organization defaults. Existing currency defaults will be used.");
    }
    const settings = (await response.json()) as { default_currency?: string | null };
    setOrganizationDefaultCurrency(settings.default_currency ?? null);
  } catch (error) {
    organizationSettingsError.value = error instanceof Error ? error.message : "Unable to load organization defaults.";
  } finally {
    organizationSettingsReady.value = true;
  }
}

async function submitLogin() {
  errorMessage.value = "";
  isSubmitting.value = true;

  try {
    const response = await fetch("/api/login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email: email.value.trim(), password: password.value }),
    });

    const payload = (await response.json()) as Partial<LoginResponse> & { error?: string };
    if (!response.ok || !payload.token || !payload.user) {
      throw new Error(payload.error ?? "Unable to sign in. Please check your details.");
    }

    localStorage.setItem("crm_token", payload.token);
    localStorage.setItem("crm_user_email", payload.user.email);
    token.value = payload.token;
    userEmail.value = payload.user.email;
    password.value = "";
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to connect to the API.";
  } finally {
    isSubmitting.value = false;
  }
}

function signOut() {
  localStorage.removeItem("crm_token");
  localStorage.removeItem("crm_user_email");
  setOrganizationDefaultCurrency(null);
  token.value = null;
  userEmail.value = null;
  activeSection.value = "Dashboard";
}
</script>

<template>
  <main v-if="isSignedIn" class="app-shell">
    <header class="top-panel">
      <div class="brand-lockup">
        <div class="brand-mark" aria-hidden="true">M</div>
        <span>My CRM</span>
      </div>
      <div class="account-area">
        <NotificationCenter />
        <span class="account-email">{{ userEmail }}</span>
        <button class="sign-out" type="button" @click="signOut">Sign out</button>
      </div>
    </header>

    <aside class="left-panel" aria-label="CRM sections">
      <p class="nav-label">Workspace</p>
      <nav>
        <button
          v-for="section in sections"
          :key="section"
          class="nav-item"
          :class="{ active: activeSection === section }"
          type="button"
          @click="openSection(section)"
        >
          <span class="nav-icon" aria-hidden="true">{{
            section === "Dashboard"
              ? "&#9632;"
              : section === "News"
                ? "&#9733;"
                : section === "Clients"
                  ? "&#9673;"
                  : section === "Deals"
                    ? "&#9670;"
                  : section === "Projects"
                    ? "&#9633;"
                    : section === "Project Management"
                      ? "&#9776;"
                    : section === "Tasks"
                      ? "&#10003;"
                      : section === "Sales"
                        ? "&#8644;"
                        : section === "Finance"
                          ? "&#9636;"
                          : section === "Bank"
                            ? "&#8962;"
                          : section === "Inventory"
                            ? "&#9638;"
                            : section === "Operations"
                              ? "&#9881;"
                                : section === "Settings"
                                  ? "&#9881;"
                            : "&#36;"
          }}</span>
          {{ section }}
        </button>
      </nav>
      <p class="nav-footnote">Private workspace<br />JWT secured</p>
    </aside>

    <section class="workspace" aria-live="polite">
      <p v-if="organizationSettingsError" class="error" role="alert">{{ organizationSettingsError }}</p>
      <div v-if="!organizationSettingsReady" class="empty-page"><h2>Loading organization settings...</h2></div>
      <ClientDetailPage v-else-if="detailRoute?.kind === 'client'" :client-id="detailRoute.id" :key="`client-${detailRoute.id}`" />
      <ProjectDetailPage v-else-if="detailRoute?.kind === 'project'" :project-id="detailRoute.id" :key="`project-${detailRoute.id}`" />
      <component v-else :is="pageComponents[activeSection as keyof typeof pageComponents]" />
    </section>
  </main>

  <main v-else class="login-shell">
    <header class="top-panel login-top-panel">
      <div class="brand-lockup">
        <div class="brand-mark" aria-hidden="true">M</div>
        <span>My CRM</span>
      </div>
      <span class="top-caption">Relationship intelligence, made clear.</span>
    </header>
    <section class="form-panel" aria-labelledby="login-title">
      <form class="login-form" @submit.prevent="submitLogin">
        <p class="eyebrow">Member access</p>
        <h2 id="login-title">Sign in to your workspace</h2>
        <p class="muted">Enter your details to continue.</p>

        <div class="fields">
          <label for="email">Email address</label>
          <input
            id="email"
            v-model="email"
            name="email"
            type="email"
            autocomplete="email"
            placeholder="you@company.com"
            required
          />
          <label for="password">Password</label>
          <input
            id="password"
            v-model="password"
            name="password"
            type="password"
            autocomplete="current-password"
            placeholder="Enter your password"
            required
          />
        </div>

        <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
        <button class="button" type="submit" :disabled="isSubmitting">
          {{ isSubmitting ? "Signing in..." : "Continue" }}
          <span aria-hidden="true">&#8594;</span>
        </button>
        <p class="security-note"><span aria-hidden="true">&#9670;</span> Secure access powered by JWT</p>
      </form>
    </section>
  </main>
</template>
