<script setup>
import { ref, watch, computed, nextTick, onBeforeUnmount } from 'vue'
import DOMPurify from 'dompurify'
import AppIcon from './AppIcon.vue'
import AttachmentViewer from './AttachmentViewer.vue'
import InvitationCard from './InvitationCard.vue'
import { useRoute, useRouter } from 'vue-router'
import { useMail } from '../stores/mail.js'
import { useSession } from '../stores/session.js'
import { useToasts } from '../stores/toasts.js'
import { api, attachmentUrl } from '../api/client.js'
import { fullDate, listDate, fileSize, displayName, avatarColor, initials } from '../format.js'
import { t } from '../i18n/index.js'

/**
 * One message: sender block, remote-image notice, the sandboxed body, attachments and
 * reply buttons. Used on its own (single message) and as an open card in a conversation.
 *
 * target = { account_id, folder, uid }. Emits: loaded(message), reply({ mode, message }),
 * collapse (header clicked, only when `collapsible`).
 */
const props = defineProps({
  target: { type: Object, required: true },
  collapsible: { type: Boolean, default: false },
  badge: { type: String, default: '' },
})
const emit = defineEmits(['loaded', 'reply', 'collapse'])

const mail = useMail()
const route = useRoute()
const router = useRouter()
const session = useSession()
const toasts = useToasts()

const message = ref(null)
const loading = ref(false)
const failed = ref(false)
const frame = ref(null)
const frameHeight = ref(120)

const key = computed(() => `${props.target.account_id}:${props.target.folder}:${props.target.uid}`)
const attachments = computed(() => (message.value?.attachments ?? []).filter((a) => !a.inline))

async function load(images = false) {
  loading.value = true
  failed.value = false
  const requested = key.value
  try {
    const showImages = images || session.settings.show_images === 'always'
    const data = await mail.fetchMessage(props.target, { images: showImages })
    if (requested !== key.value) return
    message.value = data
    emit('loaded', data)
  } catch (e) {
    failed.value = true
    toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    loading.value = false
  }
}
watch(key, () => load(), { immediate: true })

// ---- sender
const senderEmail = computed(() => message.value?.from?.email?.toLowerCase() ?? '')
const senderDomain = computed(() => senderEmail.value.split('@')[1] ?? '')
const ownAddress = computed(() => session.accounts.some((a) => a.email.toLowerCase() === senderEmail.value))

const addingContact = ref(false)
// ---- unsubscribe from a mailing list (List-Unsubscribe)
const unsubscribing = ref(false)
async function unsubscribe() {
  if (!confirm(t('unsubscribe.confirm', { name: displayName(message.value.from) }))) return
  unsubscribing.value = true
  try {
    const res = await api.post(`/accounts/${props.target.account_id}/messages/${props.target.uid}/unsubscribe`, { folder: props.target.folder })
    if (res.done) {
      toasts.show(t('unsubscribe.done', { name: displayName(message.value.from) }), { seconds: 6 })
      message.value = { ...message.value, unsubscribe: false }
    } else if (res.url) {
      window.open(res.url, '_blank', 'noopener')
      toasts.show(t('unsubscribe.page'), { seconds: 8 })
    }
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    unsubscribing.value = false
  }
}

const followup = computed(() => !!(message.value && mail.byKey(`${props.target.account_id}:${props.target.uid}`)?.followup))

/** Close the reader and list the other messages from this sender. */
function moreFromSender() {
  const { uid: _, acc: __, ...rest } = route.query
  router.push({ query: rest })
  mail.searchFrom(senderEmail.value)
}

async function addContact() {
  addingContact.value = true
  try {
    await api.post('/contacts', { email: senderEmail.value, name: message.value.from?.name ?? '' })
    toasts.show(t('reader.contactAdded', { name: displayName(message.value.from) }))
  } catch (e) {
    toasts.show(e.status === 409 ? t('contacts.duplicate') : t('toast.failed', { msg: e.message }))
  } finally {
    addingContact.value = false
  }
}

const trusting = ref(false)
async function trust(kind, value) {
  trusting.value = true
  try {
    await api.post('/trusted-senders', { kind, value })
    toasts.show(t(kind === 'email' ? 'reader.trustedSender' : 'reader.trustedDomain', { value }))
    await load(true)
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    trusting.value = false
  }
}

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

