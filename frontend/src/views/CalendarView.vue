<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import EventEditor from '../components/EventEditor.vue'
import { api } from '../api/client.js'
import { useToasts } from '../stores/toasts.js'
import { useSession } from '../stores/session.js'
import {
  timeZone, ymd, parseYmd, addDays, addMonths, startOfWeek, monthGrid, byDay, layoutDay, nextHalfHour,
} from '../calendar.js'
import { t, locale } from '../i18n/index.js'

/**
 * Calendar: month, week (day on phones) and list. Events come from /events for the visible
 * period, recurring events already expanded by the server. Same data as on a phone over CalDAV.
 */
const router = useRouter()
const route = useRoute()
const toasts = useToasts()

const wide = ref(matchMedia('(min-width: 1024px)').matches)
const media = matchMedia('(min-width: 1024px)')
const onMedia = (e) => (wide.value = e.matches)
onMounted(() => media.addEventListener('change', onMedia))
onBeforeUnmount(() => media.removeEventListener('change', onMedia))

const store = {
  get: (k, d) => { try { return localStorage.getItem(k) ?? d } catch { return d } },
  set: (k, v) => { try { localStorage.setItem(k, v) } catch {} },
}

const view = ref(store.get('pv-cal-view', wide.value ? 'week' : 'list')) // month | week | list
watch(view, (v) => store.set('pv-cal-view', v))
const cursor = ref(route.query.date && /^\d{4}-\d{2}-\d{2}$/.test(route.query.date) ? parseYmd(route.query.date) : new Date())
const selectedDay = ref(ymd(cursor.value))
const calendars = ref([])
const hidden = ref(new Set(JSON.parse(store.get('pv-cal-hidden', '[]'))))
const events = ref([])
const loading = ref(false)
const editor = ref(null) // { event } | { start, allDay }

const today = ref(ymd(new Date()))
const now = ref(new Date())
let clock = null
onMounted(() => (clock = setInterval(() => { now.value = new Date(); today.value = ymd(now.value) }, 60000)))
onBeforeUnmount(() => clearInterval(clock))

const dayCount = computed(() => (wide.value ? 7 : 1))
const range = computed(() => {
  const c = cursor.value
  if (view.value === 'month') {
    const grid = monthGrid(c)
    return { start: grid[0], end: addDays(grid[41], 1) }
  }
  if (view.value === 'week') {
    const start = dayCount.value === 7 ? startOfWeek(c) : new Date(c.getFullYear(), c.getMonth(), c.getDate())
    return { start, end: addDays(start, dayCount.value) }
  }
  const start = new Date(c.getFullYear(), c.getMonth(), c.getDate())
  return { start, end: addDays(start, 42) }
})

const days = computed(() => Array.from({ length: Math.round((range.value.end - range.value.start) / 86400000) }, (_, i) => addDays(range.value.start, i)))

