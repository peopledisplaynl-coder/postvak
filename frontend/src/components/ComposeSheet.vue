<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import DOMPurify from 'dompurify'
import AppIcon from './AppIcon.vue'
import RecipientInput from './RecipientInput.vue'
import RichEditor from './RichEditor.vue'
import TimePicker from './TimePicker.vue'
import { api } from '../api/client.js'
import { useSession } from '../stores/session.js'
import { useMail } from '../stores/mail.js'
import { useToasts } from '../stores/toasts.js'
import { useOutbox } from '../stores/outbox.js'
import { fullDate, displayName, fileSize } from '../format.js'
import { t, locale } from '../i18n/index.js'

/**
 * Compose a new message, reply, forward, or continue a saved draft.
 * draft = { accountId, mode: 'new'|'reply'|'replyAll'|'forward'|'draft', message? }
 *
 * The message is saved as a draft in the Drafts folder a few seconds after every
 * change, and when the composer is closed. Sending removes the draft.
 */
const props = defineProps({ draft: { type: Object, required: true } })
const emit = defineEmits(['close'])

const AUTOSAVE_MS = 2500

const session = useSession()
const mail = useMail()
const toasts = useToasts()

const fromId = ref(props.draft.accountId ?? session.loginAccount?.id)
const to = ref([])
const cc = ref([])
const bcc = ref([])
const showCc = ref(false)
const subject = ref('')
const files = ref([])               // new local files, not yet in a saved draft
const serverAttachments = ref([])   // attachments of the saved draft / forwarded message
const attachSource = ref(null)      // { account_id, folder, uid } that serverAttachments come from
const sending = ref(false)
const loadingDraft = ref(false)
const editor = ref(null)          // RichEditor instance
const quoteHtml = ref('')         // quoted original (reply/forward), kept outside the editor
const quoteOpen = ref(false)
const quoteRemoved = ref(false)
const fileInput = ref(null)
const toInput = ref(null)

// Reply metadata (kept when the draft is saved and reopened)
const inReplyTo = ref('')
const references = ref('')
const replyRef = ref(null) // { folder, uid } of the message being answered

// Draft state
const draftUid = ref(null)
const draftAccountId = ref(null)
const saveState = ref('idle') // idle | saving | saved | error
const savedAt = ref(null)
let dirty = false
let saveTimer = null
let savePromise = null
let ready = false

const from = computed(() => session.accountById(fromId.value))
const original = props.draft.mode === 'draft' ? null : props.draft.message
// Attachments of another message can only be sent from that message's account.
const fromLocked = computed(() => serverAttachments.value.length > 0 && attachSource.value && attachSource.value.account_id !== fromId.value)

function escape(s) {
  return String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c])
}

