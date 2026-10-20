<script setup>
import { computed, ref } from 'vue'
import AppIcon from './AppIcon.vue'
import { useSwipe } from '../composables/useSwipe.js'
import { useSession } from '../stores/session.js'
import { useMail, msgKey, uidsOf } from '../stores/mail.js'
import { useToasts } from '../stores/toasts.js'
import { listDate, laterDate, displayName, avatarColor, initials } from '../format.js'
import { t, locale } from '../i18n/index.js'
import { labelsOfMessage, labelName } from '../labels.js'

const props = defineProps({
  message: { type: Object, required: true },
  active: { type: Boolean, default: false },
})
const emit = defineEmits(['open', 'context'])

const session = useSession()
const mail = useMail()

const leftAction = computed(() => session.settings.swipe_left ?? 'trash')
const rightAction = computed(() => session.settings.swipe_right ?? 'archive')

const key = computed(() => msgKey(props.message))
const selected = computed(() => mail.selected.includes(key.value))
const account = computed(() => session.accountById(props.message.account_id))
const labels = computed(() => labelsOfMessage(session.settings, props.message))

const { offset, dragging, flying, direction, progress, armed, handlers: swipe } = useSwipe({
  // No swiping while selecting several messages.
  enabledLeft: () => leftAction.value !== 'none' && !mail.selecting,
  enabledRight: () => rightAction.value !== 'none' && !mail.selecting,
  onCommit: async (dir) => {
    const action = dir === 'left' ? leftAction.value : rightAction.value
    const result = await mail.act(action, props.message)
    // Read/flag keep the row in place: spring back.
    if (action === 'read' || action === 'flag') return false
    return result
  },
})

// What is revealed behind the row while swiping.
const revealAction = computed(() => (direction.value === 'left' ? leftAction.value : rightAction.value))
const actionColor = {
  archive: 'var(--color-archive)',
  trash: 'var(--color-danger)',
  junk: 'var(--color-junk)',
  snooze: 'var(--color-snooze)',
  read: 'var(--color-read)',
  flag: 'var(--color-flag)',
  none: 'var(--color-muted)',
}
const revealIcon = computed(() => {
  if (revealAction.value === 'read') return props.message.seen ? 'unread' : 'read'
  if (revealAction.value === 'junk' && mail.folderRole === 'junk') return 'inbox'
  return revealAction.value
})

const person = computed(() => {
  // In Sent and Drafts, show the recipient instead of yourself.
  const role = mail.folderRole
  if ((role === 'sent' || role === 'drafts' || role === 'scheduled') && props.message.to?.length) return props.message.to[0]
  return props.message.from
})

// Conversation: "Peter, ik, Anja" (first names when there are several people) + count.
const isThread = computed(() => (props.message.thread?.count ?? 1) > 1)
const senderLabel = computed(() => {
  const people = props.message.thread?.participants ?? []
  if (!isThread.value || !people.length) return displayName(person.value)
  const short = people.length > 1
  const names = people.map((p) => (p.me ? t('list.me') : short ? (p.name || p.email).split(/[\s@]/)[0] : p.name || p.email))
  return names.length > 3 ? `${names[0]} … ${names.slice(-2).join(', ')}` : names.join(', ')
})

// Scheduled: when it will be sent. Snoozed: when it comes back.
const laterAt = computed(() => props.message.scheduled?.send_at ?? props.message.snoozed_until ?? null)
const canSnooze = computed(() => !['drafts', 'sent', 'scheduled', 'snoozed'].includes(mail.folderRole))

const compact = computed(() => session.settings.density === 'compact')

function quick(action) {
  mail.act(action, props.message)
}

// ---- long press (touch) selects the message, like in Gmail
let pressTimer = null
let pressStart = null
let longPressed = false
let lastPointer = 'mouse'
let dragged = false // pointer moved: the following click is not a tap

// ---- drag to a folder (mouse only: touch uses swipe and long press)
const draggable = ref(false)
const beingDragged = ref(false)

