<script setup>
import { computed } from 'vue'
import AppIcon from './AppIcon.vue'
import { useSwipe } from '../composables/useSwipe.js'
import { useSession } from '../stores/session.js'
import { useMail } from '../stores/mail.js'
import { listDate, displayName, avatarColor, initials } from '../format.js'
import { t } from '../i18n/index.js'

const props = defineProps({
  message: { type: Object, required: true },
  active: { type: Boolean, default: false },
})
const emit = defineEmits(['open'])

const session = useSession()
const mail = useMail()

const leftAction = computed(() => session.settings.swipe_left ?? 'trash')
const rightAction = computed(() => session.settings.swipe_right ?? 'archive')

const selected = computed(() => mail.selected.includes(props.message.uid))

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
  read: 'var(--color-read)',
  flag: 'var(--color-flag)',
  none: 'var(--color-muted)',
}
const revealIcon = computed(() => {
  if (revealAction.value === 'read') return props.message.seen ? 'unread' : 'read'
  return revealAction.value
})

const person = computed(() => {
  // In Sent and Drafts, show the recipient instead of yourself.
  const role = mail.folderRole
  if ((role === 'sent' || role === 'drafts') && props.message.to?.length) return props.message.to[0]
  return props.message.from
})

const compact = computed(() => session.settings.density === 'compact')

function quick(action) {
  mail.act(action, props.message)
}

// ---- long press (touch) selects the message, like in Gmail
let pressTimer = null
let pressStart = null
let longPressed = false
let dragged = false // pointer moved: the following click is not a tap

function onPointerdown(e) {
  swipe.onPointerdown(e)
  dragged = false
  if (e.pointerType === 'mouse') return
  longPressed = false
  pressStart = { x: e.clientX, y: e.clientY }
  clearTimeout(pressTimer)
  pressTimer = setTimeout(() => {
    longPressed = true
    navigator.vibrate?.(15)
    mail.toggleSelect(props.message.uid)
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
  if (mail.selecting) mail.toggleSelect(props.message.uid)
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
        <span class="text-sm">{{ t(`actions.${revealAction}`) }}</span>
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
      ]"
      :style="{ transform: `translateX(${offset}px)` }"
      @pointerdown="onPointerdown"
      @pointermove="onPointermove"
      @pointerup="onPointerup"
      @pointercancel="onPointercancel"
      @click="onClick"
      @keydown.enter="onClick"
      @keydown.space.prevent="mail.toggleSelect(message.uid)"
    >
      <!-- Avatar doubles as the select checkbox (click it on desktop, long press on touch) -->
      <button
        v-if="!compact"
        type="button"
        class="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white transition"
        :style="{ background: selected ? 'var(--color-accent)' : avatarColor(person?.email) }"
        :aria-label="t('list.select')"
        @click.stop="mail.toggleSelect(message.uid)"
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
        @click.stop="mail.toggleSelect(message.uid)"
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
            {{ displayName(person) }}
          </span>
          <span class="ml-auto shrink-0 text-xs tabular-nums" :class="message.seen ? 'text-muted' : 'text-accent font-medium'">
            {{ listDate(message.date) }}
          </span>
        </div>
        <div class="flex items-center gap-2">
          <span class="truncate text-[14px]" :class="message.seen ? 'text-muted' : 'text-ink'">
            {{ message.subject || t('list.noSubject') }}
            <span v-if="compact && message.snippet" class="text-muted"> – {{ message.snippet }}</span>
          </span>
          <span class="ml-auto flex shrink-0 items-center gap-1 text-muted">
            <AppIcon v-if="message.answered" name="reply" :size="14" />
            <AppIcon v-if="message.has_attachments" name="paperclip" :size="14" />
            <AppIcon v-if="message.flagged" name="flag" :size="15" class="text-flag fill-current" />
          </span>
        </div>
        <p v-if="!compact && message.snippet" class="truncate text-[13px] text-muted">{{ message.snippet }}</p>
      </div>

      <!-- Hover actions on desktop -->
      <div v-if="!mail.selecting" class="absolute right-3 top-1/2 hidden -translate-y-1/2 gap-1 rounded-lg bg-surface p-1 shadow-sm ring-1 ring-line [@media(hover:hover)]:group-hover:flex">
        <button class="rounded-md p-1.5 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.archive')" @click.stop="quick('archive')">
          <AppIcon name="archive" :size="18" />
        </button>
        <button class="rounded-md p-1.5 text-muted hover:bg-sunken hover:text-danger" :title="t('actions.trash')" @click.stop="quick('trash')">
          <AppIcon name="trash" :size="18" />
        </button>
        <button class="rounded-md p-1.5 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.read')" @click.stop="quick('read')">
          <AppIcon :name="message.seen ? 'unread' : 'read'" :size="18" />
        </button>
      </div>
    </div>
  </li>
</template>
