<script setup lang="ts">
import { ref } from 'vue'

type Article = { title: string; content: string }

const isFormVisible = ref(false)
const articles = ref<Article[]>([])
const editingIndex = ref<number | null>(null)
const title = ref('')
const content = ref('')

function openCreateForm() {
  editingIndex.value = null
  title.value = ''
  content.value = ''
  isFormVisible.value = true
}

function editArticle(index: number) {
  const article = articles.value[index]
  editingIndex.value = index
  title.value = article.title
  content.value = article.content
  isFormVisible.value = true
}

function saveArticle() {
  const article = { title: title.value.trim(), content: content.value.trim() }
  if (!article.title || !article.content) return

  if (editingIndex.value === null) articles.value.unshift(article)
  else articles.value[editingIndex.value] = article

  isFormVisible.value = false
}
</script>

<template>
  <div class="page-content">
    <div class="page-heading">
      <div><p class="eyebrow">News</p><h1>What is happening.</h1></div>
      <button class="create-button" type="button" @click="openCreateForm">+ <span>Create article</span></button>
    </div>

    <form v-if="isFormVisible" class="article-form" @submit.prevent="saveArticle">
      <div class="article-form-heading"><div><p class="card-kicker">{{ editingIndex === null ? 'New article' : 'Edit article' }}</p><h2>{{ editingIndex === null ? 'Share an update.' : 'Refine your update.' }}</h2></div><button class="close-button" type="button" aria-label="Close article form" @click="isFormVisible = false">&#10005;</button></div>
      <label for="article-title">Title</label>
      <input id="article-title" v-model="title" type="text" placeholder="Article title" required />
      <label for="article-content">Content</label>
      <textarea id="article-content" v-model="content" rows="7" placeholder="Write your article..." required></textarea>
      <button class="button article-submit" type="submit">{{ editingIndex === null ? 'Publish article' : 'Save changes' }} <span aria-hidden="true">&#8594;</span></button>
    </form>

    <div v-else-if="articles.length === 0" class="empty-page"><span class="empty-icon" aria-hidden="true">&#9733;</span><h2>Your news starts here.</h2><p class="muted">Create your first article to keep the team informed.</p></div>
    <div v-else class="article-list"><article v-for="(article, index) in articles" :key="`${article.title}-${index}`" class="article-item"><div><p class="card-kicker">News article</p><h2>{{ article.title }}</h2><p class="muted">{{ article.content }}</p></div><button class="edit-button" type="button" @click="editArticle(index)">Edit</button></article></div>
  </div>
</template>