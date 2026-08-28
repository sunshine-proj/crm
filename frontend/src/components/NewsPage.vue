<script setup lang="ts">
import { onMounted, ref } from 'vue'

type Article = { id: number; title: string; content: string; created_at: string; updated_at: string }
type ArticleInput = Pick<Article, 'title' | 'content'>

const isFormVisible = ref(false)
const articles = ref<Article[]>([])
const editingId = ref<number | null>(null)
const title = ref('')
const content = ref('')
const errorMessage = ref('')
const isLoading = ref(true)
const isSaving = ref(false)
const deletingId = ref<number | null>(null)

function formatArticleDate(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

function wasArticleUpdated(article: Article) {
  return new Date(article.updated_at).getTime() > new Date(article.created_at).getTime()
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = localStorage.getItem('crm_token')
  const response = await fetch(`/api/news${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  })

  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: string } | null
    throw new Error(payload?.error ?? 'Unable to complete the news request.')
  }

  return response.status === 204 ? (undefined as T) : (await response.json() as T)
}

async function loadArticles() {
  errorMessage.value = ''
  isLoading.value = true

  try {
    articles.value = await request<Article[]>('')
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Unable to load news articles.'
  } finally {
    isLoading.value = false
  }
}

onMounted(loadArticles)

function openCreateForm() {
  editingId.value = null
  title.value = ''
  content.value = ''
  errorMessage.value = ''
  isFormVisible.value = true
}

function editArticle(article: Article) {
  editingId.value = article.id
  title.value = article.title
  content.value = article.content
  errorMessage.value = ''
  isFormVisible.value = true
}

async function saveArticle() {
  const article: ArticleInput = { title: title.value.trim(), content: content.value.trim() }
  if (!article.title || !article.content) return

  errorMessage.value = ''
  isSaving.value = true

  try {
    const savedArticle = await request<Article>(editingId.value === null ? '' : `/${editingId.value}`, {
      method: editingId.value === null ? 'POST' : 'PUT',
      body: JSON.stringify(article),
    })

    if (editingId.value === null) articles.value.unshift(savedArticle)
    else {
      const articleIndex = articles.value.findIndex((item) => item.id === editingId.value)
      if (articleIndex !== -1) articles.value[articleIndex] = savedArticle
    }

    isFormVisible.value = false
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Unable to save the article.'
  } finally {
    isSaving.value = false
  }
}

async function deleteArticle(article: Article) {
  if (!window.confirm(`Delete "${article.title}"?`)) return

  errorMessage.value = ''
  deletingId.value = article.id

  try {
    await request(`/${article.id}`, { method: 'DELETE' })
    articles.value = articles.value.filter((item) => item.id !== article.id)
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Unable to delete the article.'
  } finally {
    deletingId.value = null
  }
}
</script>

<template>
  <div class="page-content">
    <div class="page-heading">
      <div><p class="eyebrow">News</p><h1>What is happening.</h1></div>
      <button class="create-button" type="button" @click="openCreateForm">+ <span>Create article</span></button>
    </div>

    <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <div v-if="isLoading" class="empty-page"><h2>Loading news...</h2></div>
    <form v-else-if="isFormVisible" class="article-form" @submit.prevent="saveArticle">
      <div class="article-form-heading"><div><p class="card-kicker">{{ editingId === null ? 'New article' : 'Edit article' }}</p><h2>{{ editingId === null ? 'Share an update.' : 'Refine your update.' }}</h2></div><button class="close-button" type="button" aria-label="Close article form" @click="isFormVisible = false">&#10005;</button></div>
      <label for="article-title">Title</label>
      <input id="article-title" v-model="title" type="text" placeholder="Article title" required />
      <label for="article-content">Content</label>
      <textarea id="article-content" v-model="content" rows="7" placeholder="Write your article..." required></textarea>
      <button class="button article-submit" type="submit" :disabled="isSaving">{{ isSaving ? 'Saving...' : editingId === null ? 'Publish article' : 'Save changes' }} <span aria-hidden="true">&#8594;</span></button>
    </form>

    <div v-else-if="articles.length === 0" class="empty-page"><span class="empty-icon" aria-hidden="true">&#9733;</span><h2>Your news starts here.</h2><p class="muted">Create your first article to keep the team informed.</p></div>
    <div v-else class="article-list"><article v-for="article in articles" :key="article.id" class="article-item"><div><p class="card-kicker">{{ formatArticleDate(article.created_at) }}<span v-if="wasArticleUpdated(article)"> (updated {{ formatArticleDate(article.updated_at) }})</span></p><h2>{{ article.title }}</h2><p class="muted">{{ article.content }}</p></div><div class="article-actions"><button class="edit-button" type="button" @click="editArticle(article)">Edit</button><button class="edit-button" type="button" :disabled="deletingId === article.id" @click="deleteArticle(article)">{{ deletingId === article.id ? 'Deleting...' : 'Delete' }}</button></div></article></div>
  </div>
</template>