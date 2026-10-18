<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import AppIcon from './AppIcon.vue'
import { api } from '../api/client.js'
import { useToasts } from '../stores/toasts.js'
import { timeZone, ymd, localStamp, parseYmd, addDays } from '../calendar.js'
import { t } from '../i18n/index.js'

/**
 * Create or edit an event. Props: event = null (new) or an occurrence from the list;
 * start = Date for a new event (clicked slot). Emits saved / deleted / close.
 * A recurring event is edited as a series; deleting asks "only this one or all".
 */
const props = defineProps({
  event: { type: Object, default: null },
  start: { type: Date, default: null },
  allDay: { type: Boolean, default: false },
  calendars: { type: Array, required: true },
})
const emit = defineEmits(['saved', 'deleted', 'close'])
const toasts = useToasts()

const eventCalendars = computed(() => props.calendars.filter((c) => c.type !== 'tasks' && c.writable))
// Events from a calendar from elsewhere (an iCal address) can only be looked at.
const ownCalendar = computed(() => (props.event ? props.calendars.find((c) => c.id === props.event.calendar_id) : null))
const readonly = computed(() => !!ownCalendar.value && !ownCalendar.value.writable)
const calendarChoices = computed(() => (readonly.value ? [ownCalendar.value] : eventCalendars.value))
const loading = ref(!!props.event)
const saving = ref(false)
const askDelete = ref(false)
const titleInput = ref(null)
const originalRule = ref(null)

const form = ref({
  calendar_id: eventCalendars.value[0]?.id ?? null,
  title: '',
  location: '',
  description: '',
  all_day: props.allDay,
  date: '',
  time: '',
  endDate: '',
  endTime: '',
  reminder: '',
  repeat: '',
  until: '',
})

function initNew() {
  const s = props.start ?? new Date()
  const e = new Date(s.getTime() + 60 * 60000)
  Object.assign(form.value, {
    date: ymd(s), time: localStamp(s).slice(11), endDate: ymd(e), endTime: localStamp(e).slice(11),
    reminder: props.allDay ? '' : '15',
  })
}

onMounted(async () => {
  if (!props.event) {
    initNew()
    nextTick(() => titleInput.value?.focus())
    return
  }
  try {
    const ev = await api.get(`/events/${props.event.id}`, { tz: timeZone() })
    originalRule.value = ev.recurrence
    Object.assign(form.value, {
      calendar_id: ev.calendar_id,
      title: ev.title,
      location: ev.location,
      description: ev.description,
      all_day: ev.all_day,
      date: ev.start.slice(0, 10),
      time: ev.all_day ? '09:00' : ev.start.slice(11, 16),
      endDate: ev.end.slice(0, 10),
      endTime: ev.all_day ? '10:00' : ev.end.slice(11, 16),
      reminder: ev.reminder === null ? '' : String(ev.reminder),
      repeat: ev.recurrence ? (ev.recurrence.custom ? 'custom' : ev.recurrence.freq) : '',
      until: ev.recurrence?.until ?? '',
    })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
    emit('close')
  } finally {
    loading.value = false
  }
})

// Moving the start moves the end along (same duration).
function onStartChange(field, value) {
  const f = form.value
  const oldStart = f.all_day ? parseYmd(f.date) : new Date(`${f.date}T${f.time || '00:00'}`)
  const oldEnd = f.all_day ? parseYmd(f.endDate) : new Date(`${f.endDate}T${f.endTime || '00:00'}`)
  f[field] = value
  const newStart = f.all_day ? parseYmd(f.date) : new Date(`${f.date}T${f.time || '00:00'}`)
  if (isNaN(newStart) || isNaN(oldStart) || isNaN(oldEnd)) return
  const end = new Date(newStart.getTime() + Math.max(0, oldEnd - oldStart))
  f.endDate = ymd(end)
  if (!f.all_day) f.endTime = localStamp(end).slice(11)
}

// The reminder choices differ for all-day events: pick a sensible one when switching.
watch(() => form.value.all_day, (allDay) => {
  if (loading.value) return
  if (!reminderOptions.value.some(([v]) => v === form.value.reminder)) form.value.reminder = allDay ? '-540' : '15'
})

const reminderOptions = computed(() => form.value.all_day
  ? [['', t('calendar.reminderNone')], ['-540', t('calendar.reminderDay9')], ['900', t('calendar.reminderDayBefore')], ['9540', t('calendar.reminderWeekBefore')]]
  : [['', t('calendar.reminderNone')], ['0', t('calendar.reminderAtStart')], ...[5, 10, 15, 30, 60, 120, 1440].map((m) => [String(m), t('calendar.reminderBefore', { time: duration(m) })])])