onMounted(async () => {
  const mode = props.draft.mode
  let body = '<p></p>'
  // New message to a given address (e.g. from the contacts page).
  if (mode === 'new' && props.draft.to?.length) to.value = props.draft.to.map((r) => ({ email: r.email, name: r.name ?? '' }))

  if (mode === 'draft') {
    await openDraft(props.draft.message)
    return
  }

  if (original && mode !== 'new') {
    const re = /^(re|antw|aw):/i.test(original.subject) ? original.subject : `Re: ${original.subject}`
    const fw = /^(fw|fwd|doorst):/i.test(original.subject) ? original.subject : `Fwd: ${original.subject}`
    subject.value = mode === 'forward' ? fw : re

    if (mode === 'reply' || mode === 'replyAll') {
      const mine = session.accounts.find((a) => a.email.toLowerCase() === original.from?.email?.toLowerCase())
      // Replying to your own message (e.g. from Sent, in a conversation): to its recipients.
      if (mine) to.value = [...(original.to ?? [])]
      else to.value = original.reply_to?.length ? [...original.reply_to] : original.from ? [original.from] : []
      inReplyTo.value = original.message_id
      references.value = [original.references, original.message_id].filter(Boolean).join(' ')
      replyRef.value = { folder: original.folder, uid: original.uid }
    }
    if (mode === 'replyAll') {
      const mine = new Set(session.accounts.map((a) => a.email.toLowerCase()))
      const seen = new Set(to.value.map((r) => r.email.toLowerCase()))
      cc.value = [...original.to, ...original.cc].filter((r) => {
        const e = r.email.toLowerCase()
        if (mine.has(e) || seen.has(e)) return false
        seen.add(e)
        return true
      })
      showCc.value = cc.value.length > 0
    }
    if (mode === 'reply' || mode === 'replyAll') {
      // Reply from the account the mail was sent to, if it is one of ours.
      const sender = session.accounts.find((a) => a.email.toLowerCase() === original.from?.email?.toLowerCase())
      const target = sender ?? session.accounts.find((a) => [...original.to, ...original.cc].some((r) => r.email.toLowerCase() === a.email))
      if (target) fromId.value = target.id
    }
    if (mode === 'forward') {
      serverAttachments.value = (original.attachments ?? []).filter((a) => !a.inline)
      attachSource.value = { account_id: original.account_id, folder: original.folder, uid: original.uid }
    }

    const header = mode === 'forward'
      ? `${t('compose.forwardHeader')}<br>${t('compose.fwdFrom')}: ${escape(displayName(original.from))} &lt;${escape(original.from?.email)}&gt;<br>${t('compose.fwdDate')}: ${escape(fullDate(original.date))}<br>${t('compose.fwdSubject')}: ${escape(original.subject)}<br><br>`
      : escape(t('compose.quoteHeader', { date: fullDate(original.date), name: displayName(original.from) }))
    quoteHtml.value = `<div>${header}</div><blockquote type="cite" style="margin:0 0 0 .8ex;border-left:2px solid #ccc;padding-left:1ex">${DOMPurify.sanitize(original.html)}</blockquote>`
    body = '<p></p>'
  }

  const signature = from.value?.signature_html
  if (signature) body = `<p></p><p></p><p>--</p>${DOMPurify.sanitize(signature)}`

  editor.value.setContent(body)
  if (mode === 'reply' || mode === 'replyAll') editor.value.focus('start')
  // Start watching for changes only after the initial fill (watchers run on the next tick).
  await nextTick()
  ready = true
})

/**
 * Split a saved draft into the part the user wrote and the quoted original.
 * The quote is marked with class "postvak-quote"; drafts from before 0.5 end with a
 * header <div> followed by a <blockquote>.
 */
function splitQuote(html) {
  const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html')
  let root = doc.body
  // The server may wrap the message in a single <div>.
  while (root.children.length === 1 && root.firstElementChild.tagName === 'DIV' && !root.firstElementChild.classList.contains('postvak-quote')) {
    root = root.firstElementChild
  }
  let quote = ''
  const marked = root.querySelector('.postvak-quote')
  if (marked) {
    quote = marked.innerHTML.replace(/^\s*<br\s*\/?>/i, '')
    marked.remove()
  } else if (root.lastElementChild?.tagName === 'BLOCKQUOTE') {
    const bq = root.lastElementChild
    const head = bq.previousElementSibling?.tagName === 'DIV' ? bq.previousElementSibling : null
    quote = (head ? head.outerHTML : '') + bq.outerHTML
    head?.remove()
    bq.remove()
  }
  // Empty lines are sent as <p><br></p>; read them back as empty paragraphs.
  const body = root.innerHTML.replace(/<p\b[^>]*>\s*<br\s*\/?>\s*<\/p>/gi, '<p></p>')
  return { body, quote }
}

/** Continue a saved draft from the Drafts folder. */
async function openDraft(summary) {
  loadingDraft.value = true
  try {
    const d = await mail.fetchMessage(summary)
    fromId.value = summary.account_id
    to.value = d.to ?? []
    cc.value = d.cc ?? []
    bcc.value = d.bcc ?? []
    showCc.value = cc.value.length + bcc.value.length > 0
    subject.value = d.subject ?? ''
    inReplyTo.value = d.in_reply_to ?? ''
    references.value = d.references ?? ''
    replyRef.value = d.reply_ref ?? null
    const parts = splitQuote(DOMPurify.sanitize(d.html ?? ''))
    editor.value.setContent(parts.body)
    quoteHtml.value = parts.quote
    serverAttachments.value = (d.attachments ?? []).filter((a) => !a.inline)
    attachSource.value = { account_id: summary.account_id, folder: summary.folder, uid: summary.uid }
    draftUid.value = summary.uid
    draftAccountId.value = summary.account_id
    saveState.value = 'saved'
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
    emit('close')
  } finally {
    loadingDraft.value = false
    await nextTick()
    ready = true
  }
}

