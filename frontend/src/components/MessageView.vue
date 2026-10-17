<script setup>
import { ref, watch, computed, nextTick } from 'vue'
import DOMPurify from 'dompurify'
import AppIcon from './AppIcon.vue'
import { useMail } from '../stores/mail.js'
import { useSession } from '../stores/session.js'
import { useToasts } from '../stores/toasts.js'
import { attachmentUrl } from '../api/client.js'
import { fullDate, fileSize, displayName, avatarColor, initials } from '../format.js'
import { t } from '../i18n/index.js'

const props = defineProps({ uid: { type: Number, default: null } })
const emit = defineEmits(['back', 'reply'])

const mail = useMail()
const session = useSession()
const toasts = useToasts()

const message = ref(null)
const loading = ref(false)
const frame = ref(null)
const frameHeight = ref(200)

const summary = computed(() => mail.messages.find((m) => m.uid === props.uid))
const attachments = computed(() => message.value?.attachments.filter((a) => !a.inline) ?? [])

async function load(images = false) {
  if (!props.uid) {
    message.value = null
    return
  }
  loading.value = true
  const uid = props.uid
  try {
    const showImages = images || session.settings.show_images === 'always'
    const data = await mail.fetchMessage(uid, { images: showImages })
    if (uid !== props.uid) return
    message.value = data
    if (!data.seen && session.settings.mark_read_on_open && summary.value) {
      mail.setFlag(summary.value, 'seen', true)
    }
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
    emit('back')
  } finally {
    loading.value = false
  }
}
watch(() => props.uid, () => load(), { immediate: true })

/**
 * Mail HTML is sanitised on the server and again here, then rendered in an iframe
 * with sandbox="allow-same-origin allow-popups": no scripts can run, links open in a new tab.
 */
const srcdoc = computed(() => {
  if (!message.value) return ''
  const clean = DOMPurify.sanitize(message.value.html, {
    FORBID_TAGS: ['style', 'form', 'input', 'button'],
    ADD_ATTR: ['target'],
  })
  const dark = document.documentElement.classList.contains('dark')
  return `<!doctype html><html><head><meta charset="utf-8">
<base target="_blank">
<style>
  html,body{margin:0;padding:0;background:transparent}
  body{font:15px/1.55 system-ui,-apple-system,'Segoe UI',sans-serif;color:${dark ? '#E7EBF1' : '#18202B'};overflow-wrap:anywhere;padding:2px}
  a{color:${dark ? '#9AA4F5' : '#3646D9'}}
  img{max-width:100%;height:auto}
  img:not([src]){display:none}
  table{max-width:100%!important}
  blockquote{margin:0 0 0 .5em;padding-left:.75em;border-left:3px solid ${dark ? '#273040' : '#DCE1E8'};color:${dark ? '#8E99AA' : '#677385'}}
  pre{white-space:pre-wrap}
</style></head><body>${clean}</body></html>`
})

function resizeFrame() {
  const doc = frame.value?.contentDocument
  if (!doc) return
  frameHeight.value = Math.max(80, doc.documentElement.scrollHeight)
  // Images load later and change the height.
  doc.querySelectorAll('img').forEach((img) => img.addEventListener('load', () => {
    frameHeight.value = Math.max(80, doc.documentElement.scrollHeight)
  }, { once: true }))
}

async function act(action) {
  if (!summary.value) return
  const done = await mail.act(action, summary.value)
  if (done && (action === 'archive' || action === 'trash')) emit('back')
}

function reply(mode) {
  emit('reply', { mode, message: message.value })
}

watch(srcdoc, () => nextTick(() => (frameHeight.value = 200)))
</script>

