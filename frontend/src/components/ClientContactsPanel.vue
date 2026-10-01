<script setup lang="ts">
import { ref } from "vue";

const props = defineProps<{ clientId: number }>();

type ClientContact = {
  id: number;
  client_id: number;
  name: string;
  job_title: string | null;
  email: string | null;
  phone: string | null;
};

type ClientContactInput = Pick<ClientContact, "name" | "job_title" | "email" | "phone">;

const contacts = ref<ClientContact[]>([]);
const isOpen = ref(false);
const isLoaded = ref(false);
const isLoading = ref(false);
const isSaving = ref(false);
const deletingId = ref<number | null>(null);
const editingId = ref<number | null>(null);
const isFormVisible = ref(false);
const name = ref("");
const jobTitle = ref("");
const email = ref("");
const phone = ref("");
const errorMessage = ref("");

async function request<T>(path = "", options: RequestInit = {}): Promise<T> {
  const token = localStorage.getItem("crm_token");
  const response = await fetch(`/api/clients/${props.clientId}/contacts${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });

  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: string } | null;
    throw new Error(payload?.error ?? "Unable to complete the contact request.");
  }

  return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}

async function loadContacts() {
  errorMessage.value = "";
  isLoading.value = true;

  try {
    contacts.value = await request<ClientContact[]>();
    isLoaded.value = true;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to load contacts.";
  } finally {
    isLoading.value = false;
  }
}

async function toggleContacts() {
  isOpen.value = !isOpen.value;
  if (isOpen.value && !isLoaded.value) await loadContacts();
}

function openCreateForm() {
  editingId.value = null;
  name.value = "";
  jobTitle.value = "";
  email.value = "";
  phone.value = "";
  errorMessage.value = "";
  isFormVisible.value = true;
}

function openEditForm(contact: ClientContact) {
  editingId.value = contact.id;
  name.value = contact.name;
  jobTitle.value = contact.job_title ?? "";
  email.value = contact.email ?? "";
  phone.value = contact.phone ?? "";
  errorMessage.value = "";
  isFormVisible.value = true;
}

function closeForm() {
  if (!isSaving.value && deletingId.value === null) isFormVisible.value = false;
}

async function saveContact() {
  const contact: ClientContactInput = {
    name: name.value.trim(),
    job_title: jobTitle.value.trim() || null,
    email: email.value.trim() || null,
    phone: phone.value.trim() || null,
  };
  if (!contact.name) {
    errorMessage.value = "Enter a contact name.";
    return;
  }

  errorMessage.value = "";
  isSaving.value = true;
  try {
    const savedContact = await request<ClientContact>(editingId.value === null ? "" : `/${editingId.value}`, {
      method: editingId.value === null ? "POST" : "PUT",
      body: JSON.stringify(contact),
    });
    if (editingId.value === null) contacts.value.push(savedContact);
    else {
      const index = contacts.value.findIndex((item) => item.id === editingId.value);
      if (index !== -1) contacts.value[index] = savedContact;
    }
    isFormVisible.value = false;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to save the contact.";
  } finally {
    isSaving.value = false;
  }
}

async function deleteContact(contact: ClientContact) {
  if (!window.confirm(`Delete contact "${contact.name}"?`)) return;

  errorMessage.value = "";
  deletingId.value = contact.id;
  try {
    await request(`/${contact.id}`, { method: "DELETE" });
    contacts.value = contacts.value.filter((item) => item.id !== contact.id);
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : "Unable to delete the contact.";
  } finally {
    deletingId.value = null;
  }
}
</script>

<template>
  <section class="contact-panel" :aria-label="`Contacts for client ${clientId}`">
    <button class="edit-button" type="button" :aria-expanded="isOpen" @click="toggleContacts">
      {{ isOpen ? "Hide contacts" : "Manage contacts" }}
    </button>

    <div v-if="isOpen" class="contact-panel-content">
      <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
      <p v-if="isLoading" class="muted">Loading contacts...</p>

      <form v-else-if="isFormVisible" class="article-form contact-form" @submit.prevent="saveContact">
        <div class="article-form-heading">
          <div>
            <p class="card-kicker">{{ editingId === null ? "New contact" : "Edit contact" }}</p>
            <h3>{{ editingId === null ? "Add a client contact." : "Update contact details." }}</h3>
          </div>
          <button class="close-button" type="button" aria-label="Close contact form" @click="closeForm">&#10005;</button>
        </div>

        <label :for="`contact-name-${clientId}`">Name</label>
        <input :id="`contact-name-${clientId}`" v-model="name" type="text" maxlength="255" required />

        <label :for="`contact-role-${clientId}`">Role</label>
        <input :id="`contact-role-${clientId}`" v-model="jobTitle" type="text" maxlength="150" />

        <label :for="`contact-email-${clientId}`">Email</label>
        <input :id="`contact-email-${clientId}`" v-model="email" type="email" maxlength="180" />

        <label :for="`contact-phone-${clientId}`">Phone</label>
        <input :id="`contact-phone-${clientId}`" v-model="phone" type="tel" maxlength="80" />

        <div class="task-form-actions">
          <button class="button article-submit" type="submit" :disabled="isSaving">
            {{ isSaving ? "Saving..." : "Save contact" }}
          </button>
          <button class="edit-button" type="button" :disabled="isSaving" @click="closeForm">Cancel</button>
        </div>
      </form>

      <div v-else>
        <button class="edit-button" type="button" @click="openCreateForm">Add contact</button>
        <p v-if="!isLoading && contacts.length === 0" class="muted">No contacts added yet.</p>
        <ul v-else class="contact-list">
          <li v-for="contact in contacts" :key="contact.id" class="contact-row">
            <div>
              <strong>{{ contact.name }}</strong>
              <span v-if="contact.job_title" class="muted"> · {{ contact.job_title }}</span>
              <p v-if="contact.email || contact.phone" class="muted">
                {{ [contact.email, contact.phone].filter(Boolean).join(" · ") }}
              </p>
            </div>
            <div class="article-actions">
              <button class="edit-button" type="button" @click="openEditForm(contact)">Edit</button>
              <button class="edit-button" type="button" :disabled="deletingId === contact.id" @click="deleteContact(contact)">
                {{ deletingId === contact.id ? "Deleting..." : "Delete" }}
              </button>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </section>
</template>