function duration(m) {
  if (m >= 1440) return t('calendar.days', { n: m / 1440 })
  if (m >= 60) return t('calendar.hours', { n: m / 60 })
  return t('calendar.minutes', { n: m })
}

const repeatOptions = computed(() => [
  ['', t('calendar.repeatNone')],
  ['DAILY', t('calendar.repeatDaily')],
  ['WEEKLY', t('calendar.repeatWeekly')],
  ['MONTHLY', t('calendar.repeatMonthly')],
  ['YEARLY', t('calendar.repeatYearly')],
  ...(originalRule.value?.custom ? [['custom', t('calendar.repeatCustom')]] : []),
])

function recurrencePayload() {
  const f = form.value
  if (!f.repeat) return null
  const orig = originalRule.value
  // Unchanged: keep the rule as it is (also rules made elsewhere that Postvak cannot edit).
  if (orig && (f.repeat === 'custom' || (f.repeat === orig.freq && (f.until || '') === (orig.until || '') && !orig.count))) return { rrule: orig.rrule }
  return { freq: f.repeat, until: f.until || null, interval: orig && f.repeat === orig.freq ? orig.interval : 1 }
}

async function save() {
  const f = form.value
  if (!f.date || readonly.value) return
  saving.value = true
  const payload = {
    calendar_id: f.calendar_id,
    title: f.title,
    location: f.location,
    description: f.description,
    all_day: f.all_day,
    start: f.all_day ? f.date : `${f.date}T${f.time || '09:00'}`,
    end: f.all_day ? (f.endDate || f.date) : `${f.endDate || f.date}T${f.endTime || f.time || '10:00'}`,
    timezone: timeZone(),
    reminder: f.reminder === '' ? null : Number(f.reminder),
    recurrence: recurrencePayload(),
  }
  try {
    if (props.event) await api.put(`/events/${props.event.id}`, payload)
    else await api.post('/events', payload)
    toasts.show(t('calendar.saved'))
    emit('saved', { date: f.date })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    saving.value = false
  }
}

async function remove(onlyThis) {
  askDelete.value = false
  try {
    await api.del(`/events/${props.event.id}`, onlyThis && props.event.occurrence ? { occurrence: props.event.occurrence } : undefined)
    toasts.show(t('calendar.deleted'))
    emit('deleted')
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

function onDeleteClick() {
  if (props.event?.recurring && props.event.occurrence) askDelete.value = true
  else if (confirm(t('calendar.confirmDelete', { title: form.value.title || t('calendar.untitled') }))) remove(false)
}

function onKey(e) {
  if (e.key === 'Escape') {
    e.stopPropagation()
    emit('close')
  }
  if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && !readonly.value) save()
}
onMounted(() => window.addEventListener('keydown', onKey, true))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey, true))

const field = 'w-full rounded-xl border border-line bg-surface px-3 py-2 outline-none focus:border-accent'
const calendarColor = computed(() => props.calendars.find((c) => c.id === form.value.calendar_id)?.color ?? 'var(--color-accent)')
const sameDay = computed(() => form.value.endDate === form.value.date)
// Keep "until" after the start date.
const minUntil = computed(() => (form.value.date ? ymd(addDays(parseYmd(form.value.date), 1)) : ''))
</script>