/**
 * Fit the mail to the screen, like Gmail: newsletters are often a fixed 600–700 px wide, which does not fit on a
 * phone. The body is laid out at the chosen text size; when it is still wider than the frame, it is scaled down
 * (CSS zoom, so links and text stay sharp and clickable). "Original size" turns this off for one message.
 */
const TEXT_ZOOM = { small: 0.92, normal: 1, large: 1.12, xlarge: 1.25 }
const fit = ref(true)
const shrunk = ref(false)
function layoutFrame() {
  const el = frame.value
  const doc = el?.contentDocument
  if (!doc?.body) return
  const want = TEXT_ZOOM[session.settings.text_size] ?? 1
  const avail = el.clientWidth
  const body = doc.body
  body.style.zoom = String(want)
  let zoom = want
  const wide = doc.documentElement.scrollWidth
  if (fit.value && avail > 0 && wide > avail + 1) {
    zoom = want * (avail / wide)
    body.style.zoom = String(zoom)
  }
  shrunk.value = zoom < want - 0.02 || (!fit.value && wide > avail + 1)
  frameHeight.value = Math.max(40, Math.ceil(doc.documentElement.scrollHeight))
}
function resizeFrame() {
  const doc = frame.value?.contentDocument
  if (!doc) return
  layoutFrame()
  // Images load later and change the size.
  doc.querySelectorAll('img').forEach((img) => img.addEventListener('load', layoutFrame, { once: true }))
}
watch(srcdoc, () => nextTick(() => {
  frameHeight.value = 120
  fit.value = true
}))
watch(() => session.settings.text_size, () => nextTick(layoutFrame))
watch(fit, () => nextTick(layoutFrame))
// A turned phone or a dragged column divider changes the room there is.
let observer = null
watch(frame, (el) => {
  observer?.disconnect()
  if (!el || typeof ResizeObserver === 'undefined') return
  let lastWidth = el.clientWidth
  observer = new ResizeObserver(() => {
    if (Math.abs(el.clientWidth - lastWidth) < 2) return
    lastWidth = el.clientWidth
    layoutFrame()
  })
  observer.observe(el)
})
onBeforeUnmount(() => observer?.disconnect())

// ---- sender details (folded away on small screens, like Gmail)
const detailsOpen = ref(false)
const trustOpen = ref(false)
watch(key, () => {
  detailsOpen.value = false
  trustOpen.value = false
})
const recipients = computed(() => {
  const m = message.value
  if (!m) return ''
  const to = (m.to ?? []).map(displayName).join(', ')
  return m.cc?.length ? `${to}, ${t('reader.cc')} ${m.cc.map(displayName).join(', ')}` : to
})
const people = (list) => (list ?? []).map((r) => (r.name ? `${r.name} <${r.email}>` : r.email)).join(', ')

// ---- attachments: preview images/PDF, download the rest
const previewable = (a) => /^(image\/(png|jpe?g|gif|webp)|application\/pdf)$/.test(a.mime)
const previewItems = computed(() => attachments.value.filter(previewable))
const viewerAt = ref(null)
function openAttachment(a, e) {
  if (!previewable(a)) return // normal download link
  e.preventDefault()
  viewerAt.value = previewItems.value.indexOf(a)
}

