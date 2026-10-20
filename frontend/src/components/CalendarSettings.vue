<script setup>
import { ref, computed, onMounted } from 'vue'
import AppIcon from './AppIcon.vue'
import { api } from '../api/client.js'
import { useToasts } from '../stores/toasts.js'
import { useSession } from '../stores/session.js'
import { fullDate } from '../format.js'
import { t } from '../i18n/index.js'

/** Settings → Calendar: calendars and task lists, and linking a phone or computer over CalDAV. */
const toasts = useToasts()
const session = useSession()
async function setBirthdays(on) {
  try {
    await session.updateSettings({ show_birthdays: on })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

const calendars = ref([])
const dav = ref(null)
const newName = ref('')
const created = ref(null) // { name, password } shown once
const creating = ref(false)
const guide = ref('')

async function load() {
  try {
    const [cals, info] = await Promise.all([api.get('/calendars'), api.get('/dav')])
    calendars.value = cals
    dav.value = info
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}
onMounted(load)

const fail = (e) => toasts.error(t('toast.failed', { msg: e.message }))

async function addCalendar(type) {
  try {
    const cal = await api.post('/calendars', {
      name: type === 'tasks' ? t('calsettings.newListName') : t('calsettings.newCalendarName'),
      color: ['#C2410C', '#9333EA', '#DB2777', '#0E7490', '#CA8A04'][calendars.value.length % 5],
      type,
    })
    calendars.value.push(cal)
  } catch (e) {
    fail(e)
  }
}

async function update(cal, changes) {
  try {
    Object.assign(cal, await api.patch(`/calendars/${cal.id}`, changes))
  } catch (e) {
    fail(e)
    load()
  }
}

async function remove(cal) {
  const question = cal.feed ? 'calsettings.confirmDeleteFeed' : cal.type === 'tasks' ? 'calsettings.confirmDeleteList' : 'calsettings.confirmDeleteCalendar'
  if (!confirm(t(question, { name: cal.name }))) return
  try {
    await api.del(`/calendars/${cal.id}`)
    calendars.value = calendars.value.filter((c) => c.id !== cal.id)
  } catch (e) {
    fail(e)
  }
}

const sameType = (cal) => calendars.value.filter((c) => c.type === cal.type && !c.feed).length
const canDelete = (cal) => !!cal.feed || sameType(cal) > 1

// ---- calendars from elsewhere (iCal address, read-only)
const feedUrl = ref('')
const feedName = ref('')
const addingFeed = ref(false)
const refreshing = ref(null)

async function addFeed() {
  if (!feedUrl.value.trim()) return
  addingFeed.value = true
  try {
    await api.post('/calendar-feeds', {
      url: feedUrl.value.trim(),
      name: feedName.value.trim(),
      color: ['#0E7490', '#CA8A04', '#DB2777', '#9333EA', '#C2410C'][calendars.value.length % 5],
    })
    feedUrl.value = ''
    feedName.value = ''
    toasts.show(t('calsettings.feedAdded'))
    await load()
  } catch (e) {
    fail(e)
  } finally {
    addingFeed.value = false
  }
}

async function refreshFeed(cal) {
  refreshing.value = cal.id
  try {
    cal.feed = await api.post(`/calendar-feeds/${cal.feed.id}/refresh`)
    toasts.show(t('calsettings.feedRefreshed'))
  } catch (e) {
    fail(e)
    load()
  } finally {
    refreshing.value = null
  }
}

// ---- share links (.ics address for Google, Outlook, …)
const sharing = ref(null)

async function createShare(cal) {
  if (cal.share_url && !confirm(t('calsettings.confirmNewLink'))) return
  try {
    cal.share_url = (await api.post(`/calendars/${cal.id}/share`)).share_url
  } catch (e) {
    fail(e)
  }
}

async function stopShare(cal) {
  if (!confirm(t('calsettings.confirmStopSharing', { name: cal.name }))) return
  try {
    await api.del(`/calendars/${cal.id}/share`)
    cal.share_url = null
  } catch (e) {
    fail(e)
  }
}

const since = (iso) => (iso ? fullDate(iso) : '')

async function createPassword() {
  const name = newName.value.trim() || t('calsettings.deviceDefault')
  creating.value = true
  try {
    const res = await api.post('/dav/passwords', { name })
    created.value = { name, password: res.password }
    newName.value = ''
    dav.value.passwords.push({ id: res.id, name, created_at: new Date().toISOString(), last_used_at: null })
  } catch (e) {
    fail(e)
  } finally {
    creating.value = false
  }
}

async function revoke(p) {
  if (!confirm(t('calsettings.confirmRevoke', { name: p.name }))) return
  try {
    await api.del(`/dav/passwords/${p.id}`)
    dav.value.passwords = dav.value.passwords.filter((x) => x.id !== p.id)
  } catch (e) {
    fail(e)
  }
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.show(t('calsettings.copied'))
  } catch {
    toasts.error(t('calsettings.copyFailed'))
  }
}

// iPhone and macOS find the calendar from the host name alone (/.well-known/caldav); in a sub folder they need the full address.
const serverForApple = computed(() => (dav.value?.base_path === '/dav/' ? dav.value.server : dav.value?.url))
const guides = ['iphone', 'android', 'mac', 'thunderbird']
const feedGuide = ref(false)
</script>

<template>
  <div class="space-y-8">
    <label class="flex items-center justify-between gap-4 rounded-2xl bg-surface px-4 py-3 ring-1 ring-line">
      <span>
        {{ t('calsettings.birthdays') }}
        <span class="block text-sm text-muted">{{ t('calsettings.birthdaysHint') }}</span>
      </span>
      <input type="checkbox" class="size-5 shrink-0 accent-accent" :checked="session.settings.show_birthdays !== false" @change="setBirthdays($event.target.checked)" />
    </label>
    <section>
      <h2 class="mb-1 font-medium">{{ t('calsettings.calendarsTitle') }}</h2>
      <p class="mb-3 text-sm text-muted">{{ t('calsettings.calendarsIntro') }}</p>
      <ul class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
        <li v-for="cal in calendars" :key="cal.id" class="px-4 py-2.5">
          <div class="flex items-center gap-3">
            <label class="relative size-6 shrink-0 cursor-pointer overflow-hidden rounded-full ring-1 ring-line" :style="{ background: cal.color }" :title="t('calsettings.color')">
              <input type="color" :value="cal.color" class="absolute inset-0 cursor-pointer opacity-0" @change="update(cal, { color: $event.target.value })" />
            </label>
            <input
              :value="cal.name" class="min-w-0 flex-1 rounded-lg bg-transparent px-2 py-1 outline-none focus:bg-sunken"
              :aria-label="t('calsettings.name')"
              @change="$event.target.value.trim() && update(cal, { name: $event.target.value.trim() })"
            />
            <span class="hidden items-center gap-1 text-sm text-muted sm:flex">
              <AppIcon :name="cal.feed ? 'globe' : cal.type === 'tasks' ? 'task' : 'calendar'" :size="15" />
              {{ cal.feed ? t('calsettings.typeFeed') : cal.type === 'tasks' ? t('calsettings.typeTasks') : t('calsettings.typeEvents') }}
            </span>
            <button
              v-if="cal.feed" class="rounded-lg p-1.5 text-muted hover:bg-sunken hover:text-ink disabled:opacity-40"
              :disabled="refreshing === cal.id" :title="t('calsettings.refreshFeed')" @click="refreshFeed(cal)"
            ><AppIcon name="refresh" :size="18" :class="refreshing === cal.id ? 'animate-spin' : ''" /></button>
            <button
              v-else-if="cal.type !== 'tasks'" class="rounded-lg p-1.5 hover:bg-sunken"
              :class="cal.share_url ? 'text-accent' : 'text-muted hover:text-ink'"
              :title="t('calsettings.share')" :aria-expanded="sharing === cal.id" @click="sharing = sharing === cal.id ? null : cal.id"
            ><AppIcon name="share" :size="18" /></button>
            <button
              class="rounded-lg p-1.5 text-muted hover:bg-sunken hover:text-danger disabled:opacity-30"
              :disabled="!canDelete(cal)"
              :title="canDelete(cal) ? t('actions.trash') : t('calsettings.lastOne')"
              @click="remove(cal)"
            ><AppIcon name="trash" :size="18" /></button>
          </div>

          <!-- Calendar from elsewhere: where from, last update -->
          <p v-if="cal.feed" class="mt-1 pl-9 text-xs" :class="cal.feed.last_error ? 'text-danger' : 'text-muted'">
            <template v-if="cal.feed.last_error">{{ t('calsettings.feedError', { error: cal.feed.last_error }) }}</template>
            <template v-else>{{ t('calsettings.feedInfo', { host: cal.feed.host, date: since(cal.feed.last_success_at) }) }}</template>
          </p>

          <!-- Share link -->
          <div v-if="sharing === cal.id && !cal.feed" class="mt-2 rounded-xl bg-sunken p-3 text-sm">
            <template v-if="cal.share_url">
              <p class="mb-2">{{ t('calsettings.shareIntro') }}</p>
              <div class="flex items-center gap-2">
                <input :value="cal.share_url" readonly class="min-w-0 flex-1 rounded-lg bg-surface px-2 py-1.5 font-mono text-xs ring-1 ring-line" @focus="$event.target.select()" />
                <button class="rounded-lg bg-surface p-2 ring-1 ring-line hover:bg-canvas" :title="t('calsettings.copy')" @click="copy(cal.share_url)"><AppIcon name="copy" :size="16" /></button>
              </div>
              <p class="mt-2 text-muted">{{ t('calsettings.shareGoogle') }}</p>
              <div class="mt-2 flex flex-wrap gap-3">
                <button class="text-accent" @click="createShare(cal)">{{ t('calsettings.newLink') }}</button>
                <button class="text-danger" @click="stopShare(cal)">{{ t('calsettings.stopSharing') }}</button>
              </div>
            </template>
            <template v-else>
              <p class="mb-2">{{ t('calsettings.shareExplain') }}</p>
              <button class="rounded-lg bg-accent px-3 py-1.5 font-medium text-white" @click="createShare(cal)">{{ t('calsettings.makeLink') }}</button>
            </template>
          </div>
        </li>
      </ul>
      <div class="mt-3 flex flex-wrap gap-2">
        <button class="flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm ring-1 ring-line hover:bg-sunken" @click="addCalendar('events')"><AppIcon name="plus" :size="16" />{{ t('calsettings.addCalendar') }}</button>
        <button class="flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm ring-1 ring-line hover:bg-sunken" @click="addCalendar('tasks')"><AppIcon name="plus" :size="16" />{{ t('calsettings.addList') }}</button>
      </div>
    </section>

    <section>
      <h2 class="mb-1 font-medium">{{ t('calsettings.feedsTitle') }}</h2>
      <p class="mb-3 text-sm text-muted">{{ t('calsettings.feedsIntro') }}</p>
      <form class="space-y-2" @submit.prevent="addFeed">
        <input
          v-model="feedUrl" type="url" required inputmode="url" autocomplete="off" spellcheck="false"
          :placeholder="t('calsettings.feedUrl')" :aria-label="t('calsettings.feedUrl')"
          class="w-full rounded-xl border border-line bg-surface px-3 py-2 font-mono text-sm outline-none focus:border-accent"
        />
        <div class="flex gap-2">
          <input v-model="feedName" maxlength="100" :placeholder="t('calsettings.feedName')" class="min-w-0 flex-1 rounded-xl border border-line bg-surface px-3 py-2 outline-none focus:border-accent" />
          <button type="submit" :disabled="addingFeed || !feedUrl.trim()" class="shrink-0 rounded-xl bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-60">
            {{ addingFeed ? t('calsettings.feedAdding') : t('calsettings.feedAdd') }}
          </button>
        </div>
      </form>
      <div class="mt-3 rounded-2xl bg-surface ring-1 ring-line">
        <button class="flex w-full items-center justify-between px-4 py-3 text-left" :aria-expanded="feedGuide" @click="feedGuide = !feedGuide">
          <span>{{ t('calsettings.googleTitle') }}</span>
          <AppIcon name="chevron" :size="16" class="text-muted transition" :class="feedGuide ? 'rotate-90' : ''" />
        </button>
        <div v-if="feedGuide" class="space-y-3 px-4 pb-4 text-sm">
          <ol class="list-decimal space-y-1.5 pl-5">
            <li v-for="(step, i) in t('calsettings.googleSteps').split('\n')" :key="i">{{ step }}</li>
          </ol>
          <p class="text-muted">{{ t('calsettings.googleNote') }}</p>
        </div>
      </div>
    </section>

    <section v-if="dav">
      <h2 class="mb-1 font-medium">{{ t('calsettings.syncTitle') }}</h2>
      <p class="mb-3 text-sm text-muted">{{ t('calsettings.syncIntro') }}</p>

      <dl class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
        <div class="flex items-center gap-3 px-4 py-2.5">
          <dt class="w-32 shrink-0 text-sm text-muted">{{ t('calsettings.server') }}</dt>
          <dd class="min-w-0 flex-1 truncate font-mono text-sm">{{ dav.url }}</dd>
          <button class="rounded-lg p-1.5 text-muted hover:bg-sunken hover:text-ink" :title="t('calsettings.copy')" @click="copy(dav.url)"><AppIcon name="copy" :size="16" /></button>
        </div>
        <div class="flex items-center gap-3 px-4 py-2.5">
          <dt class="w-32 shrink-0 text-sm text-muted">{{ t('calsettings.username') }}</dt>
          <dd class="min-w-0 flex-1 truncate font-mono text-sm">{{ dav.username }}</dd>
          <button class="rounded-lg p-1.5 text-muted hover:bg-sunken hover:text-ink" :title="t('calsettings.copy')" @click="copy(dav.username)"><AppIcon name="copy" :size="16" /></button>
        </div>
        <div class="px-4 py-2.5">
          <dt class="mb-1 text-sm text-muted">{{ t('calsettings.password') }}</dt>
          <dd class="text-sm">{{ t('calsettings.passwordIntro') }}</dd>
        </div>
      </dl>

      <!-- New app password, shown once -->
      <div v-if="created" class="mt-3 rounded-2xl bg-archive/10 p-4 ring-1 ring-archive/30">
        <p class="mb-2 text-sm">{{ t('calsettings.createdFor', { name: created.name }) }}</p>
        <div class="flex items-center gap-2">
          <code class="min-w-0 flex-1 select-all break-all rounded-xl bg-surface px-3 py-2 text-center font-mono text-lg tracking-wider ring-1 ring-line">{{ created.password }}</code>
          <button class="rounded-xl bg-surface p-2.5 ring-1 ring-line hover:bg-sunken" :title="t('calsettings.copy')" @click="copy(created.password)"><AppIcon name="copy" :size="18" /></button>
        </div>
        <p class="mt-2 text-sm text-muted">{{ t('calsettings.onlyOnce') }}</p>
        <button class="mt-2 text-sm text-accent" @click="created = null">{{ t('calsettings.doneCopying') }}</button>
      </div>

      <form class="mt-3 flex gap-2" @submit.prevent="createPassword">
        <input v-model="newName" maxlength="100" :placeholder="t('calsettings.devicePlaceholder')" class="min-w-0 flex-1 rounded-xl border border-line bg-surface px-3 py-2 outline-none focus:border-accent" />
        <button type="submit" :disabled="creating" class="shrink-0 rounded-xl bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{{ t('calsettings.newPassword') }}</button>
      </form>

      <ul v-if="dav.passwords.length" class="mt-3 divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
        <li v-for="p in dav.passwords" :key="p.id" class="flex items-center gap-3 px-4 py-2.5">
          <AppIcon name="phone" :size="18" class="shrink-0 text-muted" />
          <div class="min-w-0 flex-1">
            <div class="truncate">{{ p.name }}</div>
            <div class="text-xs text-muted">{{ p.last_used_at ? t('calsettings.lastUsed', { date: fullDate(p.last_used_at.replace(' ', 'T')) }) : t('calsettings.neverUsed') }}</div>
          </div>
          <button class="rounded-lg px-2.5 py-1 text-sm text-danger hover:bg-danger/10" @click="revoke(p)">{{ t('calsettings.revoke') }}</button>
        </li>
      </ul>

      <h3 class="mb-2 mt-6 text-sm font-medium text-muted">{{ t('calsettings.howTo') }}</h3>
      <div class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
        <div v-for="g in guides" :key="g">
          <button class="flex w-full items-center justify-between px-4 py-3 text-left" :aria-expanded="guide === g" @click="guide = guide === g ? '' : g">
            <span>{{ t(`calsettings.guide.${g}.title`) }}</span>
            <AppIcon name="chevron" :size="16" class="text-muted transition" :class="guide === g ? 'rotate-90' : ''" />
          </button>
          <ol v-if="guide === g" class="list-decimal space-y-1.5 pb-4 pl-9 pr-4 text-sm">
            <li v-for="(step, i) in t(`calsettings.guide.${g}.steps`, { server: g === 'iphone' || g === 'mac' ? serverForApple : dav.url, username: dav.username }).split('\n')" :key="i">
              {{ step }}
            </li>
          </ol>
        </div>
      </div>
    </section>
  </div>
</template>