// ---- autosave

// ---- templates (reusable texts)
const templates = ref(null)
const templatesOpen = ref(false)

async function toggleTemplates() {
  templatesOpen.value = !templatesOpen.value
  if (templatesOpen.value && templates.value === null) {
    try {
      templates.value = await api.get('/templates')
    } catch (e) {
      templates.value = []
      toasts.error(t('toast.failed', { msg: e.message }))
    }
  }
}

function useTemplate(tpl) {
  templatesOpen.value = false
  editor.value?.insertContent(tpl.html)
  if (tpl.subject && !subject.value.trim()) subject.value = tpl.subject
  markDirty()
}

async function saveTemplate() {
  templatesOpen.value = false
  if (!editor.value?.getText().trim()) {
    toasts.error(t('templates.empty'))
    return
  }
  const name = prompt(t('templates.namePrompt'), subject.value.trim())
  if (!name?.trim()) return
  try {
    const tpl = await api.post('/templates', { name: name.trim(), subject: subject.value.trim(), html: editor.value.getHtml() })
    templates.value = [...(templates.value ?? []), tpl].sort((a, b) => a.name.localeCompare(b.name))
    toasts.show(t('templates.saved', { name: tpl.name }))
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

async function deleteTemplate(tpl) {
  if (!confirm(t('templates.confirmDelete', { name: tpl.name }))) return
  try {
    await api.del(`/templates/${tpl.id}`)
    templates.value = templates.value.filter((x) => x.id !== tpl.id)
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

function markDirty() {
  if (!ready) return
  dirty = true
  if (saveState.value === 'saved') saveState.value = 'idle'
  clearTimeout(saveTimer)
  saveTimer = setTimeout(() => saveDraft(), AUTOSAVE_MS)
}
watch([to, cc, bcc, subject, files, serverAttachments, fromId, quoteRemoved], markDirty, { deep: true })

function hasContent() {
  const text = editor.value?.getText().replace(/\s+/g, '') ?? ''
  return to.value.length || cc.value.length || bcc.value.length || subject.value.trim() || text || files.value.length || serverAttachments.value.length
}

/** The editor content plus the quoted original (marked, so a draft can be split again). */
function bodyHtml() {
  const html = editor.value.getHtml()
  if (!quoteHtml.value || quoteRemoved.value) return html
  return `${html}<div class="postvak-quote"><br>${quoteHtml.value}</div>`
}

/** Read-only preview of the quoted original (own document, so its styles stay inside). */
const quoteDoc = computed(() => `<!doctype html><meta charset="utf-8"><base target="_blank"><style>body{margin:8px;font:14px/1.5 system-ui,sans-serif;color:#1f2328;overflow-wrap:anywhere}img{max-width:100%;height:auto}</style>${quoteHtml.value}`)

function buildPayload() {
  const payload = {
    to: to.value, cc: cc.value, bcc: bcc.value,
    subject: subject.value,
    html: bodyHtml(),
    in_reply_to: inReplyTo.value || undefined,
    references: references.value || undefined,
  }
  if (replyRef.value) {
    payload.reply_folder = replyRef.value.folder
    payload.reply_uid = replyRef.value.uid
  }
  if (serverAttachments.value.length && attachSource.value && attachSource.value.account_id === fromId.value) {
    payload.include_attachments = { folder: attachSource.value.folder, uid: attachSource.value.uid, indexes: serverAttachments.value.map((a) => a.index) }
  }
  return payload
}

/** Save as draft. Waits for a running save, so versions never overlap. */
async function saveDraft() {
  clearTimeout(saveTimer)
  if (savePromise) await savePromise
  if (!dirty || !hasContent()) return false

  savePromise = (async () => {
    dirty = false
    saveState.value = 'saving'
    const payload = buildPayload()
    // The From account changed: the old draft lives in the other account.
    if (draftUid.value && draftAccountId.value !== fromId.value) {
      api.del(`/accounts/${draftAccountId.value}/drafts/${draftUid.value}`).catch(() => {})
      draftUid.value = null
    }
    if (draftUid.value) payload.draft_uid = draftUid.value
    const sentFiles = [...files.value]
    const form = new FormData()
    form.append('payload', JSON.stringify(payload))
    sentFiles.forEach((f) => form.append('files[]', f))
    try {
      const res = await api.upload(`/accounts/${fromId.value}/drafts`, form)
      draftUid.value = res.uid
      draftAccountId.value = fromId.value
      // Everything is now part of the saved draft.
      ready = false
      files.value = files.value.filter((f) => !sentFiles.includes(f))
      serverAttachments.value = res.attachments
      attachSource.value = { account_id: fromId.value, folder: res.folder, uid: res.uid }
      await nextTick()
      ready = true
      savedAt.value = new Date()
      saveState.value = dirty ? 'idle' : 'saved'
      return true
    } catch (e) {
      dirty = true
      saveState.value = 'error'
      return false
    }
  })()
  try {
    return await savePromise
  } finally {
    savePromise = null
  }
}

const saveLabel = computed(() => {
  if (saveState.value === 'saving') return t('compose.saving')
  if (saveState.value === 'error') return t('compose.saveFailed')
  if (saveState.value === 'saved' && savedAt.value) return t('compose.savedAt', { time: savedAt.value.toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit' }) })
  if (saveState.value === 'saved') return t('compose.savedDraft')
  return ''
})

onBeforeUnmount(() => clearTimeout(saveTimer))

// ---- editing helpers

function addPasted(list) {
  files.value.push(...list)
}

function addFiles(e) {
  files.value.push(...e.target.files)
  e.target.value = ''
}

// ---- drop files from the computer onto the composer
const fileOver = ref(false)
let dragDepth = 0
const hasFiles = (e) => [...(e.dataTransfer?.types ?? [])].includes('Files')

function onDragenter(e) {
  if (!hasFiles(e)) return
  e.preventDefault()
  dragDepth++
  fileOver.value = true
}
function onDragover(e) {
  if (!hasFiles(e)) return
  e.preventDefault() // also stops the editor from inserting the file itself
  e.dataTransfer.dropEffect = 'copy'
}
function onDragleave(e) {
  if (!hasFiles(e)) return
  dragDepth = Math.max(0, dragDepth - 1)
  if (!dragDepth) fileOver.value = false
}
function onDrop(e) {
  if (!hasFiles(e)) return
  e.preventDefault()
  dragDepth = 0
  fileOver.value = false
  const dropped = [...e.dataTransfer.files].filter((f) => f.size > 0 || f.type) // skip dropped folders
  if (dropped.length) files.value.push(...dropped)
}

// ---- send / close / discard

async function send() {
  toInput.value?.commitText()
  if (!to.value.length && !cc.value.length && !bcc.value.length) {
    toasts.error(t('compose.noRecipients'))
    return
  }
  sending.value = true
  clearTimeout(saveTimer)
  if (savePromise) await savePromise

  // Undo send: save everything as a draft first, close, and send after a few seconds.
  const undoSeconds = Number(session.settings.undo_send ?? 5)
  if (undoSeconds > 0) {
    dirty = true
    const saved = await saveDraft()
    if (saved && draftUid.value && attachSource.value) {
      const payload = { ...buildPayload(), draft_uid: draftUid.value }
      useOutbox().queue({
        accountId: fromId.value,
        payload,
        draft: { account_id: fromId.value, folder: attachSource.value.folder, uid: draftUid.value },
        seconds: undoSeconds,
      })
      dirty = false
      sending.value = false
      emit('close', { saved: true })
      return
    }
    // Saving the draft failed: send right away instead.
  }

  const payload = buildPayload()
  if (draftUid.value && draftAccountId.value === fromId.value) payload.draft_uid = draftUid.value
  const form = new FormData()
  form.append('payload', JSON.stringify(payload))
  files.value.forEach((f) => form.append('files[]', f))

  try {
    await api.upload(`/accounts/${fromId.value}/send`, form)
    if (draftUid.value && draftAccountId.value !== fromId.value) {
      api.del(`/accounts/${draftAccountId.value}/drafts/${draftUid.value}`).catch(() => {})
    }
    dirty = false
    toasts.show(t('toast.sent'))
    emit('close', { saved: !!draftUid.value })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    sending.value = false
  }
}

// ---- send later
const laterOpen = ref(false)   // small menu next to the send button
const pickingTime = ref(false)

async function scheduleSend(date) {
  pickingTime.value = false
  toInput.value?.commitText()
  if (!to.value.length && !cc.value.length && !bcc.value.length) {
    toasts.error(t('compose.noRecipients'))
    return
  }
  sending.value = true
  clearTimeout(saveTimer)
  if (savePromise) await savePromise
  const payload = { ...buildPayload(), send_at: date.toISOString() }
  if (draftUid.value && draftAccountId.value === fromId.value) payload.draft_uid = draftUid.value
  const form = new FormData()
  form.append('payload', JSON.stringify(payload))
  files.value.forEach((f) => form.append('files[]', f))
  try {
    await api.upload(`/accounts/${fromId.value}/scheduled`, form)
    if (draftUid.value && draftAccountId.value !== fromId.value) {
      api.del(`/accounts/${draftAccountId.value}/drafts/${draftUid.value}`).catch(() => {})
    }
    dirty = false
    toasts.show(t('toast.scheduled', { date: date.toLocaleString(locale(), { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) }))
    emit('close', { saved: true })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    sending.value = false
  }
}

/** Close: keep the work as a draft. */
async function close() {
  toInput.value?.commitText()
  if (dirty && hasContent()) {
    const ok = await saveDraft()
    if (!ok && !confirm(t('compose.closeUnsaved'))) return
    if (ok) toasts.show(t('compose.draftSaved'))
  }
  emit('close', { saved: !!draftUid.value })
}

/** Discard: remove the draft from the server too. */
async function discard() {
  if (hasContent() && !confirm(t('compose.confirmDiscard'))) return
  clearTimeout(saveTimer)
  dirty = false
  if (savePromise) await savePromise
  if (draftUid.value) {
    try {
      await api.del(`/accounts/${draftAccountId.value}/drafts/${draftUid.value}`)
    } catch (e) {
      toasts.error(t('toast.failed', { msg: e.message }))
    }
  }
  emit('close', { saved: !!draftUid.value })
}
</script>

<template>
  <div class="fixed inset-0 z-40 flex items-end justify-end bg-ink/30 sm:bg-transparent sm:p-4 sm:pointer-events-none" @keydown.esc="close">
    <form
      class="pt-safe pb-safe pointer-events-auto relative flex h-full w-full flex-col bg-surface shadow-2xl ring-1 ring-line sm:h-[min(640px,calc(100vh-2rem))] sm:w-[560px] sm:rounded-2xl"
      @submit.prevent="send"
      @dragenter="onDragenter"
      @dragover="onDragover"
      @dragleave="onDragleave"
      @drop="onDrop"
    >
      <div
        v-if="fileOver"
        class="pointer-events-none absolute inset-2 z-10 flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-accent bg-accent-soft/90 text-accent"
      >
        <AppIcon name="paperclip" :size="28" />
        <span class="font-medium">{{ t('compose.dropFiles') }}</span>
      </div>
      <header class="flex items-center gap-1 border-b border-line px-2 py-2 sm:px-3">
        <button type="button" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('compose.close')" :title="t('compose.close')" @click="close">
          <AppIcon name="close" />
        </button>
        <div class="min-w-0 flex-1 px-1">
          <h2 class="truncate font-semibold leading-tight">{{ subject || t('compose.title') }}</h2>
          <p v-if="saveLabel" class="truncate text-xs leading-tight" :class="saveState === 'error' ? 'text-danger' : 'text-muted'">{{ saveLabel }}</p>
        </div>
        <button type="button" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-danger" :title="t('compose.discard')" @click="discard">
          <AppIcon name="trash" />
        </button>
        <div class="relative">
          <button type="button" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('templates.title')" :aria-expanded="templatesOpen" @click="toggleTemplates">
            <AppIcon name="template" />
          </button>
          <div v-if="templatesOpen" class="fixed inset-0 z-20" @click="templatesOpen = false" />
          <div v-if="templatesOpen" class="absolute right-0 z-30 mt-1 w-72 overflow-hidden rounded-xl bg-surface py-1 text-sm shadow-lg ring-1 ring-line">
            <p class="px-3 pt-1 pb-1.5 text-xs font-medium text-muted">{{ t('templates.title') }}</p>
            <p v-if="templates === null" class="px-3 py-2 text-muted">…</p>
            <p v-else-if="!templates.length" class="px-3 py-2 text-muted">{{ t('templates.none') }}</p>
            <div v-for="tpl in templates ?? []" :key="tpl.id" class="group flex items-center hover:bg-sunken">
              <button type="button" class="min-w-0 flex-1 truncate px-3 py-2 text-left" @click="useTemplate(tpl)">{{ tpl.name }}</button>
              <button type="button" class="mr-1 rounded p-1 text-muted opacity-60 hover:text-danger group-hover:opacity-100" :title="t('templates.delete')" @click="deleteTemplate(tpl)"><AppIcon name="close" :size="15" /></button>
            </div>
            <button type="button" class="flex w-full items-center gap-2 border-t border-line px-3 py-2 text-left text-accent hover:bg-sunken" @click="saveTemplate">
              <AppIcon name="plus" :size="15" />{{ t('templates.saveCurrent') }}
            </button>
          </div>
        </div>
        <button type="button" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('compose.attach')" @click="fileInput.click()">
          <AppIcon name="paperclip" />
        </button>
        <div class="relative ml-1 flex">
          <button
            type="submit"
            class="flex items-center gap-2 rounded-l-full bg-accent py-2 pr-3 pl-4 text-sm font-medium text-white disabled:opacity-60"
            :disabled="sending || loadingDraft"
          >
            <AppIcon name="send" :size="17" />
            {{ sending ? t('compose.sending') : t('compose.send') }}
          </button>
          <button
            type="button"
            class="rounded-r-full border-l border-white/30 bg-accent py-2 pr-2.5 pl-2 text-white disabled:opacity-60"
            :aria-label="t('later.sendLater')"
            :title="t('later.sendLater')"
            :aria-expanded="laterOpen"
            :disabled="sending || loadingDraft"
            @click="laterOpen = !laterOpen"
          >
            <AppIcon name="chevron" :size="15" class="rotate-90" />
          </button>
          <div v-if="laterOpen" class="fixed inset-0 z-20" @click="laterOpen = false" />
          <div v-if="laterOpen" class="absolute top-full right-0 z-30 mt-1 w-52 overflow-hidden rounded-xl bg-surface py-1 shadow-lg ring-1 ring-line">
            <button type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-sunken" @click="laterOpen = false; pickingTime = true">
              <AppIcon name="scheduled" :size="18" class="text-muted" /> {{ t('later.sendLater') }}
            </button>
          </div>
        </div>
      </header>

      <div v-if="session.accounts.length > 1" class="flex items-center gap-2 border-b border-line px-4 py-2 text-sm">
        <span class="text-muted">{{ t('compose.from') }}</span>
        <select v-model="fromId" class="min-w-0 flex-1 bg-transparent outline-none" :disabled="!!(attachSource && serverAttachments.length && draft.mode === 'forward')">
          <option v-for="a in session.accounts" :key="a.id" :value="a.id">
            {{ a.display_name ? `${a.display_name} <${a.email}>` : a.email }}
          </option>
        </select>
      </div>
      <p v-if="fromLocked" class="border-b border-line px-4 py-2 text-sm text-flag">{{ t('compose.attachmentsOtherAccount') }}</p>

      <div class="relative">
        <RecipientInput ref="toInput" v-model="to" :label="t('compose.to')" :autofocus="draft.mode === 'new' || draft.mode === 'forward'" />
        <button v-if="!showCc" type="button" class="absolute top-2.5 right-3 text-sm text-muted hover:text-ink" @click="showCc = true">
          {{ t('compose.addCc') }}
        </button>
      </div>
      <template v-if="showCc">
        <RecipientInput v-model="cc" :label="t('compose.cc')" />
        <RecipientInput v-model="bcc" :label="t('compose.bcc')" />
      </template>

      <input
        v-model="subject"
        class="border-b border-line bg-transparent px-4 py-3 outline-none placeholder:text-muted"
        :placeholder="t('compose.subject')"
      />

      <div v-if="loadingDraft" class="flex-1 space-y-2 p-4">
        <div class="h-3 w-2/3 animate-pulse rounded bg-sunken" />
        <div class="h-3 w-1/2 animate-pulse rounded bg-sunken" />
      </div>
      <RichEditor
        v-show="!loadingDraft"
        ref="editor"
        :placeholder="t('editor.placeholder')"
        @update="markDirty"
        @files="addPasted"
        @submit="send"
      />

      <!-- Quoted original: sent along, but not edited (keeps tables and layout intact) -->
      <div v-if="quoteHtml" class="border-t border-line px-4 py-2 text-sm">
        <div v-if="quoteRemoved" class="flex flex-wrap items-center gap-x-3 text-muted">
          <span>{{ t('editor.quoteRemoved') }}</span>
          <button type="button" class="font-medium text-accent" @click="quoteRemoved = false">{{ t('editor.restoreQuote') }}</button>
        </div>
        <template v-else>
          <div class="flex items-center gap-3">
            <button type="button" class="flex items-center gap-1.5 rounded-lg px-2 py-1 text-muted hover:bg-sunken hover:text-ink" :aria-expanded="quoteOpen" @click="quoteOpen = !quoteOpen">
              <span class="rounded bg-sunken px-1.5 leading-tight tracking-widest">···</span>
              {{ quoteOpen ? t('editor.hideQuote') : t('editor.showQuote') }}
            </button>
            <button type="button" class="ml-auto rounded-lg px-2 py-1 text-muted hover:bg-sunken hover:text-danger" @click="quoteRemoved = true; quoteOpen = false">{{ t('editor.removeQuote') }}</button>
          </div>
          <iframe v-if="quoteOpen" :srcdoc="quoteDoc" sandbox="allow-popups allow-popups-to-escape-sandbox" class="mt-2 h-56 w-full rounded-lg bg-white ring-1 ring-line" :title="t('editor.showQuote')" />
        </template>
      </div>

      <ul v-if="files.length || serverAttachments.length" class="flex flex-wrap gap-2 border-t border-line px-4 py-2">
        <li v-for="a in serverAttachments" :key="'s' + a.index" class="flex items-center gap-2 rounded-lg bg-sunken px-2.5 py-1 text-sm">
          <AppIcon name="paperclip" :size="14" class="text-muted" />
          <span class="max-w-48 truncate">{{ a.name }}</span>
          <span class="text-muted">{{ fileSize(a.size) }}</span>
          <button type="button" class="text-muted hover:text-ink" :aria-label="t('settings.remove')" @click="serverAttachments = serverAttachments.filter((x) => x !== a)">×</button>
        </li>
        <li v-for="(f, i) in files" :key="'f' + i" class="flex items-center gap-2 rounded-lg bg-sunken px-2.5 py-1 text-sm">
          <span class="max-w-48 truncate">{{ f.name }}</span>
          <span class="text-muted">{{ fileSize(f.size) }}</span>
          <button type="button" class="text-muted hover:text-ink" :aria-label="t('settings.remove')" @click="files.splice(i, 1)">×</button>
        </li>
      </ul>
      <input ref="fileInput" type="file" multiple class="hidden" @change="addFiles" />
      <TimePicker v-if="pickingTime" mode="send" @pick="scheduleSend" @close="pickingTime = false" />
    </form>
  </div>
</template>