function onDragstart(e) {
  const drag = mail.startDrag(props.message)
  if (drag.accountId === null) {
    // Mixed accounts (unified inbox): a folder belongs to one account.
    e.preventDefault()
    mail.endDrag()
    useToasts().show(t('list.moveOneAccount'))
    return
  }
  beingDragged.value = true
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('application/x-postvak-messages', String(drag.messages.length))
  e.dataTransfer.setData('text/plain', drag.messages.map((m) => m.subject || '').join('\n'))
  // A compact label instead of a ghost of the whole row.
  const n = drag.messages.flatMap(uidsOf).length
  const ghost = document.createElement('div')
  ghost.textContent = n > 1 ? t('list.dragMany', { n }) : (props.message.subject || t('list.noSubject'))
  ghost.style.cssText = 'position:fixed;top:-1000px;left:0;max-width:260px;padding:6px 12px;border-radius:9999px;' +
    'background:var(--color-accent);color:#fff;font:500 13px/1.4 var(--font-sans,sans-serif);white-space:nowrap;overflow:hidden;text-overflow:ellipsis'
  document.body.appendChild(ghost)
  e.dataTransfer.setDragImage(ghost, 16, 16)
  setTimeout(() => ghost.remove(), 0)
}
function onDragend() {
  beingDragged.value = false
  mail.endDrag()
}

function onPointerdown(e) {
  lastPointer = e.pointerType
  draggable.value = e.pointerType === 'mouse' && e.button === 0
  swipe.onPointerdown(e)
  dragged = false
  if (e.pointerType === 'mouse') return
  longPressed = false
  pressStart = { x: e.clientX, y: e.clientY }
  clearTimeout(pressTimer)
  pressTimer = setTimeout(() => {
    longPressed = true
    navigator.vibrate?.(15)
    mail.toggleSelect(key.value)
  }, 450)
}
function onPointermove(e) {
  swipe.onPointermove(e)
  if (pressStart && Math.hypot(e.clientX - pressStart.x, e.clientY - pressStart.y) > 8) {
    clearTimeout(pressTimer)
    dragged = true
  }
}
function onPointerup(e) {
  clearTimeout(pressTimer)
  swipe.onPointerup(e)
}
function onPointercancel(e) {
  clearTimeout(pressTimer)
  swipe.onPointercancel(e)
}
/** Right-click (mouse only; on a touch screen a long press selects instead). */
function onContextmenu(e) {
  e.preventDefault()
  if (lastPointer !== 'mouse') return
  emit('context', { message: props.message, x: e.clientX, y: e.clientY })
}

function onClick() {
  // The click that ends a long press or a swipe is not a tap.
  if (longPressed) {
    longPressed = false
    return
  }
  if (dragged || Math.abs(offset.value) > 4 || flying.value) {
    dragged = false
    return
  }
  if (mail.selecting) mail.toggleSelect(key.value)
  else emit('open', props.message)
}
</script>

