<script setup>
import { ref, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from './AppIcon.vue'
import FolderPicker from './FolderPicker.vue'
import MessageBody from './MessageBody.vue'
import { useMail } from '../stores/mail.js'
import { useSession } from '../stores/session.js'
import { useToasts } from '../stores/toasts.js'
import { api } from '../api/client.js'
import { listDate, displayName, avatarColor, initials } from '../format.js'
import { t, locale } from '../i18n/index.js'

/**
 * Reading pane. target = { account_id, folder, uid } of the message (or of the newest
 * message of a conversation), or null.
 *
 * A conversation is shown as cards, oldest first: the newest and unread messages are
 * open, the rest are one line each and open on a click. The toolbar acts on the whole
 * list row (so on the whole conversation in this folder).
 */
const props = defineProps({ target: { type: Object, default: null } })
const emit = defineEmits(['back', 'reply', 'edit-draft'])

const mail = useMail()
const session = useSession()
const toasts = useToasts()
const router = useRouter()

/** Mail → task: a task with a link back to this message. */
async function makeTask() {
  const s = summary.value
  if (!s) return
  try {
    await api.post('/tasks', {
      title: s.subject || t('list.noSubject'),
      mail: { account_id: s.account_id, folder: s.folder, uid: s.uid, subject: s.subject, from: displayName(s.from) },
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    })
    toasts.show(t('tasks.createdFromMail'), { action: { label: t('tasks.show'), run: () => router.push('/tasks') } })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

const targetKey = computed(() => (props.target ? `${props.target.account_id}:${props.target.uid}` : null))
const summary = computed(() => (targetKey.value ? mail.byKey(targetKey.value) : null))

// ---- conversation
const items = ref([])          // summaries, oldest first (only when it is a real conversation)
const open = ref(new Set())     // keys of the cards that are open
const showAll = ref(false)      // middle part of a long conversation unfolded
const loadingConversation = ref(false)
const subject = ref('')
const titleReady = ref(false)  // subject known (from the list row, conversation or loaded message)

const itemKey = (m) => `${m.folder}:${m.uid}`
const isConversation = computed(() => items.value.length > 1)

async function loadConversation() {
  items.value = []
  open.value = new Set()
  showAll.value = false
  subject.value = summary.value?.subject ?? ''
  titleReady.value = !!summary.value
  if (!props.target) return
  // Only ask the server when the row is a conversation, or when the list row is unknown
  // (page opened with ?uid=…) and conversations are switched on.
  const wanted = summary.value ? (summary.value.thread?.count ?? 1) > 1 : session.settings.conversations !== false && mail.folderRole !== 'drafts'
  if (!wanted) return
  const requested = targetKey.value
  loadingConversation.value = true
  try {
    const list = await mail.fetchConversation(props.target)
    if (requested !== targetKey.value) return
    if (list.length > 1) {
      items.value = list
      subject.value = list[0].subject || subject.value
      titleReady.value = true
      const newest = list[list.length - 1]
      open.value = new Set([itemKey(newest), ...list.filter((m) => !m.seen).map(itemKey)])
      markConversationRead(list)
    }
  } catch (e) {
    // Not fatal: fall back to the single message.
    console.warn('[postvak] conversation failed', e)
  } finally {
    loadingConversation.value = false
  }
}
watch(targetKey, loadConversation, { immediate: true })

function markConversationRead(list) {
  if (!session.settings.mark_read_on_open) return
  if (summary.value) {
    if (!summary.value.seen) mail.setFlag(summary.value, 'seen', true)
    return
  }
  const unseen = list.filter((m) => !m.seen && m.folder === props.target.folder).map((m) => m.uid)
  if (unseen.length) api.post(`/accounts/${props.target.account_id}/flags`, { folder: props.target.folder, uids: unseen, seen: true }).catch(() => {})
}

/** Single message loaded: mark it read (like before). */
function onSingleLoaded(data) {
  if (!titleReady.value) subject.value = data.subject
  titleReady.value = true
  if (!data.seen && session.settings.mark_read_on_open && summary.value) mail.setFlag(summary.value, 'seen', true)
}

/** Long conversations: first message, "N earlier messages", then the last two. */
const visible = computed(() => {
  const list = items.value
  if (showAll.value || list.length <= 4) return list.map((m) => ({ m }))
  const hiddenOpen = list.slice(1, -2).some((m) => open.value.has(itemKey(m)))
  if (hiddenOpen) return list.map((m) => ({ m }))
  return [{ m: list[0] }, { more: list.length - 3 }, ...list.slice(-2).map((m) => ({ m }))]
})

function toggle(m) {
  const k = itemKey(m)
  const next = new Set(open.value)
  next.has(k) ? next.delete(k) : next.add(k)
  open.value = next
}

const sentPath = computed(() => (mail.folders[props.target?.account_id] ?? []).find((f) => f.role === 'sent')?.path)
const own = (m) => session.accounts.some((a) => a.email.toLowerCase() === m.from?.email?.toLowerCase())
const fromLabel = (m) => (own(m) ? t('list.me') : displayName(m.from))

// ---- toolbar (acts on the list row = the whole conversation in this folder)
async function act(action) {
  if (!summary.value) return
  const done = await mail.act(action, summary.value)
  if (done && (action === 'archive' || action === 'trash' || action === 'junk')) emit('back')
}

const picking = ref(false)
function moveTo({ path, label }) {
  picking.value = false
  if (summary.value && mail.moveTo([summary.value], path, label)) emit('back')
}

// ---- scheduled / snoozed
const role = computed(() => mail.folderRole)
const fmt = (iso) => new Date(iso).toLocaleString(locale(), { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })
const busy = ref(false)

async function scheduledAction(kind) {
  const sched = summary.value?.scheduled
  if (!sched) return
  busy.value = true
  try {
    if (kind === 'send') {
      await api.post(`/scheduled/${sched.id}/send`)
      toasts.show(t('toast.sent'))
      mail.dropRow(summary.value)
      emit('back')
    } else {
      const draft = await api.post(`/scheduled/${sched.id}/cancel`)
      toasts.show(t('toast.scheduleCancelled'))
      mail.dropRow(summary.value)
      emit('back')
      if (draft?.uid) emit('edit-draft', draft)
    }
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    busy.value = false
  }
}

function unsnooze() {
  if (summary.value && mail.unsnooze([summary.value])) emit('back')
}

function reply(payload) {
  emit('reply', payload)
}
</script>

<template>
  <section class="flex h-full min-h-0 flex-col bg-surface">
    <header class="pt-safe flex items-center gap-1 border-b border-line px-2 py-2">
      <button v-if="target" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('actions.back')" @click="emit('back')">
        <AppIcon name="back" />
      </button>
      <div class="flex-1" />
      <template v-if="summary">
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.archive')" @click="act('archive')"><AppIcon name="archive" /></button>
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-danger" :title="t('actions.trash')" @click="act('trash')"><AppIcon name="trash" /></button>
        <button
          v-if="!['sent', 'drafts', 'scheduled'].includes(mail.folderRole)"
          class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink"
          :title="mail.folderRole === 'junk' ? t('actions.notJunk') : t('actions.junk')"
          @click="act('junk')"
        >
          <AppIcon :name="mail.folderRole === 'junk' ? 'inbox' : 'junk'" />
        </button>
        <button v-if="role === 'snoozed'" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.unsnooze')" @click="unsnooze"><AppIcon name="inbox" /></button>
        <button v-if="!['drafts', 'sent', 'scheduled'].includes(role)" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="role === 'snoozed' ? t('later.otherTime') : t('actions.snooze')" @click="mail.snoozeRequest = [summary]"><AppIcon name="clock" /></button>
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.move')" @click="picking = true"><AppIcon name="move" /></button>
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('tasks.fromMail')" @click="makeTask"><AppIcon name="task" /></button>
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.markUnread')" @click="mail.setFlag(summary, 'seen', false); emit('back')"><AppIcon name="unread" /></button>
        <button class="rounded-lg p-2 hover:bg-sunken" :class="summary.flagged ? 'text-flag' : 'text-muted hover:text-ink'" :title="t('actions.flag')" @click="mail.setFlag(summary, 'flagged', !summary.flagged)">
          <AppIcon name="flag" :class="summary.flagged ? 'fill-current' : ''" />
        </button>
      </template>
    </header>

    <div v-if="!target" class="flex flex-1 items-center justify-center p-8 text-muted">
      {{ t('reader.select') }}
    </div>

    <div v-else-if="loadingConversation" class="space-y-3 p-6">
      <div class="h-6 w-2/3 animate-pulse rounded bg-sunken" />
      <div class="h-4 w-1/3 animate-pulse rounded bg-sunken" />
      <div class="mt-8 h-40 animate-pulse rounded bg-sunken" />
    </div>

    <article v-else class="min-h-0 flex-1 overflow-y-auto">
      <div class="mx-auto max-w-3xl px-5 py-5 sm:px-8">
        <!-- Scheduled: when, send now, or back to drafts -->
        <div v-if="summary?.scheduled" class="mb-4 flex flex-wrap items-center gap-3 rounded-2xl bg-snooze/10 px-4 py-3">
          <AppIcon name="scheduled" :size="20" class="shrink-0 text-snooze" />
          <span class="min-w-0 flex-1">{{ t('later.scheduledBanner', { date: fmt(summary.scheduled.send_at) }) }}</span>
          <button class="rounded-lg bg-surface px-3 py-1.5 text-sm font-medium ring-1 ring-line hover:bg-sunken disabled:opacity-60" :disabled="busy" @click="scheduledAction('cancel')">{{ t('later.cancelEdit') }}</button>
          <button class="rounded-lg bg-accent px-3 py-1.5 text-sm font-medium text-white disabled:opacity-60" :disabled="busy" @click="scheduledAction('send')">{{ t('later.sendNow') }}</button>
        </div>
        <!-- Snoozed: until when -->
        <div v-else-if="summary?.snoozed_until" class="mb-4 flex flex-wrap items-center gap-3 rounded-2xl bg-snooze/10 px-4 py-3">
          <AppIcon name="clock" :size="20" class="shrink-0 text-snooze" />
          <span class="min-w-0 flex-1">{{ t('later.snoozedBanner', { date: fmt(summary.snoozed_until) }) }}</span>
          <button class="rounded-lg bg-surface px-3 py-1.5 text-sm font-medium ring-1 ring-line hover:bg-sunken" @click="mail.snoozeRequest = [summary]">{{ t('later.otherTime') }}</button>
          <button class="rounded-lg bg-accent px-3 py-1.5 text-sm font-medium text-white" @click="unsnooze">{{ t('actions.unsnooze') }}</button>
        </div>

        <h2 v-if="titleReady" class="text-[22px] leading-snug font-semibold text-balance">
          {{ subject || t('list.noSubject') }}
          <span v-if="isConversation" class="ml-1 align-middle text-sm font-normal text-muted">{{ t('reader.inConversation', { n: items.length }) }}</span>
        </h2>

        <!-- Conversation -->
        <ol v-if="isConversation" class="mt-4 divide-y divide-line overflow-hidden rounded-2xl ring-1 ring-line">
          <li v-for="(row, i) in visible" :key="row.m ? itemKey(row.m) : 'more' + i">
            <button
              v-if="row.more"
              class="flex w-full items-center justify-center gap-2 bg-sunken/50 py-2 text-sm text-muted hover:bg-sunken hover:text-ink"
              @click="showAll = true"
            >
              <span class="rounded-full bg-surface px-2.5 py-0.5 text-xs font-semibold ring-1 ring-line">{{ row.more }}</span>
              {{ t('reader.earlierMessages', { n: row.more }) }}
            </button>

            <div v-else-if="open.has(itemKey(row.m))" class="px-4 py-4 sm:px-5">
              <MessageBody
                :target="row.m"
                collapsible
                :badge="row.m.folder === sentPath && row.m.folder !== target.folder ? t('folders.sent') : ''"
                @collapse="toggle(row.m)"
                @reply="reply"
              />
            </div>

            <button v-else class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-sunken/60 sm:px-5" @click="toggle(row.m)">
              <span
                class="flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold text-white"
                :style="{ background: avatarColor(row.m.from?.email) }"
              >{{ initials(row.m.from) }}</span>
              <span class="min-w-0 flex-1">
                <span class="flex items-baseline gap-2">
                  <span class="truncate text-sm" :class="row.m.seen ? '' : 'font-semibold'">{{ fromLabel(row.m) }}</span>
                  <span class="ml-auto shrink-0 text-xs text-muted">{{ listDate(row.m.date) }}</span>
                </span>
                <span class="block truncate text-sm text-muted">{{ row.m.snippet }}</span>
              </span>
            </button>
          </li>
        </ol>

        <!-- Single message -->
        <MessageBody v-else class="mt-4 pb-8" :target="target" @loaded="onSingleLoaded" @reply="reply" />
      </div>
    </article>

    <FolderPicker v-if="picking && target" :account-id="target.account_id" :exclude="target.folder" @close="picking = false" @pick="moveTo" />
  </section>
</template>
