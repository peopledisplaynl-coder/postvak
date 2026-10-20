<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import { api } from '../api/client.js'
import { useSession } from '../stores/session.js'
import { useToasts } from '../stores/toasts.js'
import { listDate, fileSize, displayName } from '../format.js'
import { folderLabel } from '../folders.js'
import { t } from '../i18n/index.js'

/**
 * All attachments in a folder at a glance (newest first), like Gmail's "has:attachment" view but per file.
 * Names, types and sizes come from the server's BODYSTRUCTURE, so nothing is downloaded until a message is opened.
 */
const router = useRouter()
const session = useSession()
const toasts = useToasts()

const accountId = ref(session.loginAccount?.id ?? session.accounts[0]?.id ?? null)
const folders = ref([])
const folder = ref('INBOX')
const query = ref('')
const kind = ref('all') // all | documents | images | other
const items = ref([])
const total = ref(0)
const page = ref(1)
const loading = ref(false)

const KINDS = ['all', 'documents', 'images', 'other']
const DOCUMENT = /pdf|word|excel|sheet|presentation|powerpoint|opendocument|text\/|rtf|csv|msword|officedocument/i

function kindOf(mime) {
  if (mime.startsWith('image/')) return 'images'
  return DOCUMENT.test(mime) ? 'documents' : 'other'
}
function iconOf(mime) {
  return mime.startsWith('image/') ? 'image' : mime === 'message/rfc822' ? 'mail' : 'paperclip'
}

async function loadFolders() {
  if (!accountId.value) return
  try {
    folders.value = await api.get(`/accounts/${accountId.value}/folders`, { counts: 0 })
    if (!folders.value.some((f) => f.path === folder.value)) folder.value = folders.value.find((f) => f.role === 'inbox')?.path ?? 'INBOX'
  } catch {
    folders.value = []
  }
}

let seq = 0
async function load(more = false) {
  if (!accountId.value) return
  const mine = ++seq
  loading.value = true
  try {
    const next = more ? page.value + 1 : 1
    const res = await api.get(`/accounts/${accountId.value}/attachments`, { folder: folder.value, page: next, q: query.value.trim() || undefined })
    if (mine !== seq) return
    items.value = more ? [...items.value, ...res.items] : res.items
    total.value = res.total
    page.value = next
  } catch (e) {
    if (mine === seq) toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    if (mine === seq) loading.value = false
  }
}

onMounted(async () => {
  await loadFolders()
  load()
})
watch(accountId, async () => {
  await loadFolders()
  load()
})
watch(folder, () => load())
let timer = null
watch(query, () => {
  clearTimeout(timer)
  timer = setTimeout(() => load(), 350)
})

/** One row per file. */
const rows = computed(() => items.value.flatMap((item) =>
  item.attachments
    .filter((a) => kind.value === 'all' || kindOf(a.mime) === kind.value)
    .map((a, i) => ({ key: `${item.message.uid}-${i}`, file: a, message: item.message }))))
const hasMore = computed(() => page.value * 25 < total.value)

function open(row) {
  const m = row.message
  router.push({ path: `/mail/${m.account_id}`, query: { folder: m.folder, uid: m.uid, acc: m.account_id } })
}
</script>

<template>
  <div class="pt-safe pb-safe flex h-full flex-col bg-surface">
    <header class="flex items-center gap-1 border-b border-line px-2 py-2">
      <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('actions.back')" @click="router.push('/')">
        <AppIcon name="back" />
      </button>
      <h1 class="flex-1 px-1 text-lg font-semibold">{{ t('attachmentsView.title') }}</h1>
    </header>

    <div class="mx-auto flex min-h-0 w-full max-w-3xl flex-1 flex-col">
      <div class="flex flex-wrap items-center gap-2 px-3 pt-3">
        <select v-if="session.accounts.length > 1" v-model="accountId" class="rounded-lg bg-sunken px-3 py-2 text-sm" :aria-label="t('attachmentsView.account')">
          <option v-for="a in session.accounts" :key="a.id" :value="a.id">{{ a.name || a.email }}</option>
        </select>
        <select v-model="folder" class="max-w-[12rem] rounded-lg bg-sunken px-3 py-2 text-sm" :aria-label="t('attachmentsView.folder')">
          <option v-for="f in folders" :key="f.path" :value="f.path">{{ folderLabel(f) }}</option>
          <option v-if="!folders.length" value="INBOX">{{ t('folders.inbox') }}</option>
        </select>
        <label class="flex min-w-0 flex-1 items-center gap-2 rounded-lg bg-sunken px-3 py-2">
          <AppIcon name="search" :size="16" class="shrink-0 text-muted" />
          <input v-model="query" type="search" :placeholder="t('attachmentsView.search')" class="min-w-0 flex-1 bg-transparent text-sm outline-none" />
        </label>
      </div>
      <div class="flex gap-1 overflow-x-auto px-3 py-3" role="tablist">
        <button
          v-for="k in KINDS" :key="k" role="tab" :aria-selected="kind === k"
          class="shrink-0 rounded-full px-3 py-1 text-sm ring-1 ring-line"
          :class="kind === k ? 'bg-ink text-surface ring-ink' : ''"
          @click="kind = k"
        >{{ t(`attachmentsView.kinds.${k}`) }}</button>
      </div>

      <ul class="min-h-0 flex-1 divide-y divide-line overflow-y-auto">
        <li v-for="row in rows" :key="row.key">
          <button class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-sunken" @click="open(row)">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-sunken text-muted">
              <AppIcon :name="iconOf(row.file.mime)" :size="20" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="block truncate font-medium">{{ row.file.name }}</span>
              <span class="block truncate text-sm text-muted">{{ displayName(row.message.from) }} · {{ row.message.subject || t('list.noSubject') }}</span>
            </span>
            <span class="shrink-0 text-right text-xs text-muted tabular-nums">
              <span class="block">{{ listDate(row.message.date) }}</span>
              <span class="block">{{ fileSize(row.file.size) }}</span>
            </span>
          </button>
        </li>
        <li v-if="!loading && !rows.length" class="px-4 py-12 text-center text-muted">{{ t('attachmentsView.empty') }}</li>
        <li v-if="loading" class="px-4 py-6 text-center text-muted">{{ t('attachmentsView.loading') }}</li>
        <li v-else-if="hasMore" class="px-4 py-3 text-center">
          <button class="rounded-xl px-4 py-2 text-sm text-accent ring-1 ring-line hover:bg-sunken" @click="load(true)">{{ t('attachmentsView.more') }}</button>
        </li>
      </ul>
    </div>
  </div>
</template>