// ---- print: own document in a hidden frame, light colours, with the header details
function printMessage() {
  const m = message.value
  if (!m) return
  const esc = (v) => String(v ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c])
  const people = (list) => (list ?? []).map((r) => esc(r.name ? `${r.name} <${r.email}>` : r.email)).join(', ')
  const body = DOMPurify.sanitize(m.html, { FORBID_TAGS: ['style', 'form', 'input', 'button'] })
  const rows = [
    [t('print.from'), people([m.from])],
    [t('print.to'), people(m.to)],
    ...(m.cc?.length ? [[t('print.cc'), people(m.cc)]] : []),
    [t('print.date'), esc(fullDate(m.date))],
  ].map(([k, v]) => `<tr><th>${k}</th><td>${v}</td></tr>`).join('')
  const doc = `<!doctype html><html><head><meta charset="utf-8"><title>${esc(m.subject)}</title><style>
    body{font:14px/1.5 system-ui,-apple-system,'Segoe UI',sans-serif;color:#111;margin:24px}
    h1{font-size:20px;margin:0 0 12px}
    table.meta{border-collapse:collapse;margin-bottom:16px;font-size:13px}
    table.meta th{text-align:left;color:#555;font-weight:500;padding:1px 12px 1px 0;vertical-align:top}
    hr{border:0;border-top:1px solid #ccc;margin:12px 0 16px}
    img{max-width:100%;height:auto} img:not([src]){display:none}
    blockquote{margin:0 0 0 .5em;padding-left:.75em;border-left:3px solid #ccc;color:#555}
  </style></head><body><h1>${esc(m.subject || t('list.noSubject'))}</h1><table class="meta">${rows}</table><hr>${body}</body></html>`
  const frame = document.createElement('iframe')
  frame.setAttribute('sandbox', 'allow-same-origin allow-modals')
  frame.style.cssText = 'position:fixed;width:0;height:0;border:0;left:-9999px'
  frame.srcdoc = doc
  frame.onload = () => {
    try {
      frame.contentWindow.focus()
      frame.contentWindow.print()
    } finally {
      setTimeout(() => frame.remove(), 1000)
    }
  }
  document.body.appendChild(frame)
}

function reply(mode) {
  emit('reply', { mode, message: message.value })
}

defineExpose({ message, reload: load, print: printMessage })
</script>