async function loadCalendars() {
  try {
    calendars.value = await api.get('/calendars')
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

let loadSeq = 0
async function loadEvents() {
  const seq = ++loadSeq
  loading.value = true
  try {
    const list = await api.get('/events', { start: ymd(range.value.start), end: ymd(range.value.end), tz: timeZone() })
    if (seq === loadSeq) events.value = list
  } catch (e) {
    if (seq === loadSeq) toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    if (seq === loadSeq) loading.value = false
  }
}

onMounted(async () => {
  loadContacts()
  await loadCalendars()
  await loadEvents()
  scrollToMorning()
})
watch(() => [ymd(range.value.start), ymd(range.value.end)], loadEvents)
watch(() => route.query.date, (d) => {
  if (d && /^\d{4}-\d{2}-\d{2}$/.test(d)) {
    cursor.value = parseYmd(d)
    selectedDay.value = d
  }
})

// Birthdays of saved contacts, shown as all-day items (not stored in a calendar, so not on phones over CalDAV).
const session = useSession()
const BIRTHDAYS = 'birthdays'
const contacts = ref([])
const showBirthdays = computed(() => session.settings?.show_birthdays !== false)
async function loadContacts() {
  if (!showBirthdays.value || contacts.value.length) return
  try {
    contacts.value = (await api.get('/contacts/list', { saved: 1 })).filter((c) => c.details?.birthday)
  } catch {
    // birthdays are a nicety; the calendar works without them
  }
}
watch(showBirthdays, loadContacts)
const birthdayEvents = computed(() => {
  if (!showBirthdays.value || hidden.value.has(BIRTHDAYS)) return []
  const out = []
  const first = range.value.start.getFullYear()
  const last = range.value.end.getFullYear()
  for (const c of contacts.value) {
    const m = /^(\d{4}|-)-(\d{2})-(\d{2})$/.exec(c.details.birthday)
    if (!m) continue
    for (let y = first; y <= last; y++) {
      const date = new Date(y, Number(m[2]) - 1, Number(m[3]))
      if (date.getMonth() !== Number(m[2]) - 1) continue // 29 February in a non-leap year
      if (date < range.value.start || date >= range.value.end) continue
      const name = c.name || c.email
      const age = m[1] !== '-' ? y - Number(m[1]) : null
      out.push({
        key: `bday-${c.id}-${y}`, birthday: true, contact_id: c.id, calendar_id: BIRTHDAYS, color: '#DB2777', all_day: true,
        start: ymd(date), end: ymd(addDays(date, 1)),
        title: age !== null && age > 0 ? t('calendar.birthdayAge', { name, age }) : t('calendar.birthday', { name }),
      })
    }
  }
  return out
})
const visibleEvents = computed(() => [...events.value.filter((e) => !hidden.value.has(e.calendar_id)), ...birthdayEvents.value])
const perDay = computed(() => byDay(visibleEvents.value))
const eventCalendars = computed(() => calendars.value.filter((c) => c.type !== 'tasks'))
const calendarChips = computed(() => [
  ...eventCalendars.value,
  ...(showBirthdays.value && contacts.value.length ? [{ id: BIRTHDAYS, name: t('calendar.birthdays'), color: '#DB2777' }] : []),
])

function toggleCalendar(id) {
  const next = new Set(hidden.value)
  next.has(id) ? next.delete(id) : next.add(id)
  hidden.value = next
  store.set('pv-cal-hidden', JSON.stringify([...next]))
}

// ---- navigation
function move(dir) {
  const c = cursor.value
  if (view.value === 'month') cursor.value = addMonths(c, dir)
  else if (view.value === 'week') cursor.value = addDays(c, dir * dayCount.value)
  else cursor.value = addDays(c, dir * 28)
  if (view.value === 'month') selectedDay.value = ymd(cursor.value)
}
function goToday() {
  cursor.value = new Date()
  selectedDay.value = ymd(cursor.value)
  nextTick(scrollToMorning)
}
function openDay(day) {
  cursor.value = parseYmd(day)
  selectedDay.value = day
  view.value = 'week'
  nextTick(scrollToMorning)
}

const title = computed(() => {
  const c = cursor.value
  const loc = locale()
  if (view.value === 'month') return c.toLocaleDateString(loc, { month: 'long', year: 'numeric' })
  if (view.value === 'week' && dayCount.value === 1) return c.toLocaleDateString(loc, { weekday: 'long', day: 'numeric', month: 'long' })
  const s = range.value.start
  const e = addDays(range.value.end, -1)
  if (view.value === 'list') return s.toLocaleDateString(loc, { month: 'long', year: 'numeric' })
  return s.getMonth() === e.getMonth()
    ? `${s.getDate()} – ${e.toLocaleDateString(loc, { day: 'numeric', month: 'long', year: 'numeric' })}`
    : `${s.toLocaleDateString(loc, { day: 'numeric', month: 'short' })} – ${e.toLocaleDateString(loc, { day: 'numeric', month: 'short', year: 'numeric' })}`
})

// ---- formatting
const time = (iso) => new Date(iso).toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit' })
const weekdayShort = (d) => d.toLocaleDateString(locale(), { weekday: 'short' })
const weekdays = computed(() => Array.from({ length: 7 }, (_, i) => weekdayShort(addDays(startOfWeek(new Date()), i))))
function timeRange(ev, day) {
  if (ev.all_day) return t('calendar.allDay')
  const s = new Date(ev.start)
  const e = new Date(ev.end)
  const startsToday = ymd(s) === day
  const endsToday = ymd(e) === day || (ymd(addDays(e, -1)) === day && e.getHours() === 0 && e.getMinutes() === 0)
  if (startsToday && endsToday) return `${time(ev.start)} – ${time(ev.end)}`
  if (startsToday) return `${time(ev.start)} →`
  if (endsToday) return `→ ${time(ev.end)}`
  return t('calendar.allDay')
}
const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1)
function dayHeading(day) {
  const d = parseYmd(day)
  const label = d.toLocaleDateString(locale(), { weekday: 'long', day: 'numeric', month: 'long' })
  if (day === today.value) return cap(`${t('dates.today')} · ${label}`)
  if (day === ymd(addDays(new Date(), 1))) return cap(`${t('dates.tomorrow')} · ${label}`)
  return cap(label)
}

// ---- week / day grid
const HOUR = 48 // px per hour
const grid = ref(null)
function scrollToMorning() {
  if (!grid.value) return
  const nowMinutes = now.value.getHours() * 60 + now.value.getMinutes()
  const showsToday = days.value.some((d) => ymd(d) === today.value)
  const target = showsToday ? Math.max(0, nowMinutes - 120) : 7 * 60
  grid.value.scrollTop = (target / 60) * HOUR
}
watch(view, (v) => v === 'week' && nextTick(scrollToMorning))

const columns = computed(() => days.value.map((d) => {
  const day = ymd(d)
  const list = perDay.value.get(day) ?? []
  return { day, date: d, allDay: list.filter((e) => e.all_day || spansWholeDay(e, day)), items: layoutDay(list.filter((e) => !e.all_day && !spansWholeDay(e, day)), day) }
}))
function spansWholeDay(ev, day) {
  if (ev.all_day) return true
  const start = parseYmd(day)
  return new Date(ev.start) <= start && new Date(ev.end) >= addDays(start, 1)
}
const nowTop = computed(() => ((now.value.getHours() * 60 + now.value.getMinutes()) / 60) * HOUR)

function onGridClick(e, day) {
  const rect = e.currentTarget.getBoundingClientRect()
  const minutes = Math.floor(((e.clientY - rect.top) / HOUR) * 2) * 30
  const start = parseYmd(day)
  start.setMinutes(minutes)
  editor.value = { start, allDay: false }
}

// ---- list view
const listDays = computed(() => {
  const out = []
  for (const d of days.value) {
    const day = ymd(d)
    const list = perDay.value.get(day)
    if (list?.length) out.push({ day, list })
  }
  return out
})

// ---- editor
function newEvent(day = null) {
  // Nothing chosen: today when it is on screen, otherwise the first day shown.
  if (!day && view.value !== 'list' && (today.value < ymd(range.value.start) || today.value >= ymd(range.value.end))) day = ymd(range.value.start)
  const base = day ? parseYmd(day) : new Date()
  const start = nextHalfHour(day && day !== today.value ? new Date(base.getFullYear(), base.getMonth(), base.getDate(), 9, 30) : new Date())
  editor.value = { start, allDay: false }
}
function openEvent(ev) {
  if (ev.birthday) return router.push({ name: 'contacts', query: { id: ev.contact_id } })
  editor.value = { event: ev }
}
async function onSaved(info) {
  editor.value = null
  if (info?.date) {
    const d = parseYmd(info.date)
    if (d < range.value.start || d >= range.value.end) {
      cursor.value = d
      selectedDay.value = info.date
      return // the range watcher reloads
    }
  }
  await loadEvents()
}
async function onDeleted() {
  editor.value = null
  await loadEvents()
}

function onKey(e) {
  if (editor.value || e.target.closest?.('input, textarea, select, [contenteditable]')) return
  if (e.key === 'ArrowLeft' || e.key === 'k') move(-1)
  else if (e.key === 'ArrowRight' || e.key === 'j') move(1)
  else if (e.key === 't') goToday()
  else if (e.key === 'c' || e.key === 'n') newEvent(view.value === 'month' ? selectedDay.value : null)
  else if (e.key === 'm') view.value = 'month'
  else if (e.key === 'w' || e.key === 'd') view.value = 'week'
  else if (e.key === 'l') view.value = 'list'
  else return
  e.preventDefault()
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

// Phones: swipe sideways to go to the previous/next day or month.
let touch = null
function onTouchStart(e) {
  touch = e.touches.length === 1 ? { x: e.touches[0].clientX, y: e.touches[0].clientY } : null
}
function onTouchEnd(e) {
  if (!touch || editor.value) return
  const dx = e.changedTouches[0].clientX - touch.x
  const dy = e.changedTouches[0].clientY - touch.y
  touch = null
  if (Math.abs(dx) > 70 && Math.abs(dx) > Math.abs(dy) * 1.5) move(dx < 0 ? 1 : -1)
}

const views = computed(() => [['month', t('calendar.month')], ['week', wide.value ? t('calendar.week') : t('calendar.day')], ['list', t('calendar.list')]])
const chipStyle = (ev) => ({ background: `color-mix(in srgb, ${ev.color} 16%, var(--color-surface))`, borderColor: ev.color })
</script>

<template>
  <div class="pt-safe pb-safe flex h-full flex-col bg-surface">
    <header class="flex flex-wrap items-center gap-1 border-b border-line px-2 py-2">
      <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('actions.back')" @click="router.push('/')">
        <AppIcon name="back" />
      </button>
      <h1 class="min-w-0 flex-1 truncate px-1 text-lg font-semibold first-letter:uppercase">{{ title }}</h1>
      <button class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-line hover:bg-sunken" @click="goToday">{{ t('calendar.today') }}</button>
      <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('calendar.previous')" @click="move(-1)"><AppIcon name="chevronLeft" /></button>
      <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('calendar.next')" @click="move(1)"><AppIcon name="chevronRight" /></button>
      <button class="hidden items-center gap-1.5 rounded-xl bg-accent px-3 py-2 text-sm font-medium text-white hover:brightness-110 sm:flex" @click="newEvent(view === 'month' ? selectedDay : null)">
        <AppIcon name="plus" :size="18" />{{ t('calendar.newEvent') }}
      </button>
      <div class="flex w-full items-center gap-2 pt-1 sm:w-auto sm:pt-0">
        <div class="flex rounded-xl bg-sunken p-0.5 text-sm" role="tablist">
          <button
            v-for="[key, label] in views" :key="key" role="tab" :aria-selected="view === key"
            class="rounded-lg px-3 py-1" :class="view === key ? 'bg-surface font-medium shadow-sm' : 'text-muted hover:text-ink'"
            @click="view = key; key === 'week' && dayCount === 1 && (cursor = parseYmd(selectedDay))"
          >{{ label }}</button>
        </div>
        <div class="flex min-w-0 flex-1 gap-1 overflow-x-auto">
          <button
            v-for="c in calendarChips" :key="c.id"
            class="flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs ring-1 ring-line"
            :class="hidden.has(c.id) ? 'text-muted' : ''"
            :aria-pressed="!hidden.has(c.id)"
            @click="toggleCalendar(c.id)"
          >
            <span class="size-2.5 rounded-full" :style="hidden.has(c.id) ? { boxShadow: `inset 0 0 0 1.5px ${c.color}` } : { background: c.color }" />
            {{ c.name }}
          </button>
        </div>
      </div>
    </header>

    <!-- Month -->
    <div v-if="view === 'month'" class="flex min-h-0 flex-1 flex-col" @touchstart.passive="onTouchStart" @touchend="onTouchEnd">
      <div class="grid grid-cols-7 border-b border-line text-center text-xs text-muted">
        <div v-for="w in weekdays" :key="w" class="py-1.5">{{ w }}</div>
      </div>
      <div class="grid min-h-0 flex-1 grid-cols-7 grid-rows-6" :class="wide ? '' : 'flex-none'">
        <div
          v-for="d in days" :key="ymd(d)"
          class="min-h-0 overflow-hidden border-b border-r border-line p-1 last:border-r-0"
          :class="[d.getMonth() !== cursor.getMonth() ? 'bg-canvas/60 text-muted' : '', !wide && ymd(d) === selectedDay ? 'bg-accent-soft' : '', wide ? 'min-h-24 cursor-pointer' : 'h-14 cursor-pointer']"
          @click="wide ? newEvent(ymd(d)) : (selectedDay = ymd(d))"
          @dblclick="openDay(ymd(d))"
        >
          <button
            class="mb-0.5 flex size-6 items-center justify-center rounded-full text-xs"
            :class="ymd(d) === today ? 'bg-accent font-semibold text-white' : 'hover:bg-sunken'"
            @click.stop="wide ? openDay(ymd(d)) : (selectedDay = ymd(d))"
          >{{ d.getDate() }}</button>
          <template v-if="wide">
            <button
              v-for="ev in (perDay.get(ymd(d)) ?? []).slice(0, 3)" :key="ev.key"
              class="mb-0.5 flex w-full items-center gap-1 truncate rounded px-1 text-left text-xs"
              :class="ev.all_day ? 'border-l-2' : ''"
              :style="ev.all_day ? chipStyle(ev) : {}"
              @click.stop="openEvent(ev)"
            >
              <span v-if="!ev.all_day" class="size-1.5 shrink-0 rounded-full" :style="{ background: ev.color }" />
              <span v-if="!ev.all_day" class="shrink-0 text-muted tabular-nums">{{ time(ev.start) }}</span>
              <span class="truncate">{{ ev.title }}</span>
            </button>
            <button v-if="(perDay.get(ymd(d)) ?? []).length > 3" class="px-1 text-xs text-muted hover:text-ink" @click.stop="openDay(ymd(d))">
              {{ t('calendar.more', { n: perDay.get(ymd(d)).length - 3 }) }}
            </button>
          </template>
          <div v-else class="flex flex-wrap justify-center gap-0.5">
            <span v-for="ev in (perDay.get(ymd(d)) ?? []).slice(0, 4)" :key="ev.key" class="size-1.5 rounded-full" :style="{ background: ev.color }" />
          </div>
        </div>
      </div>
      <!-- Phone: the chosen day below the month -->
      <section v-if="!wide" class="min-h-0 flex-1 overflow-y-auto">
        <h2 class="flex items-center justify-between px-4 pb-1 pt-3 text-sm font-medium text-muted first-letter:uppercase">
          <span>{{ dayHeading(selectedDay) }}</span>
          <button class="rounded-lg p-1 text-accent" :aria-label="t('calendar.newEvent')" @click="newEvent(selectedDay)"><AppIcon name="plus" /></button>
        </h2>
        <ul class="divide-y divide-line">
          <li v-for="ev in perDay.get(selectedDay) ?? []" :key="ev.key">
            <button class="flex w-full items-start gap-3 px-4 py-2.5 text-left hover:bg-sunken" @click="openEvent(ev)">
              <span class="mt-1.5 size-2.5 shrink-0 rounded-full" :style="{ background: ev.color }" />
              <span class="min-w-0 flex-1">
                <span class="block truncate font-medium">{{ ev.title }}</span>
                <span class="block text-sm text-muted">{{ timeRange(ev, selectedDay) }}<template v-if="ev.location"> · {{ ev.location }}</template></span>
              </span>
            </button>
          </li>
          <li v-if="!(perDay.get(selectedDay) ?? []).length" class="px-4 py-6 text-center text-muted">{{ t('calendar.nothing') }}</li>
        </ul>
      </section>
    </div>

    <!-- Week / day -->
    <div v-else-if="view === 'week'" class="flex min-h-0 flex-1 flex-col" @touchstart.passive="onTouchStart" @touchend="onTouchEnd">
      <div class="flex border-b border-line">
        <div class="w-12 shrink-0" />
        <div v-for="col in columns" :key="col.day" class="min-w-0 flex-1 border-l border-line px-1 py-1">
          <div class="flex w-full items-baseline justify-center gap-1 text-sm">
            <span class="text-muted">{{ weekdayShort(col.date) }}</span>
            <span class="flex size-7 items-center justify-center rounded-full font-medium" :class="col.day === today ? 'bg-accent text-white' : ''">{{ col.date.getDate() }}</span>
          </div>
          <div class="space-y-0.5 pb-0.5">
            <button
              v-for="ev in col.allDay" :key="ev.key"
              class="block w-full truncate rounded border-l-2 px-1 text-left text-xs"
              :style="chipStyle(ev)"
              @click="openEvent(ev)"
            >{{ ev.title }}</button>
          </div>
        </div>
      </div>
      <div ref="grid" class="relative min-h-0 flex-1 overflow-y-auto">
        <div class="relative flex" :style="{ height: `${24 * HOUR}px` }">
          <div class="w-12 shrink-0 text-right text-[11px] text-muted">
            <div v-for="h in 24" :key="h" class="relative pr-1.5" :style="{ height: `${HOUR}px` }">
              <span v-if="h > 1" class="absolute -top-2 right-1.5 tabular-nums">{{ String(h - 1).padStart(2, '0') }}:00</span>
            </div>
          </div>
          <div
            v-for="col in columns" :key="col.day"
            class="relative min-w-0 flex-1 cursor-pointer border-l border-line"
            :class="col.day === today ? 'bg-accent-soft/30' : ''"
            :style="{ backgroundImage: `repeating-linear-gradient(to bottom, var(--color-line) 0 1px, transparent 1px ${HOUR}px)` }"
            @click.self="onGridClick($event, col.day)"
          >
            <button
              v-for="it in col.items" :key="it.ev.key"
              class="absolute flex flex-col justify-start overflow-hidden rounded-md border-l-[3px] px-1.5 py-0.5 text-left text-xs leading-tight shadow-sm"
              :style="{
                ...chipStyle(it.ev),
                top: `${(it.top / 60) * HOUR}px`,
                height: `${Math.max(18, (it.height / 60) * HOUR - 2)}px`,
                left: `calc(${(it.col / it.cols) * 100}% + 2px)`,
                width: `calc(${100 / it.cols}% - 4px)`,
              }"
              @click="openEvent(it.ev)"
            >
              <span class="block truncate font-medium">{{ it.ev.title }}</span>
              <span v-if="it.height >= 45" class="block truncate text-muted">{{ time(it.ev.start) }}<template v-if="it.ev.location"> · {{ it.ev.location }}</template></span>
            </button>
            <div v-if="col.day === today" class="pointer-events-none absolute inset-x-0 z-10 h-0.5 bg-danger" :style="{ top: `${nowTop}px` }">
              <span class="absolute -left-1 -top-1 size-2.5 rounded-full bg-danger" />
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- List -->
    <div v-else class="min-h-0 flex-1 overflow-y-auto">
      <section v-for="group in listDays" :key="group.day">
        <h2 class="sticky top-0 z-10 bg-surface/95 px-4 pb-1 pt-3 text-sm font-medium text-muted backdrop-blur first-letter:uppercase" :class="group.day === today ? 'text-accent' : ''">{{ dayHeading(group.day) }}</h2>
        <ul>
          <li v-for="ev in group.list" :key="ev.key">
            <button class="flex w-full items-start gap-3 px-4 py-2.5 text-left hover:bg-sunken" @click="openEvent(ev)">
              <span class="mt-1.5 size-2.5 shrink-0 rounded-full" :style="{ background: ev.color }" />
              <span class="w-24 shrink-0 text-sm tabular-nums text-muted">{{ timeRange(ev, group.day) }}</span>
              <span class="min-w-0 flex-1">
                <span class="block truncate font-medium">{{ ev.title }}</span>
                <span v-if="ev.location" class="block truncate text-sm text-muted">{{ ev.location }}</span>
              </span>
              <AppIcon v-if="ev.recurring" name="repeat" :size="15" class="mt-1 shrink-0 text-muted" />
              <AppIcon v-if="ev.reminder !== null" name="bell" :size="15" class="mt-1 shrink-0 text-muted" />
            </button>
          </li>
        </ul>
      </section>
      <p v-if="!loading && !listDays.length" class="px-4 py-12 text-center text-muted">{{ t('calendar.nothingPeriod') }}</p>
      <div class="p-4 text-center">
        <button class="rounded-xl px-4 py-2 text-sm text-accent hover:bg-accent-soft" @click="move(1)">{{ t('calendar.later') }}</button>
      </div>
    </div>

    <!-- New event on phones -->
    <button
      style="bottom: calc(1.25rem + env(safe-area-inset-bottom))" class="fixed right-5 z-20 flex size-14 items-center justify-center rounded-2xl bg-accent text-white shadow-lg sm:hidden"
      :aria-label="t('calendar.newEvent')"
      @click="newEvent(view === 'month' ? selectedDay : view === 'week' ? ymd(cursor) : null)"
    >
      <AppIcon name="plus" :size="26" />
    </button>

    <EventEditor
      v-if="editor && calendars.length"
      :event="editor.event ?? null"
      :start="editor.start ?? null"
      :all-day="editor.allDay ?? false"
      :calendars="calendars"
      @close="editor = null"
      @saved="onSaved"
      @deleted="onDeleted"
    />
  </div>
</template>
