<script setup lang="ts">
import { computed, ref } from "vue";
import DashboardPage from "./components/DashboardPage.vue";
import NewsPage from "./components/NewsPage.vue";
import ClientsPage from "./components/ClientsPage.vue";
import ProjectsPage from "./components/ProjectsPage.vue";
import TasksPage from "./components/TasksPage.vue";
import InvoicesPage from "./components/InvoicesPage.vue";
import SalesPage from "./components/SalesPage.vue";

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
const sections = ["Dashboard", "News", "Clients", "Projects", "Tasks", "Sales", "Invoices"];
const pageComponents = {
  Dashboard: DashboardPage,
  News: NewsPage,
  Clients: ClientsPage,
  Projects: ProjectsPage,
  Tasks: TasksPage,
  Sales: SalesPage,
  Invoices: InvoicesPage,
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
          @click="activeSection = section"
        >
          <span class="nav-icon" aria-hidden="true">{{
            section === "Dashboard"
              ? "&#9632;"
              : section === "News"
                ? "&#9733;"
                : section === "Clients"
                  ? "&#9673;"
                  : section === "Projects"
                    ? "&#9633;"
                    : section === "Tasks"
                      ? "&#10003;"
                      : section === "Sales"
                        ? "&#8644;"
                        : "&#36;"
          }}</span>
          {{ section }}
        </button>
      </nav>
      <p class="nav-footnote">Private workspace<br />JWT secured</p>
    </aside>

    <section class="workspace" aria-live="polite">
      <component :is="pageComponents[activeSection as keyof typeof pageComponents]" />
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
