<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import { api } from '../api/client.js'
import { useSession } from '../stores/session.js'
import { useToasts } from '../stores/toasts.js'
import { avatarColor, initials, fullDate } from '../format.js'
import { t } from '../i18n/index.js'

/**
 * Address book: saved contacts, favourites, and every address Postvak collected
 * while sending. Add/edit/delete, and vCard import/export.
 */
const router = useRouter()
const session = useSession()
const toasts = useToasts()

const filter = ref('saved') // saved | favorites | all
const contacts = ref([])
const loading = ref(false)
const query = ref('')
const currentId = ref(null) // id, 'new' or null
const editing = ref(false)
const form = ref({ name: '', email: '', phone: '', notes: '', favorite: false })
const saving = ref(false)
const menuOpen = ref(false)
const fileInput = ref(null)
const nameInput = ref(null)

async function load() {
  loading.value = true
  try {
    contacts.value = await api.get('/contacts/list', { saved: filter.value === 'all' ? undefined : 1 })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(filter, (f, old) => {
  // Favourites is a view on the saved contacts: no reload needed between those two.
  if (f === 'all' || old === 'all') load()
})

const normalize = (s) => (s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const label = (c) => c.name || c.email

const visible = computed(() => {
  const q = normalize(query.value.trim())
  return contacts.value.filter((c) => {
    if (filter.value === 'favorites' && !c.favorite) return false
    if (!q) return true
    return normalize(`${c.name} ${c.email} ${c.phone}`).includes(q)
  })
})

/** Alphabetical groups: A, B, …, # for the rest. */
const groups = computed(() => {
  const map = new Map()
  for (const c of visible.value) {
    const first = normalize(label(c)).charAt(0).toUpperCase()
    const key = /[A-Z]/.test(first) ? first : '#'
    if (!map.has(key)) map.set(key, [])
    map.get(key).push(c)
  }
  return [...map.entries()].sort(([a], [b]) => (a === '#') - (b === '#') || a.localeCompare(b))
})

const current = computed(() => (currentId.value === 'new' ? null : contacts.value.find((c) => c.id === currentId.value) ?? null))
const detailOpen = computed(() => currentId.value !== null)

function select(c) {
  currentId.value = c.id
  editing.value = false
}

function startNew() {
  currentId.value = 'new'
  form.value = { name: '', email: '', phone: '', notes: '', favorite: false }
  editing.value = true
  nextTick(() => nameInput.value?.focus())
}

function startEdit() {
  const c = current.value
  if (!c) return
  form.value = { name: c.name, email: c.email, phone: c.phone, notes: c.notes, favorite: c.favorite }
  editing.value = true
  nextTick(() => nameInput.value?.focus())
}

function cancelEdit() {
  editing.value = false
  if (currentId.value === 'new') currentId.value = null
}

function closeDetail() {
  currentId.value = null
  editing.value = false
}

function upsertLocal(contact) {
  const i = contacts.value.findIndex((c) => c.id === contact.id)
  if (i === -1) contacts.value.push(contact)
  else contacts.value[i] = contact
  contacts.value.sort((a, b) => normalize(label(a)).localeCompare(normalize(label(b))))
}

function errorText(e) {
  return e.status === 409 ? t('contacts.duplicate') : t('toast.failed', { msg: e.message })
}

async function save() {
  saving.value = true
  try {
    // A contact from a phone may have no e-mail address; leave it out then.
    const body = { ...form.value }
    if (currentId.value !== 'new' && !body.email.trim()) delete body.email
    const contact = currentId.value === 'new'
      ? await api.post('/contacts', body)
      : await api.patch(`/contacts/${currentId.value}`, body)
    upsertLocal(contact)
    currentId.value = contact.id
    editing.value = false
    toasts.show(t('contacts.saved'))
  } catch (e) {
    toasts.error(errorText(e))
  } finally {
    saving.value = false
  }
}

/** Turn an automatically collected address into a saved contact. */
async function keep(c) {
  try {
    upsertLocal(await api.patch(`/contacts/${c.id}`, { name: c.name }))
    toasts.show(t('contacts.saved'))
  } catch (e) {
    toasts.error(errorText(e))
  }
}

async function toggleFavorite(c) {
  try {
    upsertLocal(await api.patch(`/contacts/${c.id}`, { favorite: !c.favorite }))
  } catch (e) {
    toasts.error(errorText(e))
  }
}

async function remove(c) {
  if (!confirm(t('contacts.confirmDelete', { name: label(c) }))) return
  try {
    await api.del(`/contacts/${c.id}`)
    contacts.value = contacts.value.filter((x) => x.id !== c.id)
    closeDetail()
    toasts.show(t('contacts.deleted'))
  } catch (e) {
    toasts.error(errorText(e))
  }
}

function mailTo(c) {
  const unified = session.accounts.length > 1 && session.settings.unified_inbox !== false
  const accountId = unified ? 'all' : session.loginAccount?.id ?? ''
  router.push({ path: `/mail/${accountId}`, query: { compose: c.email, name: c.name || undefined } })
}

// ---- import / export
async function importFile(e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  if (!file) return
  const data = new FormData()
  data.append('file', file)
  try {
    const res = await api.upload('/contacts/import', data)
    toasts.show(t(res.skipped ? 'contacts.importedSkipped' : 'contacts.imported', res), { seconds: 6 })
    if (filter.value === 'favorites') filter.value = 'saved'
    await load()
  } catch (err) {
    toasts.error(t('toast.failed', { msg: err.message }))
  }
}

function exportUrl(all) {
  return `${import.meta.env.BASE_URL}api/contacts/export${all ? '?all=1' : ''}`.replace(/\/{2,}/g, '/')
}

const countLabel = computed(() => (visible.value.length === 1 ? t('contacts.countOne') : t('contacts.count', { n: visible.value.length })))
</script>

<template>
  <div class="pt-safe pb-safe flex h-full bg-canvas">
    <!-- List -->
    <section
      class="flex min-h-0 w-full flex-col bg-surface lg:w-[400px] lg:shrink-0 lg:border-r lg:border-line"
      :class="detailOpen ? 'hidden lg:flex' : 'flex'"
    >
      <header class="flex items-center gap-1 border-b border-line px-2 py-2">
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('actions.back')" @click="router.push('/')">
          <AppIcon name="back" />
        </button>
        <h1 class="min-w-0 flex-1 truncate px-1 text-lg font-semibold">{{ t('contacts.title') }}</h1>
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('contacts.new')" @click="startNew">
          <AppIcon name="userPlus" />
        </button>
        <div class="relative">
          <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('contacts.import')" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen">
            <AppIcon name="more" />
          </button>
          <div v-if="menuOpen" class="fixed inset-0 z-20" @click="menuOpen = false" />
          <div v-if="menuOpen" class="absolute right-0 z-30 mt-1 w-64 overflow-hidden rounded-xl bg-surface py-1 shadow-lg ring-1 ring-line" role="menu">
            <button role="menuitem" class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-sunken" @click="menuOpen = false; fileInput.click()">
              <AppIcon name="upload" :size="18" class="text-muted" /> {{ t('contacts.import') }}
            </button>
            <a role="menuitem" :href="exportUrl(false)" download class="flex w-full items-center gap-3 px-4 py-2.5 text-sm hover:bg-sunken" @click="menuOpen = false">
              <AppIcon name="download" :size="18" class="text-muted" /> {{ t('contacts.export') }}
            </a>
            <a role="menuitem" :href="exportUrl(true)" download class="flex w-full items-center gap-3 px-4 py-2.5 text-sm hover:bg-sunken" @click="menuOpen = false">
              <AppIcon name="download" :size="18" class="text-muted" /> {{ t('contacts.exportAll') }}
            </a>
          </div>
        </div>
        <input ref="fileInput" type="file" accept=".vcf,text/vcard,text/x-vcard" class="hidden" @change="importFile" />
      </header>

      <div class="space-y-2 border-b border-line px-3 py-2">
        <input
          v-model="query"
          type="search"
          class="w-full rounded-lg bg-sunken px-3 py-2 outline-none placeholder:text-muted"
          :placeholder="t('contacts.search')"
        />
        <div class="flex flex-wrap gap-1.5" role="tablist">
          <button
            v-for="f in ['saved', 'favorites', 'all']"
            :key="f"
            role="tab"
            :aria-selected="filter === f"
            class="shrink-0 rounded-full px-3 py-1 text-sm"
            :class="filter === f ? 'bg-ink font-medium text-canvas' : 'text-muted hover:bg-sunken'"
            @click="filter = f"
          >{{ t(`contacts.filter${f[0].toUpperCase()}${f.slice(1)}`) }}</button>
        </div>
        <p v-if="filter === 'all'" class="text-xs text-muted">{{ t('contacts.allHint') }}</p>
      </div>

      <div class="min-h-0 flex-1 overflow-y-auto">
        <div v-if="loading && !contacts.length" class="space-y-px">
          <div v-for="i in 6" :key="i" class="flex items-center gap-3 px-4 py-3">
            <div class="size-10 animate-pulse rounded-full bg-sunken" />
            <div class="h-3 w-1/2 animate-pulse rounded bg-sunken" />
          </div>
        </div>
        <div v-else-if="!visible.length" class="px-6 py-14 text-center text-muted">
          <p>{{ query ? t('contacts.emptySearch') : t('contacts.empty') }}</p>
          <p v-if="!query && filter !== 'all'" class="mx-auto mt-3 max-w-xs text-sm">{{ t('contacts.importHelp') }}</p>
        </div>
        <template v-else>
          <section v-for="[letter, list] in groups" :key="letter">
            <h2 class="sticky top-0 z-10 bg-surface/95 px-4 pt-3 pb-1 text-xs font-semibold text-muted backdrop-blur">{{ letter }}</h2>
            <ul>
              <li v-for="c in list" :key="c.id">
                <button
                  class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition"
                  :class="c.id === currentId ? 'bg-accent-soft' : 'hover:bg-sunken/60'"
                  @click="select(c)"
                >
                  <span class="flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white" :style="{ background: avatarColor(c.email || c.name) }">
                    {{ initials(c) }}
                  </span>
                  <span class="min-w-0 flex-1">
                    <span class="block truncate">{{ label(c) }}</span>
                    <span v-if="c.name" class="block truncate text-sm text-muted">{{ c.email }}</span>
                  </span>
                  <span v-if="!c.saved" class="shrink-0 rounded-full bg-sunken px-2 py-0.5 text-xs text-muted">{{ t('contacts.auto') }}</span>
                  <AppIcon v-if="c.favorite" name="flag" :size="16" class="shrink-0 fill-current text-flag" />
                </button>
              </li>
            </ul>
          </section>
          <p class="px-4 py-4 text-center text-xs text-muted">{{ countLabel }}</p>
        </template>
      </div>
    </section>

    <!-- Detail / edit -->
    <section class="min-h-0 flex-1 flex-col overflow-y-auto bg-surface lg:flex" :class="detailOpen ? 'flex' : 'hidden'">
      <header class="flex items-center gap-1 border-b border-line px-2 py-2 lg:border-transparent">
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink lg:hidden" :aria-label="t('actions.back')" @click="closeDetail">
          <AppIcon name="back" />
        </button>
        <div class="flex-1" />
        <template v-if="current && !editing">
          <button
            class="rounded-lg p-2 hover:bg-sunken"
            :class="current.favorite ? 'text-flag' : 'text-muted hover:text-ink'"
            :title="t('contacts.favorite')"
            @click="toggleFavorite(current)"
          >
            <AppIcon name="flag" :class="current.favorite ? 'fill-current' : ''" />
          </button>
          <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('contacts.edit')" @click="startEdit">
            <AppIcon name="drafts" />
          </button>
          <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-danger" :title="t('contacts.delete')" @click="remove(current)">
            <AppIcon name="trash" />
          </button>
        </template>
      </header>

      <!-- Edit form -->
      <form v-if="editing" class="mx-auto w-full max-w-lg space-y-4 px-5 py-4" @submit.prevent="save">
        <h2 class="text-xl font-semibold">{{ currentId === 'new' ? t('contacts.new') : t('contacts.edit') }}</h2>
        <label class="block">
          <span class="mb-1 block text-sm text-muted">{{ t('contacts.name') }}</span>
          <input ref="nameInput" v-model="form.name" maxlength="255" class="w-full rounded-lg border border-line bg-surface px-3 py-2 outline-none focus:border-accent" />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm text-muted">{{ t('contacts.email') }}</span>
          <input v-model="form.email" type="email" :required="currentId === 'new' || !!current?.email" maxlength="255" class="w-full rounded-lg border border-line bg-surface px-3 py-2 outline-none focus:border-accent" />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm text-muted">{{ t('contacts.phone') }}</span>
          <input v-model="form.phone" type="tel" maxlength="64" class="w-full rounded-lg border border-line bg-surface px-3 py-2 outline-none focus:border-accent" />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm text-muted">{{ t('contacts.notes') }}</span>
          <textarea v-model="form.notes" rows="4" maxlength="5000" class="w-full rounded-lg border border-line bg-surface px-3 py-2 outline-none focus:border-accent" />
        </label>
        <label class="flex items-center gap-2">
          <input v-model="form.favorite" type="checkbox" class="size-4 accent-[var(--color-accent)]" />
          {{ t('contacts.favorite') }}
        </label>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="rounded-lg px-4 py-2 text-muted hover:bg-sunken" @click="cancelEdit">{{ t('contacts.cancel') }}</button>
          <button class="rounded-lg bg-accent px-4 py-2 font-medium text-white disabled:opacity-60" :disabled="saving || (currentId === 'new' && !form.email.trim())">{{ t('contacts.save') }}</button>
        </div>
      </form>

      <!-- View -->
      <div v-else-if="current" class="mx-auto w-full max-w-lg px-5 py-4">
        <div class="flex flex-col items-center gap-3 text-center">
          <span class="flex size-20 items-center justify-center rounded-full text-2xl font-semibold text-white" :style="{ background: avatarColor(current.email || current.name) }">
            {{ initials(current) }}
          </span>
          <div class="min-w-0">
            <h2 class="text-xl font-semibold break-words">{{ label(current) }}</h2>
            <p v-if="current.name" class="text-muted break-all">{{ current.email }}</p>
          </div>
          <div class="flex flex-wrap justify-center gap-2">
            <button v-if="current.email" class="flex items-center gap-2 rounded-full bg-accent px-4 py-2 text-sm font-medium text-white" @click="mailTo(current)">
              <AppIcon name="send" :size="17" /> {{ t('contacts.mail') }}
            </button>
            <a v-if="current.phone" :href="`tel:${current.phone.replace(/[^0-9+]/g, '')}`" class="flex items-center gap-2 rounded-full px-4 py-2 text-sm font-medium ring-1 ring-line hover:bg-sunken">
              <AppIcon name="phone" :size="17" /> {{ current.phone }}
            </a>
            <button v-if="!current.saved" class="flex items-center gap-2 rounded-full px-4 py-2 text-sm font-medium ring-1 ring-line hover:bg-sunken" @click="keep(current)">
              <AppIcon name="userPlus" :size="17" /> {{ t('contacts.saveAuto') }}
            </button>
          </div>
        </div>

        <dl class="mt-6 divide-y divide-line rounded-2xl ring-1 ring-line">
          <div v-if="current.email" class="px-4 py-3">
            <dt class="text-sm text-muted">{{ t('contacts.email') }}</dt>
            <dd class="break-all">{{ current.email }}</dd>
          </div>
          <div v-if="current.other_emails?.length" class="px-4 py-3">
            <dt class="text-sm text-muted">{{ t('contacts.otherEmails') }}</dt>
            <dd v-for="e in current.other_emails" :key="e" class="break-all">{{ e }}</dd>
          </div>
          <div v-if="current.phone" class="px-4 py-3">
            <dt class="text-sm text-muted">{{ t('contacts.phone') }}</dt>
            <dd>{{ current.phone }}</dd>
          </div>
          <div v-if="current.notes" class="px-4 py-3">
            <dt class="text-sm text-muted">{{ t('contacts.notes') }}</dt>
            <dd class="whitespace-pre-line break-words">{{ current.notes }}</dd>
          </div>
        </dl>
        <p class="mt-3 text-center text-xs text-muted">
          {{ current.times_used ? t('contacts.used', { n: current.times_used, date: fullDate(current.last_used_at) }) : t('contacts.usedNever') }}
        </p>
      </div>

      <div v-else class="m-auto max-w-xs px-5 text-center text-muted">
        <p>{{ t('contacts.select') }}</p>
        <p class="mt-4 text-sm">
          <AppIcon name="phone" :size="15" class="mr-1 inline align-[-2px]" />{{ t('contacts.syncHint') }}
          <RouterLink to="/settings/calendar" class="text-accent">{{ t('contacts.syncLink') }}</RouterLink>
        </p>
      </div>
    </section>
  </div>
</template>