<template>
  <div>
    <div v-if="loading && !message" class="space-y-3 py-2">
      <div class="h-4 w-1/3 animate-pulse rounded bg-sunken" />
      <div class="h-24 animate-pulse rounded bg-sunken" />
    </div>
    <p v-else-if="failed && !message" class="py-4 text-sm text-muted">
      {{ t('reader.loadFailed') }}
      <button class="font-medium text-accent" @click="load()">{{ t('reader.retry') }}</button>
    </p>

    <template v-else-if="message">
      <!-- The sender is not who they claim to be (checked by the receiving mail server) -->
      <div
        v-if="message.authenticity?.level"
        class="mb-4 flex gap-3 rounded-xl px-4 py-3 text-sm"
        :class="message.authenticity.level === 'danger' ? 'bg-danger/10 text-danger' : 'bg-flag/10 text-ink'"
        role="alert"
      >
        <AppIcon name="shield" :size="20" class="mt-0.5 shrink-0" />
        <div>
          <p class="font-semibold">{{ t(message.authenticity.level === 'danger' ? 'trust.dangerTitle' : 'trust.cautionTitle') }}</p>
          <p v-for="r in message.authenticity.reasons" :key="r" class="mt-0.5" :class="message.authenticity.level === 'danger' ? 'text-danger/90' : 'text-muted'">{{ t(`trust.${r}`) }}</p>
        </div>
      </div>
      <!-- Sent with "remind me if nobody answers" and no answer came -->
      <div v-if="followup" class="mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-accent-soft px-4 py-3 text-sm">
        <AppIcon name="clock" :size="20" class="shrink-0 text-accent" />
        <span class="min-w-0 flex-1">{{ t('followup.banner', { date: fullDate(message.date) }) }}</span>
        <button class="rounded-lg bg-accent px-3 py-1.5 font-medium text-white" @click="reply('replyAll')">{{ t('followup.nudge') }}</button>
      </div>
      <div class="flex items-start gap-3" :class="collapsible ? 'cursor-pointer' : ''" @click="collapsible && emit('collapse')">
        <span
          class="flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white"
          :style="{ background: avatarColor(message.from?.email) }"
        >{{ initials(message.from) }}</span>
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-x-2">
            <p class="min-w-0 flex-1 truncate">
              <span class="font-semibold">{{ displayName(message.from) }}</span>
              <span v-if="message.from?.name" class="text-sm text-muted max-md:hidden"> &lt;{{ message.from.email }}&gt;</span>
              <span v-if="badge" class="ml-2 rounded-full bg-sunken px-2 py-0.5 text-xs text-muted">{{ badge }}</span>
            </p>
            <span class="shrink-0 text-xs text-muted md:hidden">{{ listDate(message.date) }}</span>
          </div>
          <button
            type="button"
            class="flex max-w-full items-center gap-1 text-left text-sm text-muted hover:text-ink"
            :aria-expanded="detailsOpen"
            @click.stop="detailsOpen = !detailsOpen"
          >
            <span class="truncate">{{ t('reader.to') }} {{ recipients }}</span>
            <AppIcon name="chevron" :size="14" class="shrink-0 transition" :class="detailsOpen ? '-rotate-90' : 'rotate-90'" />
          </button>
          <p class="text-sm text-muted max-md:hidden">{{ fullDate(message.date) }}</p>
        </div>
        <button
          v-if="message.unsubscribe && !collapsible"
          class="shrink-0 rounded-full px-3 py-1 text-sm font-medium text-accent ring-1 ring-line hover:bg-accent-soft disabled:opacity-50"
          :disabled="unsubscribing"
          @click.stop="unsubscribe"
        >{{ t('unsubscribe.button') }}</button>
        <div v-if="senderEmail && !ownAddress" class="flex shrink-0 max-md:hidden">
          <RouterLink
            :to="{ path: '/settings/rules', query: { from: senderEmail, name: displayName(message.from), account: target.account_id } }"
            class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink"
            :title="t('rules.fromMessage')"
            @click.stop
          >
            <AppIcon name="filter" :size="20" />
          </RouterLink>
          <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('filters.moreFrom')" @click.stop="moreFromSender">
            <AppIcon name="search" :size="20" />
          </button>
          <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink disabled:opacity-50" :title="t('reader.addContact')" :disabled="addingContact" @click.stop="addContact">
            <AppIcon name="userPlus" :size="20" />
          </button>
        </div>
      </div>

      <!-- Details: full addresses and date; on phones also the sender actions -->
      <div v-if="detailsOpen" class="mt-3 rounded-xl bg-sunken px-4 py-3 text-sm" @click.stop>
        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
          <dt class="text-muted">{{ t('print.from') }}</dt><dd class="min-w-0 break-words">{{ people([message.from]) }}</dd>
          <dt class="text-muted">{{ t('print.to') }}</dt><dd class="min-w-0 break-words">{{ people(message.to) }}</dd>
          <template v-if="message.cc?.length"><dt class="text-muted">{{ t('print.cc') }}</dt><dd class="min-w-0 break-words">{{ people(message.cc) }}</dd></template>
          <dt class="text-muted">{{ t('print.date') }}</dt><dd>{{ fullDate(message.date) }}</dd>
        </dl>
        <div v-if="senderEmail && !ownAddress" class="mt-3 flex flex-wrap gap-2 md:hidden">
          <RouterLink
            :to="{ path: '/settings/rules', query: { from: senderEmail, name: displayName(message.from), account: target.account_id } }"
            class="flex items-center gap-1.5 rounded-lg bg-surface px-3 py-1.5 ring-1 ring-line"
          ><AppIcon name="filter" :size="16" />{{ t('rules.fromMessage') }}</RouterLink>
          <button class="flex items-center gap-1.5 rounded-lg bg-surface px-3 py-1.5 ring-1 ring-line" @click="moreFromSender">
            <AppIcon name="search" :size="16" />{{ t('filters.moreFrom') }}
          </button>
          <button class="flex items-center gap-1.5 rounded-lg bg-surface px-3 py-1.5 ring-1 ring-line disabled:opacity-50" :disabled="addingContact" @click="addContact">
            <AppIcon name="userPlus" :size="16" />{{ t('reader.addContact') }}
          </button>
        </div>
      </div>

      <!-- Remote images are blocked: one compact bar, the "always" choices folded into a small menu -->
      <div v-if="message.blocked_images && session.settings.show_images !== 'always'" class="relative mt-4 flex flex-wrap items-center gap-2 rounded-xl bg-sunken px-3 py-2 text-sm">
        <AppIcon name="image" :size="18" class="shrink-0 text-muted" />
        <span class="min-w-0 flex-1 text-muted">
          <span class="md:hidden">{{ t('reader.imagesBlockedShort') }}</span>
          <span class="max-md:hidden">{{ t('reader.imagesBlocked') }}</span>
        </span>
        <button class="rounded-lg bg-surface px-3 py-1.5 font-medium text-accent ring-1 ring-line hover:bg-accent-soft" @click="load(true)">
          {{ t('reader.showImages') }}
        </button>
        <button v-if="senderEmail" class="flex items-center gap-1 rounded-lg bg-surface px-3 py-1.5 ring-1 ring-line hover:bg-accent-soft" :aria-expanded="trustOpen" @click="trustOpen = !trustOpen">
          {{ t('reader.alwaysShow') }}<AppIcon name="chevron" :size="14" class="rotate-90" />
        </button>
        <div v-if="trustOpen" class="absolute top-full right-0 z-20 mt-1 w-72 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl bg-surface py-1 shadow-lg ring-1 ring-line">
          <button class="block w-full truncate px-4 py-2.5 text-left hover:bg-sunken" :disabled="trusting" @click="trustOpen = false; trust('email', senderEmail)">
            {{ t('reader.trustSender', { email: senderEmail }) }}
          </button>
          <button class="block w-full truncate px-4 py-2.5 text-left hover:bg-sunken" :disabled="trusting" @click="trustOpen = false; trust('domain', senderDomain)">
            {{ t('reader.trustDomain', { domain: senderDomain }) }}
          </button>
        </div>
      </div>

      <InvitationCard v-if="message.invitation" :key="key" :invitation="message.invitation" :target="target" />

      <iframe
        ref="frame"
        class="mt-5 block w-full border-0"
        :style="{ height: frameHeight + 'px' }"
        sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
        :srcdoc="srcdoc"
        :title="t('reader.frameTitle')"
        @load="resizeFrame"
      />
      <button v-if="shrunk || !fit" class="mt-2 text-sm text-accent" @click="fit = !fit">
        {{ fit ? t('reader.originalSize') : t('reader.fitScreen') }}
      </button>

      <div v-if="attachments.length" class="mt-6">
        <h3 class="mb-2 text-sm font-medium text-muted">{{ t('reader.attachments') }}</h3>
        <ul class="flex flex-wrap gap-2">
          <li v-for="a in attachments" :key="a.index">
            <a
              :href="attachmentUrl(a.url)"
              class="flex max-w-64 items-center gap-2 rounded-xl border border-line px-3 py-2 text-sm hover:bg-sunken"
              :title="previewable(a) ? t('viewer.view') : t('viewer.download')"
              download
              @click="openAttachment(a, $event)"
            >
              <img v-if="a.mime.startsWith('image/')" :src="attachmentUrl(a.url) + '&inline=1'" alt="" loading="lazy" class="size-8 shrink-0 rounded object-cover" />
              <AppIcon v-else name="paperclip" :size="16" class="shrink-0 text-muted" />
              <span class="truncate">{{ a.name }}</span>
              <span class="shrink-0 text-muted">{{ fileSize(a.size) }}</span>
            </a>
          </li>
        </ul>
      </div>

      <div class="mt-6 flex flex-wrap gap-2">
        <button class="flex items-center gap-2 rounded-full border border-line px-4 py-2 text-sm font-medium hover:bg-sunken" @click="reply('reply')">
          <AppIcon name="reply" :size="18" /> {{ t('actions.reply') }}
        </button>
        <button v-if="(message.to?.length ?? 0) + (message.cc?.length ?? 0) > 1" class="flex items-center gap-2 rounded-full border border-line px-4 py-2 text-sm font-medium hover:bg-sunken" @click="reply('replyAll')">
          <AppIcon name="replyAll" :size="18" /> {{ t('actions.replyAll') }}
        </button>
        <button class="flex items-center gap-2 rounded-full border border-line px-4 py-2 text-sm font-medium hover:bg-sunken" @click="reply('forward')">
          <AppIcon name="forward" :size="18" /> {{ t('actions.forward') }}
        </button>
        <button class="ml-auto rounded-full p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('print.print')" @click="printMessage">
          <AppIcon name="print" :size="18" />
        </button>
      </div>
    </template>
    <AttachmentViewer v-if="viewerAt !== null" :items="previewItems" :start="viewerAt" @close="viewerAt = null" />
  </div>
</template>
