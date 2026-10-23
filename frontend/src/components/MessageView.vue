<script setup>
import { ref, watch, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import LabelMenu from './LabelMenu.vue'
import ActionMenu from './ActionMenu.vue'
import { messageActions, tidy, makeTask as makeTaskFrom } from '../messageActions.js'
import { labelsOfMessage, labelName } from '../labels.js'
import AppIcon from './AppIcon.vue'
import FolderPicker from './FolderPicker.vue'
import MessageBody from './MessageBody.vue'
import QuickReply from './QuickReply.vue'
import { useMail, isSmallDevice } from '../stores/mail.js'
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
function makeTask() {
  if (summary.value) makeTaskFrom(summary.value, router)
}

// ---- toolbar size: larger touch targets on phones and tablets
const touch = ref(isSmallDevice())
const onResize = () => (touch.value = isSmallDevice())
onMounted(() => window.addEventListener('resize', onResize))
onBeforeUnmount(() => window.removeEventListener('resize', onResize))
const iconSize = computed(() => (touch.value ? 23 : 20))
const btn = computed(() => (touch.value ? 'rounded-xl p-2.5' : 'rounded-lg p-2'))

// ⋮ on small screens: the actions that do not fit in the bar
const moreMenu = ref(null)
// Actions already in the bar: on a phone archive/delete/unread, on a larger screen all icons.
const PHONE_BAR = ['archive', 'trash', 'read', 'reply', 'replyAll', 'forward']
const WIDE_BAR = [...PHONE_BAR, 'junk', 'unsnooze', 'snooze', 'move', 'task', 'labels', 'flag']
function openMore(e) {
  const rect = e.currentTarget.getBoundingClientRect()
  const inBar = matchMedia('(min-width: 768px)').matches ? WIDE_BAR : PHONE_BAR
  const all = messageActions({
    messages: [summary.value],
    extra: loaded.value ? [loaded.value] : [],
    loadedMessages: [loaded.value, newestLoaded.value].filter(Boolean),
    muted: !!(loaded.value?.muted ?? newestLoaded.value?.muted),
    router,
    onMove: () => (picking.value = true),
    onMoreFromSender: (email) => { emit('back'); mail.searchFrom(email) },
  })
  const items = tidy(all.filter((item) => !item || !inBar.includes(item.id)))
  moreMenu.value = { items, x: rect.right, y: rect.bottom + 4 }
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
const loaded = ref(null) // the open message as the reader loaded it (single message)
const newestLoaded = ref(null) // newest message of a conversation, once its card has loaded
watch(targetKey, () => { loaded.value = null; newestLoaded.value = null })
/** The message a quick reply answers: the newest one of the conversation, or the single message. */
const replyTarget = computed(() => (isConversation.value ? newestLoaded.value : loaded.value))
function onItemLoaded(m, data) {
  const list = items.value
  if (list.length && itemKey(list[list.length - 1]) === itemKey(m)) newestLoaded.value = data
}
const readerLabels = computed(() => labelsOfMessage(session.settings, loaded.value ?? summary.value))

function onSingleLoaded(data) {
  loaded.value = data
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
  // Removing the message closes the reader (or opens the next one): MailView watches the open message.
  await mail.act(action, summary.value)
}

const picking = ref(false)
function moveTo({ path, label }) {
  picking.value = false
  if (summary.value) mail.moveTo([summary.value], path, label)
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
  if (summary.value) mail.unsnooze([summary.value])
}

function reply(payload) {
  emit('reply', payload)
}
</script>

<template>
  <section class="flex h-full min-h-0 flex-col bg-surface">
    <header class="pt-safe flex items-center gap-0.5 border-b border-line px-2 py-1.5 sm:gap-1 sm:py-2">
      <button v-if="target" :class="btn" class="text-muted hover:bg-sunken hover:text-ink" :aria-label="t('actions.back')" @click="emit('back')">
        <AppIcon name="back" :size="iconSize" />
      </button>
      <div class="flex-1" />
      <template v-if="summary">
        <!-- Always visible: archive, delete, unread (like Gmail on a phone). The rest: in ⋮ on small screens. -->
        <button v-if="role !== 'archive'" :class="btn" class="text-muted hover:bg-sunken hover:text-ink" :title="t('actions.archive')" :aria-label="t('actions.archive')" @click="act('archive')"><AppIcon name="archive" :size="iconSize" /></button>
        <button :class="btn" class="text-muted hover:bg-sunken hover:text-danger" :title="t('actions.trash')" :aria-label="t('actions.trash')" @click="act('trash')"><AppIcon name="trash" :size="iconSize" /></button>
        <button
          v-if="!['sent', 'drafts', 'scheduled'].includes(mail.folderRole)"
          :class="btn" class="text-muted hover:bg-sunken hover:text-ink max-md:hidden"
          :title="mail.folderRole === 'junk' ? t('actions.notJunk') : t('actions.junk')"
          @click="act('junk')"
        >
          <AppIcon :name="mail.folderRole === 'junk' ? 'inbox' : 'junk'" :size="iconSize" />
        </button>
        <button v-if="role === 'snoozed'" :class="btn" class="text-muted hover:bg-sunken hover:text-ink max-md:hidden" :title="t('actions.unsnooze')" @click="unsnooze"><AppIcon name="inbox" :size="iconSize" /></button>
        <button v-if="!['drafts', 'sent', 'scheduled'].includes(role)" :class="btn" class="text-muted hover:bg-sunken hover:text-ink max-md:hidden" :title="role === 'snoozed' ? t('later.otherTime') : t('actions.snooze')" @click="mail.snoozeRequest = [summary]"><AppIcon name="clock" :size="iconSize" /></button>
        <button :class="btn" class="text-muted hover:bg-sunken hover:text-ink max-md:hidden" :title="t('actions.move')" @click="picking = true"><AppIcon name="move" :size="iconSize" /></button>
        <button :class="btn" class="text-muted hover:bg-sunken hover:text-ink max-md:hidden" :title="t('tasks.fromMail')" @click="makeTask"><AppIcon name="task" :size="iconSize" /></button>
        <LabelMenu v-if="session.settings.labels?.length && (summary || loaded)" class="max-md:hidden" :messages="summary ? [summary] : []" :extra="loaded ? [loaded] : []" />
        <button :class="btn" class="text-muted hover:bg-sunken hover:text-ink" :title="t('actions.markUnread')" :aria-label="t('actions.markUnread')" @click="mail.setFlag(summary, 'seen', false); emit('back')"><AppIcon name="unread" :size="iconSize" /></button>
        <button :class="btn" class="hover:bg-sunken max-md:hidden" :title="t('actions.flag')" @click="mail.setFlag(summary, 'flagged', !summary.flagged)">
          <AppIcon name="flag" :size="iconSize" :class="summary.flagged ? 'fill-current text-flag' : 'text-muted hover:text-ink'" />
        </button>
        <button :class="btn" class="text-muted hover:bg-sunken hover:text-ink" :title="t('menu.more')" :aria-label="t('menu.more')" :aria-expanded="!!moreMenu" @click="openMore">
          <AppIcon name="more" :size="iconSize" />
        </button>
      </template>
    </header>
    <ActionMenu v-if="moreMenu" :items="moreMenu.items" :x="moreMenu.x" :y="moreMenu.y" align="right" @close="moreMenu = null" />

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

        <h2 v-if="titleReady" class="text-[1.375rem] leading-snug font-semibold text-balance">
          {{ subject || t('list.noSubject') }}
          <span v-if="isConversation" class="ml-1 align-middle text-sm font-normal text-muted">{{ t('reader.inConversation', { n: items.length }) }}</span>
        </h2>
        <div v-if="titleReady && readerLabels.length" class="mt-2 flex flex-wrap gap-1.5">
          <span
            v-for="l in readerLabels" :key="l.key"
            class="flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
            :style="{ background: l.color + '22', color: l.color }"
          ><span class="size-1.5 rounded-full" :style="{ background: l.color }" />{{ labelName(l) }}</span>
        </div>

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
                @loaded="(d) => onItemLoaded(row.m, d)"
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
        <MessageBody v-else class="mt-4" :target="target" @loaded="onSingleLoaded" @reply="reply" />

        <QuickReply v-if="replyTarget && mail.folderRole !== 'drafts'" :message="replyTarget" class="mb-8" @reply="reply" />
      </div>
    </article>

    <FolderPicker v-if="picking && target" :account-id="target.account_id" :exclude="target.folder" @close="picking = false" @pick="moveTo" />
  </section>
</template>
