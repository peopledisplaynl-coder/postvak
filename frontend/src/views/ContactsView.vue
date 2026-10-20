<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import ContactEditor from '../components/ContactEditor.vue'
import { api } from '../api/client.js'
import { contactInitials, workLine, addressLines, mapsUrl, birthdayText, daysToBirthday, searchText } from '../contacts.js'
import { useSession } from '../stores/session.js'
import { useToasts } from '../stores/toasts.js'
import { avatarColor, fullDate } from '../format.js'
import { t } from '../i18n/index.js'

/**
 * Address book: saved contacts, favourites, and every address Postvak collected
 * while sending. Add/edit/delete, and vCard import/export.
 */
const router = useRouter()
const route = useRoute()
const session = useSession()
const toasts = useToasts()

const filter = ref('saved') // saved | favorites | all
const contacts = ref([])
const loading = ref(false)
const query = ref('')
const currentId = ref(null) // id, 'new' or null
const editing = ref(false)
const saving = ref(false)
const menuOpen = ref(false)
const fileInput = ref(null)
const group = ref(null) // show only this group

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
onMounted(async () => {
  await load()
  // /contacts?id=12 (from a birthday in the calendar) opens that contact.
  const id = Number(route.query.id)
  if (id && contacts.value.some((c) => c.id === id)) currentId.value = id
})
watch(filter, (f, old) => {
  // Favourites is a view on the saved contacts: no reload needed between those two.
  if (f === 'all' || old === 'all') load()
})