<template>
  <section class="flex h-full min-h-0 flex-col bg-surface">
    <header class="pt-safe flex items-center gap-1 border-b border-line px-2 py-2">
      <button v-if="uid" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('actions.back')" @click="emit('back')">
        <AppIcon name="back" />
      </button>
      <div class="flex-1" />
      <template v-if="summary">
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.archive')" @click="act('archive')"><AppIcon name="archive" /></button>
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-danger" :title="t('actions.trash')" @click="act('trash')"><AppIcon name="trash" /></button>
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.markUnread')" @click="mail.setFlag(summary, 'seen', false); emit('back')"><AppIcon name="unread" /></button>
        <button class="rounded-lg p-2 hover:bg-sunken" :class="summary.flagged ? 'text-flag' : 'text-muted hover:text-ink'" :title="t('actions.flag')" @click="mail.setFlag(summary, 'flagged', !summary.flagged)">
          <AppIcon name="flag" :class="summary.flagged ? 'fill-current' : ''" />
        </button>
      </template>
    </header>

    <div v-if="loading && !message" class="space-y-3 p-6">
      <div class="h-6 w-2/3 animate-pulse rounded bg-sunken" />
      <div class="h-4 w-1/3 animate-pulse rounded bg-sunken" />
      <div class="mt-8 h-40 animate-pulse rounded bg-sunken" />
    </div>

    <article v-else-if="message" class="min-h-0 flex-1 overflow-y-auto">
      <div class="mx-auto max-w-3xl px-5 py-5 sm:px-8">
        <h2 class="text-[22px] leading-snug font-semibold text-balance">{{ message.subject || t('list.noSubject') }}</h2>

        <div class="mt-4 flex items-start gap-3">
          <span
            class="flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white"
            :style="{ background: avatarColor(message.from?.email) }"
          >{{ initials(message.from) }}</span>
          <div class="min-w-0 flex-1 text-sm">
            <p class="truncate">
              <span class="font-semibold">{{ displayName(message.from) }}</span>
              <span v-if="message.from?.name" class="text-muted"> &lt;{{ message.from.email }}&gt;</span>
            </p>
            <p class="truncate text-muted">
              {{ t('reader.to') }} {{ message.to.map(displayName).join(', ') }}
              <template v-if="message.cc.length">, {{ t('reader.cc') }} {{ message.cc.map(displayName).join(', ') }}</template>
            </p>
            <p class="text-muted">{{ fullDate(message.date) }}</p>
          </div>
        </div>

        <div v-if="message.blocked_images && session.settings.show_images === 'ask'" class="mt-5 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl bg-sunken px-4 py-3 text-sm">
          <span class="text-muted">{{ t('reader.imagesBlocked') }}</span>
          <button class="font-medium text-accent hover:underline" @click="load(true)">{{ t('reader.showImages') }}</button>
        </div>

        <iframe
          ref="frame"
          class="mt-5 block w-full border-0"
          :style="{ height: frameHeight + 'px' }"
          sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
          :srcdoc="srcdoc"
          title="Bericht"
          @load="resizeFrame"
        />

        <div v-if="attachments.length" class="mt-6">
          <h3 class="mb-2 text-sm font-medium text-muted">{{ t('reader.attachments') }}</h3>
          <ul class="flex flex-wrap gap-2">
            <li v-for="a in attachments" :key="a.index">
              <a
                :href="attachmentUrl(a.url)"
                class="flex max-w-64 items-center gap-2 rounded-xl border border-line px-3 py-2 text-sm hover:bg-sunken"
                download
              >
                <AppIcon name="paperclip" :size="16" class="shrink-0 text-muted" />
                <span class="truncate">{{ a.name }}</span>
                <span class="shrink-0 text-muted">{{ fileSize(a.size) }}</span>
              </a>
            </li>
          </ul>
        </div>

        <div class="mt-8 flex flex-wrap gap-2 pb-8">
          <button class="flex items-center gap-2 rounded-full border border-line px-4 py-2 text-sm font-medium hover:bg-sunken" @click="reply('reply')">
            <AppIcon name="reply" :size="18" /> {{ t('actions.reply') }}
          </button>
          <button v-if="message.to.length + message.cc.length > 1" class="flex items-center gap-2 rounded-full border border-line px-4 py-2 text-sm font-medium hover:bg-sunken" @click="reply('replyAll')">
            <AppIcon name="replyAll" :size="18" /> {{ t('actions.replyAll') }}
          </button>
          <button class="flex items-center gap-2 rounded-full border border-line px-4 py-2 text-sm font-medium hover:bg-sunken" @click="reply('forward')">
            <AppIcon name="forward" :size="18" /> {{ t('actions.forward') }}
          </button>
        </div>
      </div>
    </article>

    <div v-else class="flex flex-1 items-center justify-center p-8 text-muted">
      {{ t('reader.select') }}
    </div>
  </section>
</template>
