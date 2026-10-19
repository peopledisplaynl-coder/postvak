<script setup>
import { ref, onMounted } from 'vue'
import AppIcon from './AppIcon.vue'
import { api } from '../api/client.js'
import { useToasts } from '../stores/toasts.js'
import { fileSize } from '../format.js'
import { t, locale } from '../i18n/index.js'

/** Settings → Privacy: large files shared as a download link (copy the link, remove early). */
const toasts = useToasts()
const files = ref([])
const days = ref(30)

async function load() {
  try {
    const data = await api.get('/files')
    files.value = data.files ?? []
    days.value = data.days ?? 30
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}
onMounted(load)

const date = (iso) => new Date(iso).toLocaleDateString(locale(), { day: 'numeric', month: 'short' })
const fullUrl = (f) => new URL(f.url, location.origin).href

async function copy(f) {
  try {
    await navigator.clipboard.writeText(fullUrl(f))
    toasts.show(t('bigFiles.copied'))
  } catch {
    prompt(t('bigFiles.copy'), fullUrl(f))
  }
}

async function remove(f) {
  if (!confirm(t('bigFiles.confirmRemove', { name: f.name }))) return
  try {
    await api.del(`/files/${f.token}`)
    files.value = files.value.filter((x) => x.token !== f.token)
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}
</script>

<template>
  <section>
    <h2 class="font-medium">{{ t('bigFiles.title') }}</h2>
    <p class="mt-1 text-sm text-muted">{{ t('bigFiles.intro', { days }) }}</p>
    <ul class="mt-3 divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
      <li v-for="f in files" :key="f.token" class="flex items-center gap-3 px-4 py-2.5">
        <AppIcon name="link" :size="16" class="shrink-0 text-muted" />
        <span class="min-w-0 flex-1">
          <a :href="f.url" target="_blank" rel="noopener" class="block truncate hover:underline">{{ f.name }}</a>
          <span class="block text-xs text-muted">{{ fileSize(f.size) }} · {{ t('bigFiles.downloads', { n: f.downloads }) }} · {{ t('bigFiles.expires', { date: date(f.expires_at) }) }}</span>
        </span>
        <button class="shrink-0 rounded-lg p-1.5 text-muted hover:bg-sunken hover:text-ink" :title="t('bigFiles.copy')" @click="copy(f)"><AppIcon name="copy" :size="17" /></button>
        <button class="shrink-0 rounded-lg p-1.5 text-muted hover:bg-sunken hover:text-danger" :title="t('bigFiles.remove')" @click="remove(f)"><AppIcon name="trash" :size="17" /></button>
      </li>
      <li v-if="!files.length" class="px-4 py-3 text-sm text-muted">{{ t('bigFiles.empty') }}</li>
    </ul>
  </section>
</template>
