<script setup>
import { ref, computed, onMounted } from 'vue'
import AppIcon from './AppIcon.vue'
import { t, locale } from '../i18n/index.js'

/**
 * Choose a moment: for snoozing (mode "snooze") or for sending later (mode "send").
 * Quick choices like Gmail, plus a date/time field. Emits pick(Date) or close.
 */
const props = defineProps({
  mode: { type: String, default: 'snooze' }, // snooze | send
  count: { type: Number, default: 1 },
})
const emit = defineEmits(['pick', 'close'])

const at = (base, days, hour, minute = 0) => {
  const d = new Date(base)
  d.setDate(d.getDate() + days)
  d.setHours(hour, minute, 0, 0)
  return d
}
const nextWeekday = (weekday, hour) => {
  // weekday: 0 = Sunday … 6 = Saturday; always in the future (next week when it is that day today)
  const now = new Date()
  const days = ((weekday - now.getDay() + 7) % 7) || 7
  return at(now, days, hour)
}

const options = computed(() => {
  const now = new Date()
  const list = []
  if (props.mode === 'snooze') {
    if (now.getHours() < 17) list.push({ key: 'laterToday', date: at(now, 0, 18) })
    list.push({ key: 'tomorrow', date: at(now, 1, 8) })
    if (now.getDay() >= 1 && now.getDay() <= 4) list.push({ key: 'weekend', date: nextWeekday(6, 8) })
    // On Sunday "next week" is tomorrow: then it would only repeat "Morgen".
    const monday = nextWeekday(1, 8)
    if (monday.toDateString() !== at(now, 1, 8).toDateString()) list.push({ key: 'nextWeek', date: monday })
  } else {
    list.push({ key: 'tomorrowMorning', date: at(now, 1, 8) })
    list.push({ key: 'tomorrowAfternoon', date: at(now, 1, 13) })
    const monday = nextWeekday(1, 8)
    if (monday.toDateString() !== at(now, 1, 8).toDateString()) list.push({ key: 'mondayMorning', date: monday })
  }
  return list
})

const label = (d) => d.toLocaleString(locale(), { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })

// ---- custom date/time (datetime-local works with local time, without zone)
const pad = (n) => String(n).padStart(2, '0')
const local = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
const custom = ref(local(at(new Date(), 1, 8)))
const min = local(new Date(Date.now() + 5 * 60 * 1000))
const error = ref('')
const first = ref(null)

function pickCustom() {
  const d = new Date(custom.value)
  if (Number.isNaN(d.getTime()) || d.getTime() < Date.now() + 60 * 1000) {
    error.value = t('later.inPast')
    return
  }
  emit('pick', d)
}

const title = computed(() => {
  if (props.mode === 'send') return t('later.sendTitle')
  return props.count > 1 ? t('later.snoozeTitleMany', { n: props.count }) : t('later.snoozeTitle')
})

onMounted(() => first.value?.focus())
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-end justify-center bg-ink/40 sm:items-center sm:p-4" @click.self="emit('close')" @keydown.esc="emit('close')">
    <div class="pb-safe w-full rounded-t-2xl bg-surface shadow-2xl ring-1 ring-line sm:max-w-sm sm:rounded-2xl" role="dialog" :aria-label="title">
      <header class="flex items-center gap-2 border-b border-line px-4 py-3">
        <AppIcon :name="mode === 'send' ? 'scheduled' : 'clock'" :size="19" class="text-muted" />
        <h2 class="flex-1 font-semibold">{{ title }}</h2>
        <button class="rounded-lg p-1.5 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('settings.cancel')" @click="emit('close')">
          <AppIcon name="close" />
        </button>
      </header>
      <ul class="py-1">
        <li v-for="(o, i) in options" :key="o.key">
          <button
            :ref="(el) => i === 0 && (first = el)"
            class="flex w-full items-center justify-between gap-4 px-4 py-3 text-left hover:bg-sunken focus:bg-sunken focus:outline-none"
            @click="emit('pick', o.date)"
          >
            <span>{{ t(`later.${o.key}`) }}</span>
            <span class="text-sm text-muted tabular-nums">{{ label(o.date) }}</span>
          </button>
        </li>
      </ul>
      <form class="space-y-2 border-t border-line px-4 py-3" @submit.prevent="pickCustom">
        <label class="block text-sm text-muted" for="pv-custom-time">{{ t('later.custom') }}</label>
        <div class="flex gap-2">
          <input
            id="pv-custom-time"
            v-model="custom"
            type="datetime-local"
            :min="min"
            class="min-w-0 flex-1 rounded-lg border border-line bg-surface px-3 py-2 outline-none focus:border-accent"
            @input="error = ''"
          />
          <button class="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white">{{ t('later.pick') }}</button>
        </div>
        <p v-if="error" class="text-sm text-danger">{{ error }}</p>
        <p class="text-xs text-muted">{{ t('later.cronNote') }}</p>
      </form>
    </div>
  </div>
</template>