const normalize = (s) => (s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const label = (c) => c.name || c.email

/** All groups in use (Google calls them labels), for the filter chips and the editor. */
const allGroups = computed(() => {
  const seen = new Map()
  for (const c of contacts.value) for (const g of c.details?.groups ?? []) if (!seen.has(g.toLowerCase())) seen.set(g.toLowerCase(), g)
  return [...seen.values()].sort((a, b) => a.localeCompare(b))
})
watch(allGroups, (list) => { if (group.value && !list.includes(group.value)) group.value = null })

const visible = computed(() => {
  const q = normalize(query.value.trim())
  return contacts.value.filter((c) => {
    if (filter.value === 'favorites' && !c.favorite) return false
    if (group.value && !(c.details?.groups ?? []).includes(group.value)) return false
    if (!q) return true
    return normalize(searchText(c)).includes(q)
  })
})

/** Birthdays in the coming week (shown at the top of the saved contacts). */
const soonBirthdays = computed(() => {
  if (query.value || group.value || filter.value === 'all') return []
  return visible.value
    .map((c) => ({ c, days: daysToBirthday(c.details?.birthday) }))
    .filter((x) => x.days !== null && x.days <= 7)
    .sort((a, b) => a.days - b.days)
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
  editing.value = true
}

function startEdit() {
  if (current.value) editing.value = true
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

/** Save from the editor: the details, then the photo (new, removed or unchanged). */
async function save({ details, notes, favorite, photo }) {
  saving.value = true
  try {
    const body = { details, notes, favorite }
    let contact = currentId.value === 'new'
      ? await api.post('/contacts', body)
      : await api.patch(`/contacts/${currentId.value}`, body)
    if (photo instanceof Blob) {
      const form = new FormData()
      form.append('file', photo, 'photo.jpg')
      contact = await api.upload(`/contacts/${contact.id}/photo`, form)
    } else if (photo === null) {
      contact = await api.del(`/contacts/${contact.id}/photo`)
    }
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

function mailTo(c, email = c.email) {
  const unified = session.accounts.length > 1 && session.settings.unified_inbox !== false
  const accountId = unified ? 'all' : session.loginAccount?.id ?? ''
  router.push({ path: `/mail/${accountId}`, query: { compose: email, name: c.name || undefined } })
}

const telHref = (n) => `tel:${String(n).replace(/[^0-9+]/g, '')}`
const typeLabel = (type) => t(`contacts.types.${type}`)

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
        <div v-if="allGroups.length" class="flex flex-wrap gap-1.5" role="group" :aria-label="t('contacts.groups')">
          <button
            v-for="g in allGroups" :key="g"
            class="flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs ring-1 transition"
            :class="group === g ? 'bg-accent-soft font-medium text-accent ring-accent/40' : 'text-muted ring-line hover:bg-sunken'"
            :aria-pressed="group === g"
            @click="group = group === g ? null : g"
          ><AppIcon name="tag" :size="12" />{{ g }}</button>
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
          <section v-if="soonBirthdays.length" class="border-b border-line">
            <h2 class="px-4 pt-3 pb-1 text-xs font-semibold text-muted">{{ t('contacts.birthdaysSoon') }}</h2>
            <button
              v-for="{ c, days } in soonBirthdays" :key="'b' + c.id"
              class="flex w-full items-center gap-3 px-4 py-2 text-left hover:bg-sunken/60"
              @click="select(c)"
            >
              <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-flag/15 text-flag"><AppIcon name="calendar" :size="16" /></span>
              <span class="min-w-0 flex-1 truncate">{{ label(c) }}</span>
              <span class="shrink-0 text-sm text-muted">{{ days === 0 ? t('contacts.birthdayToday') : days === 1 ? t('contacts.birthdayTomorrow') : t('contacts.birthdayIn', { n: days }) }}</span>
            </button>
          </section>
          <section v-for="[letter, list] in groups" :key="letter">
            <h2 class="sticky top-0 z-10 bg-surface/95 px-4 pt-3 pb-1 text-xs font-semibold text-muted backdrop-blur">{{ letter }}</h2>
            <ul>
              <li v-for="c in list" :key="c.id">
                <button
                  class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition"
                  :class="c.id === currentId ? 'bg-accent-soft' : 'hover:bg-sunken/60'"
                  @click="select(c)"
                >
                  <span class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-full text-sm font-semibold text-white" :style="{ background: avatarColor(c.email || c.name) }">
                    <img v-if="c.photo_url" :src="c.photo_url" alt="" loading="lazy" class="size-full object-cover" />
                    <template v-else>{{ contactInitials(c) }}</template>
                  </span>
                  <span class="min-w-0 flex-1">
                    <span class="block truncate">{{ label(c) || c.phone }}</span>
                    <span v-if="workLine(c.details) || (c.name && c.email)" class="block truncate text-sm text-muted">{{ workLine(c.details) || c.email }}</span>
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
      <ContactEditor
        v-if="editing"
        :key="currentId"
        :contact="current"
        :groups="allGroups"
        :saving="saving"
        @save="save"
        @cancel="cancelEdit"
      />

      <!-- View -->
      <div v-else-if="current" class="mx-auto w-full max-w-xl px-5 py-4">
        <div class="flex flex-col items-center gap-3 text-center">
          <span class="flex size-28 items-center justify-center overflow-hidden rounded-full text-4xl font-semibold text-white" :style="{ background: avatarColor(current.email || current.name) }">
            <img v-if="current.photo_url" :src="current.photo_url" alt="" class="size-full object-cover" />
            <template v-else>{{ contactInitials(current) }}</template>
          </span>
          <div class="min-w-0">
            <h2 class="text-2xl font-semibold break-words">{{ label(current) || current.phone }}</h2>
            <p v-if="current.details?.nickname" class="text-muted">“{{ current.details.nickname }}”</p>
            <p v-if="workLine(current.details)" class="text-muted">{{ workLine(current.details) }}</p>
          </div>
          <div v-if="current.details?.groups?.length" class="flex flex-wrap justify-center gap-1.5">
            <button v-for="g in current.details.groups" :key="g" class="flex items-center gap-1 rounded-full bg-accent-soft px-2.5 py-0.5 text-xs text-accent" @click="group = g; closeDetail()">
              <AppIcon name="tag" :size="12" />{{ g }}
            </button>
          </div>
          <div class="flex flex-wrap justify-center gap-2">
            <button v-if="current.email" class="flex items-center gap-2 rounded-full bg-accent px-4 py-2 text-sm font-medium text-white" @click="mailTo(current)">
              <AppIcon name="send" :size="17" /> {{ t('contacts.mail') }}
            </button>
            <a v-if="current.phone" :href="telHref(current.phone)" class="flex items-center gap-2 rounded-full px-4 py-2 text-sm font-medium ring-1 ring-line hover:bg-sunken">
              <AppIcon name="phone" :size="17" /> {{ t('contacts.call') }}
            </a>
            <button v-if="current.saved" class="flex items-center gap-2 rounded-full px-4 py-2 text-sm font-medium ring-1 ring-line hover:bg-sunken" @click="startEdit">
              <AppIcon name="drafts" :size="17" /> {{ t('contacts.edit') }}
            </button>
            <button v-else class="flex items-center gap-2 rounded-full px-4 py-2 text-sm font-medium ring-1 ring-line hover:bg-sunken" @click="keep(current)">
              <AppIcon name="userPlus" :size="17" /> {{ t('contacts.saveAuto') }}
            </button>
          </div>
        </div>

        <!-- Contact details, like Google Contacts -->
        <section class="mt-6 rounded-2xl ring-1 ring-line">
          <h3 class="px-4 pt-3 pb-1 font-medium">{{ t('contacts.contactInfo') }}</h3>
          <ul class="pb-2">
            <li v-for="(e, i) in current.details?.emails ?? []" :key="'e' + i" class="flex items-center gap-3 px-4 py-2">
              <AppIcon name="mail" :size="18" class="shrink-0 text-muted" />
              <span class="min-w-0 flex-1">
                <button class="block max-w-full truncate text-left text-accent hover:underline" @click="mailTo(current, e.value)">{{ e.value }}</button>
                <span class="text-xs text-muted">{{ typeLabel(e.type) }}</span>
              </span>
            </li>
            <li v-for="(p, i) in current.details?.phones ?? []" :key="'p' + i" class="flex items-center gap-3 px-4 py-2">
              <AppIcon name="phone" :size="18" class="shrink-0 text-muted" />
              <span class="min-w-0 flex-1">
                <a :href="telHref(p.value)" class="block text-accent hover:underline">{{ p.value }}</a>
                <span class="text-xs text-muted">{{ typeLabel(p.type) }}</span>
              </span>
            </li>
            <li v-for="(a, i) in current.details?.addresses ?? []" :key="'a' + i" class="flex items-start gap-3 px-4 py-2">
              <AppIcon name="pin" :size="18" class="mt-0.5 shrink-0 text-muted" />
              <span class="min-w-0 flex-1">
                <span v-for="line in addressLines(a)" :key="line" class="block whitespace-pre-line">{{ line }}</span>
                <span class="text-xs text-muted">{{ typeLabel(a.type) }} · <a :href="mapsUrl(a)" target="_blank" rel="noopener" class="text-accent hover:underline">{{ t('contacts.onMap') }}</a></span>
              </span>
            </li>
            <li v-if="current.details?.birthday" class="flex items-center gap-3 px-4 py-2">
              <AppIcon name="calendar" :size="18" class="shrink-0 text-muted" />
              <span class="min-w-0 flex-1">
                <span class="block">{{ birthdayText(current.details.birthday) }}</span>
                <span class="text-xs text-muted">{{ t('contacts.birthday') }}</span>
              </span>
            </li>
            <li v-for="(w, i) in current.details?.websites ?? []" :key="'w' + i" class="flex items-center gap-3 px-4 py-2">
              <AppIcon name="globe" :size="18" class="shrink-0 text-muted" />
              <a :href="w.value" target="_blank" rel="noopener" class="min-w-0 flex-1 truncate text-accent hover:underline">{{ w.value.replace(/^https?:\/\//, '') }}</a>
            </li>
            <li v-if="!(current.details?.emails?.length || current.details?.phones?.length || current.details?.addresses?.length || current.details?.birthday || current.details?.websites?.length)" class="px-4 py-2 text-sm text-muted">
              {{ t('contacts.noInfo') }}
            </li>
          </ul>
        </section>

        <section v-if="current.notes" class="mt-4 rounded-2xl px-4 py-3 ring-1 ring-line">
          <h3 class="mb-1 font-medium">{{ t('contacts.notes') }}</h3>
          <p class="whitespace-pre-line break-words">{{ current.notes }}</p>
        </section>

        <p class="mt-3 text-center text-xs text-muted">
          {{ current.times_used ? t('contacts.used', { n: current.times_used, date: fullDate(current.last_used_at) }) : t('contacts.usedNever') }}
          <template v-if="current.synced"> · {{ t('contacts.syncedPhone') }}</template>
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