<template>
  <div class="fixed inset-0 z-40 flex items-end justify-center bg-ink/40 sm:items-center sm:p-4" @click.self="emit('close')">
    <form
      class="pb-safe flex max-h-[92vh] w-full flex-col rounded-t-2xl bg-surface shadow-2xl ring-1 ring-line sm:max-w-lg sm:rounded-2xl"
      role="dialog"
      :aria-label="event ? t('calendar.editEvent') : t('calendar.newEvent')"
      @submit.prevent="save"
    >
      <header class="flex items-center gap-2 border-b border-line px-3 py-2">
        <button type="button" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('compose.close')" @click="emit('close')"><AppIcon name="close" /></button>
        <h2 class="flex-1 font-semibold">{{ event ? t('calendar.editEvent') : t('calendar.newEvent') }}</h2>
        <template v-if="!readonly">
          <button v-if="event" type="button" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-danger" :title="t('actions.trash')" @click="onDeleteClick"><AppIcon name="trash" /></button>
          <button type="submit" :disabled="saving || loading" class="rounded-xl bg-accent px-4 py-2 font-medium text-white disabled:opacity-60">{{ t('calendar.save') }}</button>
        </template>
      </header>

      <div v-if="loading" class="space-y-3 p-5">
        <div class="h-10 animate-pulse rounded-xl bg-sunken" />
        <div class="h-24 animate-pulse rounded-xl bg-sunken" />
      </div>

      <fieldset v-else :disabled="readonly" class="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
        <p v-if="readonly" class="flex items-center gap-2 rounded-xl bg-sunken px-3 py-2 text-sm text-muted">
          <AppIcon name="lock" :size="16" class="shrink-0" />{{ t('calendar.readOnly', { name: ownCalendar.name }) }}
        </p>
        <div class="flex items-center gap-3">
          <span class="size-3 shrink-0 rounded-full" :style="{ background: calendarColor }" />
          <input ref="titleInput" v-model="form.title" :placeholder="t('calendar.titlePlaceholder')" class="min-w-0 flex-1 bg-transparent text-xl font-semibold outline-none" />
        </div>

        <p v-if="event?.recurring && !readonly" class="flex items-center gap-2 rounded-xl bg-sunken px-3 py-2 text-sm text-muted">
          <AppIcon name="repeat" :size="16" class="shrink-0" />{{ t('calendar.editingSeries') }}
        </p>

        <label class="flex items-center justify-between gap-3">
          <span>{{ t('calendar.allDay') }}</span>
          <input v-model="form.all_day" type="checkbox" class="size-5 accent-accent" />
        </label>

        <div class="grid grid-cols-[3.5rem_1fr_auto] items-center gap-2">
          <span class="text-sm text-muted">{{ t('calendar.starts') }}</span>
          <input :value="form.date" type="date" required :class="field" @change="onStartChange('date', $event.target.value)" />
          <input v-if="!form.all_day" :value="form.time" type="time" required :class="field" class="w-28" @change="onStartChange('time', $event.target.value)" />
          <span v-else />
          <span class="text-sm text-muted">{{ t('calendar.ends') }}</span>
          <input v-model="form.endDate" type="date" :min="form.date" :class="field" />
          <input v-if="!form.all_day" v-model="form.endTime" type="time" :min="sameDay ? form.time : undefined" :class="field" class="w-28" />
          <span v-else />
        </div>

        <label class="flex items-center gap-3">
          <AppIcon name="pin" :size="18" class="shrink-0 text-muted" />
          <input v-model="form.location" :placeholder="t('calendar.location')" :class="field" />
        </label>

        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block">
            <span class="mb-1 flex items-center gap-1.5 text-sm text-muted"><AppIcon name="bell" :size="15" />{{ t('calendar.reminder') }}</span>
            <select v-model="form.reminder" :class="field">
              <option v-for="[value, label] in reminderOptions" :key="value" :value="value">{{ label }}</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 flex items-center gap-1.5 text-sm text-muted"><AppIcon name="repeat" :size="15" />{{ t('calendar.repeat') }}</span>
            <select v-model="form.repeat" :class="field">
              <option v-for="[value, label] in repeatOptions" :key="value" :value="value">{{ label }}</option>
            </select>
          </label>
          <label v-if="form.repeat && form.repeat !== 'custom'" class="block sm:col-span-2">
            <span class="mb-1 block text-sm text-muted">{{ t('calendar.until') }}</span>
            <input v-model="form.until" type="date" :min="minUntil" :class="field" />
          </label>
        </div>

        <label class="block">
          <span class="mb-1 block text-sm text-muted">{{ t('calendar.calendar') }}</span>
          <select v-model="form.calendar_id" :class="field">
            <option v-for="c in calendarChoices" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm text-muted">{{ t('calendar.notes') }}</span>
          <textarea v-model="form.description" rows="3" :class="field" />
        </label>
      </fieldset>

      <!-- Delete a recurring event: this one or the whole series -->
      <div v-if="askDelete" class="border-t border-line p-4">
        <p class="mb-3 font-medium">{{ t('calendar.deleteRecurring') }}</p>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="rounded-xl bg-sunken px-4 py-2 hover:bg-line" @click="remove(true)">{{ t('calendar.deleteThis') }}</button>
          <button type="button" class="rounded-xl bg-danger px-4 py-2 text-white" @click="remove(false)">{{ t('calendar.deleteSeries') }}</button>
          <button type="button" class="rounded-xl px-4 py-2 text-muted hover:text-ink" @click="askDelete = false">{{ t('calendar.cancel') }}</button>
        </div>
      </div>
    </form>
  </div>
</template>
