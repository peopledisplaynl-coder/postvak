<script setup>
import { ref, computed } from 'vue'
import AppIcon from './AppIcon.vue'
import { api } from '../api/client.js'
import { useToasts } from '../stores/toasts.js'
import { parseYmd } from '../calendar.js'
import { locale, t } from '../i18n/index.js'

/**
 * Meeting invitation inside a message: what, when, where, and the answer buttons.
 * The answer goes into the calendar and (optionally) back to the organizer.
 */
const props = defineProps({
  invitation: { type: Object, required: true },
  target: { type: Object, required: true }, // { account_id, folder, uid }
})
const toasts = useToasts()

const inv = ref({ ...props.invitation })
const busy = ref('')
const sendReply = ref(true)

const kind = computed(() => (inv.value.method === 'cancel' ? 'cancel' : inv.value.method === 'publish' ? 'publish' : 'request'))
const canAnswer = computed(() => kind.value === 'request' && !inv.value.own)

const when = computed(() => {
  const i = inv.value
  const loc = locale()
  if (i.all_day) {
    const s = parseYmd(i.start)
    const e = parseYmd(i.end)
    const fmt = { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }
    return i.start === i.end ? s.toLocaleDateString(loc, fmt) : `${s.toLocaleDateString(loc, fmt)} – ${e.toLocaleDateString(loc, fmt)}`
  }
  const s = new Date(i.start)
  const e = new Date(i.end)
  const date = s.toLocaleDateString(loc, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
  const time = (d) => d.toLocaleTimeString(loc, { hour: '2-digit', minute: '2-digit' })
  return s.toDateString() === e.toDateString()
    ? `${date}, ${time(s)} – ${time(e)}`
    : `${date}, ${time(s)} – ${e.toLocaleDateString(loc, { day: 'numeric', month: 'long' })} ${time(e)}`
})

const status = computed(() => {
  const i = inv.value
  if (kind.value === 'cancel') return i.in_calendar ? t('invite.cancelledInCalendar') : t('invite.cancelled')
  if (!i.in_calendar) return i.calendar_status === 'declined' ? t('invite.statusDeclined') : ''
  return { accepted: t('invite.statusAccepted'), tentative: t('invite.statusTentative') }[i.calendar_status] ?? t('invite.inCalendar')
})

async function answer(response) {
  busy.value = response
  try {
    const res = await api.post(`/accounts/${props.target.account_id}/messages/${props.target.uid}/invitation`, {
      folder: props.target.folder,
      response,
      reply: canAnswer.value && sendReply.value,
    })
    inv.value = { ...inv.value, in_calendar: res.in_calendar, calendar_status: res.calendar_status ?? (response === 'declined' ? 'declined' : null) }
    if (res.reply_error) toasts.error(t('invite.replyFailed', { msg: res.reply_error }))
    else toasts.show(response === 'remove' ? t('invite.removed') : res.replied ? t('invite.answered') : t('invite.saved'))
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    busy.value = ''
  }
}

const buttons = [
  ['accepted', 'invite.accept', 'check'],
  ['tentative', 'invite.maybe', 'none'],
  ['declined', 'invite.decline', 'close'],
]
</script>

<template>
  <section class="mt-5 overflow-hidden rounded-2xl ring-1 ring-line" :aria-label="t('invite.label')">
    <div class="flex gap-3 bg-sunken/60 px-4 py-3">
      <div class="flex size-11 shrink-0 flex-col items-center justify-center rounded-xl bg-surface text-center ring-1 ring-line" aria-hidden="true">
        <span class="text-[0.625rem] font-semibold uppercase leading-none text-danger">{{ new Date(inv.all_day ? parseYmd(inv.start) : inv.start).toLocaleDateString(locale(), { month: 'short' }) }}</span>
        <span class="text-lg font-semibold leading-tight">{{ new Date(inv.all_day ? parseYmd(inv.start) : inv.start).getDate() }}</span>
      </div>
      <div class="min-w-0 flex-1">
        <p class="text-xs font-medium uppercase tracking-wide" :class="kind === 'cancel' ? 'text-danger' : 'text-muted'">
          {{ kind === 'cancel' ? t('invite.kindCancel') : kind === 'publish' ? t('invite.kindPublish') : t('invite.kindRequest') }}
        </p>
        <h3 class="font-semibold break-words" :class="kind === 'cancel' ? 'line-through decoration-danger/60' : ''">{{ inv.title || t('calendar.untitled') }}</h3>
        <p class="text-sm">{{ when }}<span v-if="inv.recurring" class="text-muted"> · {{ t('invite.recurring') }}</span></p>
        <p v-if="inv.location" class="flex items-center gap-1 text-sm text-muted"><AppIcon name="pin" :size="14" class="shrink-0" /><span class="truncate">{{ inv.location }}</span></p>
        <p v-if="inv.organizer" class="text-sm text-muted">
          {{ t('invite.organizer', { name: inv.organizer.name || inv.organizer.email }) }}<template v-if="inv.attendees > 1"> · {{ t('invite.attendees', { n: inv.attendees }) }}</template>
        </p>
      </div>
    </div>

    <div class="space-y-3 px-4 py-3">
      <p v-if="status" class="flex items-center gap-2 text-sm" :class="inv.calendar_status === 'declined' || kind === 'cancel' ? 'text-muted' : 'text-archive'">
        <AppIcon :name="inv.in_calendar ? 'calendar' : 'none'" :size="16" class="shrink-0" />{{ status }}
      </p>

      <template v-if="canAnswer">
        <div class="flex flex-wrap gap-2">
          <button
            v-for="[response, label, icon] in buttons"
            :key="response"
            class="flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-sm font-medium ring-1 transition disabled:opacity-60"
            :class="inv.calendar_status === response
              ? 'bg-accent text-white ring-accent'
              : 'ring-line hover:bg-sunken'"
            :disabled="!!busy"
            :aria-pressed="inv.calendar_status === response"
            @click="answer(response)"
          >
            <AppIcon :name="icon" :size="15" />{{ t(label) }}
          </button>
        </div>
        <label v-if="inv.organizer" class="flex items-center gap-2 text-sm text-muted">
          <input v-model="sendReply" type="checkbox" class="size-4 accent-accent" />
          {{ t('invite.sendReply', { name: inv.organizer.name || inv.organizer.email }) }}
        </label>
      </template>

      <button
        v-else-if="kind === 'cancel' && inv.in_calendar"
        class="rounded-xl px-3 py-1.5 text-sm font-medium text-danger ring-1 ring-line hover:bg-danger/10 disabled:opacity-60"
        :disabled="!!busy" @click="answer('remove')"
      >{{ t('invite.removeFromCalendar') }}</button>

      <button
        v-else-if="kind === 'publish' && !inv.in_calendar"
        class="flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-sm font-medium ring-1 ring-line hover:bg-sunken disabled:opacity-60"
        :disabled="!!busy" @click="answer('accepted')"
      ><AppIcon name="plus" :size="15" />{{ t('invite.addToCalendar') }}</button>
    </div>
  </section>
</template>