<template>
  <li class="relative overflow-hidden select-none" :class="flying ? 'pointer-events-none' : ''">
    <!-- Action revealed behind the row -->
    <div
      v-if="direction"
      class="absolute inset-0 flex items-center px-6 text-white"
      :class="direction === 'left' ? 'justify-end' : 'justify-start'"
      :style="{ background: actionColor[revealAction], opacity: 0.55 + progress * 0.45 }"
    >
      <span
        class="flex items-center gap-2 font-medium transition-transform duration-150"
        :style="{ transform: `scale(${armed ? 1.15 : 0.8 + progress * 0.2})` }"
      >
        <AppIcon :name="revealIcon" :size="24" />
        <span class="text-sm">{{ revealAction === 'junk' && mail.folderRole === 'junk' ? t('actions.notJunk') : t(`actions.${revealAction}`) }}</span>
      </span>
    </div>

    <!-- The row itself -->
    <div
      role="button"
      tabindex="0"
      :aria-pressed="mail.selecting ? selected : undefined"
      class="group relative flex gap-3 px-4 touch-pan-y cursor-pointer"
      :class="[
        compact ? 'py-2' : 'py-3',
        selected || active ? 'bg-accent-soft' : 'bg-surface hover:bg-sunken/60',
        !dragging ? 'transition-transform duration-200 ease-out' : '',
        beingDragged || (mail.drag && selected) ? 'opacity-50' : '',
      ]"
      :style="{ transform: `translateX(${offset}px)` }"
      :draggable="draggable"
      @dragstart="onDragstart"
      @dragend="onDragend"
      @pointerdown="onPointerdown"
      @pointermove="onPointermove"
      @pointerup="onPointerup"
      @pointercancel="onPointercancel"
      @click="onClick"
      @contextmenu="onContextmenu"
      @keydown.enter="onClick"
      @keydown.space.prevent="mail.toggleSelect(key)"
    >
      <!-- Avatar doubles as the select checkbox (click it on desktop, long press on touch) -->
      <button
        v-if="!compact"
        type="button"
        class="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white transition"
        :style="{ background: selected ? 'var(--color-accent)' : avatarColor(person?.email) }"
        :aria-label="t('list.select')"
        @click.stop="mail.toggleSelect(key)"
      >
        <AppIcon v-if="selected" name="check" :size="20" />
        <span v-else>{{ initials(person) }}</span>
      </button>
      <button
        v-else
        type="button"
        class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-md ring-1 transition"
        :class="selected ? 'bg-accent text-white ring-accent' : 'text-transparent ring-line group-hover:ring-muted'"
        :aria-label="t('list.select')"
        @click.stop="mail.toggleSelect(key)"
      >
        <AppIcon name="check" :size="14" />
      </button>

      <div class="min-w-0 flex-1">
        <div class="flex items-baseline gap-2">
          <span
            v-if="!message.seen"
            class="size-2 shrink-0 self-center rounded-full bg-accent"
            :aria-label="t('actions.markUnread')"
          />
          <span class="truncate" :class="message.seen ? 'text-ink/80' : 'font-semibold'">
            {{ senderLabel }}
          </span>
          <span v-if="isThread" class="shrink-0 text-xs tabular-nums text-muted" :title="t('list.inConversation', { n: message.thread.count })">{{ message.thread.count }}</span>
          <span
            v-if="mail.unified && account"
            class="size-2 shrink-0 self-center rounded-full"
            :style="{ background: account.color || 'var(--color-accent)' }"
            :title="account.email"
          />
          <span v-if="laterAt" class="ml-auto flex shrink-0 items-center gap-1 text-xs tabular-nums text-snooze" :title="t(message.scheduled ? 'later.scheduledBanner' : 'later.snoozedBanner', { date: new Date(laterAt).toLocaleString(locale()) })">
            <AppIcon :name="message.scheduled ? 'scheduled' : 'clock'" :size="13" />
            {{ laterDate(laterAt) }}
          </span>
          <span v-else class="ml-auto shrink-0 text-xs tabular-nums" :class="message.seen ? 'text-muted' : 'text-accent font-medium'">
            {{ listDate(message.date) }}
          </span>
        </div>
        <div class="flex items-center gap-2">
          <span class="truncate text-[0.875rem]" :class="message.seen ? 'text-muted' : 'text-ink'">
            {{ message.subject || t('list.noSubject') }}
            <span v-if="compact && message.snippet" class="text-muted"> – {{ message.snippet }}</span>
          </span>
          <span class="ml-auto flex shrink-0 items-center gap-1 text-muted">
            <span
              v-for="l in labels" :key="l.key"
              class="hidden max-w-24 truncate rounded-full px-1.5 py-px text-[0.6875rem] font-medium leading-4 sm:inline-block"
              :style="{ background: l.color + '22', color: l.color }"
            >{{ labelName(l) }}</span>
            <span v-for="l in labels" :key="'dot' + l.key" class="size-2 rounded-full sm:hidden" :style="{ background: l.color }" :title="labelName(l)" />
            <AppIcon v-if="message.answered" name="reply" :size="14" />
            <AppIcon v-if="message.has_attachments" name="paperclip" :size="14" />
            <AppIcon v-if="message.flagged" name="flag" :size="15" class="text-flag fill-current" />
          </span>
        </div>
        <p v-if="!compact && message.snippet" class="truncate text-[0.8125rem] text-muted">{{ message.snippet }}</p>
      </div>

      <!-- Hover actions on desktop -->
      <div v-if="!mail.selecting" class="absolute right-3 top-1/2 hidden -translate-y-1/2 gap-1 rounded-lg bg-surface p-1 shadow-sm ring-1 ring-line [@media(hover:hover)]:group-hover:flex">
        <button class="rounded-md p-1.5 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.archive')" @click.stop="quick('archive')">
          <AppIcon name="archive" :size="18" />
        </button>
        <button class="rounded-md p-1.5 text-muted hover:bg-sunken hover:text-danger" :title="t('actions.trash')" @click.stop="quick('trash')">
          <AppIcon name="trash" :size="18" />
        </button>
        <button v-if="canSnooze" class="rounded-md p-1.5 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.snooze')" @click.stop="quick('snooze')">
          <AppIcon name="clock" :size="18" />
        </button>
        <button class="rounded-md p-1.5 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.read')" @click.stop="quick('read')">
          <AppIcon :name="message.seen ? 'unread' : 'read'" :size="18" />
        </button>
      </div>
    </div>
  </li>
</template>
