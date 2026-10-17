<script setup>
import { ref, computed, onMounted } from 'vue'
import DOMPurify from 'dompurify'
import AppIcon from './AppIcon.vue'
import RecipientInput from './RecipientInput.vue'
import { api } from '../api/client.js'
import { useSession } from '../stores/session.js'
import { useMail } from '../stores/mail.js'
import { useToasts } from '../stores/toasts.js'
import { fullDate, displayName, fileSize } from '../format.js'
import { t } from '../i18n/index.js'

/**
 * Compose a new message, reply or forward.
 * draft = { accountId, mode: 'new'|'reply'|'replyAll'|'forward', message? }
 */
const props = defineProps({ draft: { type: Object, required: true } })
const emit = defineEmits(['close'])

const session = useSession()
const mail = useMail()
const toasts = useToasts()

const fromId = ref(props.draft.accountId ?? mail.accountId ?? session.loginAccount?.id)
const to = ref([])
const cc = ref([])
const bcc = ref([])
const showCc = ref(false)
const subject = ref('')
const files = ref([])
const sending = ref(false)
const editor = ref(null)
const fileInput = ref(null)
const toInput = ref(null)

const from = computed(() => session.accountById(fromId.value))
const original = props.draft.message

function escape(s) {
  return String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c])
}

onMounted(() => {
  const mode = props.draft.mode
  let body = '<p><br></p>'

  if (original && mode !== 'new') {
    const re = /^(re|antw|aw):/i.test(original.subject) ? original.subject : `Re: ${original.subject}`
    const fw = /^(fw|fwd|doorst):/i.test(original.subject) ? original.subject : `Fwd: ${original.subject}`
    subject.value = mode === 'forward' ? fw : re

    if (mode === 'reply' || mode === 'replyAll') {
      to.value = original.reply_to?.length ? [...original.reply_to] : original.from ? [original.from] : []
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
      // Reply from the account the mail was sent to, if it is one of ours.
      const target = session.accounts.find((a) => original.to.some((r) => r.email.toLowerCase() === a.email))
      if (target) fromId.value = target.id
    }

    const header = mode === 'forward'
      ? `${t('compose.forwardHeader')}<br>Van: ${escape(displayName(original.from))} &lt;${escape(original.from?.email)}&gt;<br>Datum: ${escape(fullDate(original.date))}<br>Onderwerp: ${escape(original.subject)}<br><br>`
      : escape(t('compose.quoteHeader', { date: fullDate(original.date), name: displayName(original.from) }))
    body = `<p><br></p><p><br></p><div>${header}</div><blockquote>${DOMPurify.sanitize(original.html)}</blockquote>`
  }

  const signature = from.value?.signature_html
  if (signature) body = body.replace('<p><br></p>', `<p><br></p><p>--<br>${signature}</p>`)

  editor.value.innerHTML = body
  if (mode === 'reply' || mode === 'replyAll') {
    editor.value.focus()
    placeCaretAtStart(editor.value)
  }
})

function placeCaretAtStart(el) {
  const range = document.createRange()
  range.setStart(el, 0)
  range.collapse(true)
  const sel = getSelection()
  sel.removeAllRanges()
  sel.addRange(range)
}

// Minimal formatting. execCommand is old but still works everywhere;
// a proper editor (e.g. Tiptap) is on the roadmap.
function format(cmd, value = null) {
  if (cmd === 'createLink') {
    value = prompt('Link (https://…)')
    if (!value) return
  }
  document.execCommand(cmd, false, value)
  editor.value.focus()
}

function addFiles(e) {
  files.value.push(...e.target.files)
  e.target.value = ''
}

async function send() {
  toInput.value?.commitText()
  if (!to.value.length && !cc.value.length && !bcc.value.length) {
    toasts.error(t('toast.failed', { msg: 'voeg minstens één ontvanger toe' }))
    return
  }
  sending.value = true
  const payload = {
    to: to.value, cc: cc.value, bcc: bcc.value,
    subject: subject.value,
    html: editor.value.innerHTML,
  }
  if (original && props.draft.mode !== 'forward' && props.draft.mode !== 'new') {
    payload.in_reply_to = original.message_id
    payload.references = [original.references, original.message_id].filter(Boolean).join(' ')
    payload.reply_folder = original.folder
    payload.reply_uid = original.uid
  }
  const form = new FormData()
  form.append('payload', JSON.stringify(payload))
  files.value.forEach((f) => form.append('files[]', f))

  try {
    await api.upload(`/accounts/${fromId.value}/send`, form)
    toasts.show(t('toast.sent'))
    emit('close')
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    sending.value = false
  }
}

function discard() {
  const dirty = to.value.length || subject.value || editor.value.innerText.trim().length > 0
  if (!dirty || props.draft.mode !== 'new' || confirm(`${t('compose.discard')}?`)) emit('close')
}
</script>

<template>
  <div class="fixed inset-0 z-40 flex items-end justify-end bg-ink/30 sm:bg-transparent sm:p-4 sm:pointer-events-none">
    <form
      class="pt-safe pb-safe pointer-events-auto flex h-full w-full flex-col bg-surface shadow-2xl ring-1 ring-line sm:h-[min(640px,calc(100vh-2rem))] sm:w-[560px] sm:rounded-2xl"
      @submit.prevent="send"
    >
      <header class="flex items-center gap-2 border-b border-line px-2 py-2 sm:px-4">
        <button type="button" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('compose.discard')" @click="discard">
          <AppIcon name="close" />
        </button>
        <h2 class="flex-1 truncate font-semibold">{{ subject || t('compose.title') }}</h2>
        <button type="button" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('compose.attach')" @click="fileInput.click()">
          <AppIcon name="paperclip" />
        </button>
        <button
          type="submit"
          class="flex items-center gap-2 rounded-full bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
          :disabled="sending"
        >
          <AppIcon name="send" :size="17" />
          {{ sending ? t('compose.sending') : t('compose.send') }}
        </button>
      </header>

      <div v-if="session.accounts.length > 1" class="flex items-center gap-2 border-b border-line px-4 py-2 text-sm">
        <span class="text-muted">{{ t('compose.from') }}</span>
        <select v-model="fromId" class="min-w-0 flex-1 bg-transparent outline-none">
          <option v-for="a in session.accounts" :key="a.id" :value="a.id">
            {{ a.display_name ? `${a.display_name} <${a.email}>` : a.email }}
          </option>
        </select>
      </div>

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

      <div class="flex gap-0.5 border-b border-line px-2 py-1 text-muted">
        <button type="button" class="rounded px-2 py-1 font-bold hover:bg-sunken" title="Vet" @mousedown.prevent="format('bold')">B</button>
        <button type="button" class="rounded px-2 py-1 italic hover:bg-sunken" title="Cursief" @mousedown.prevent="format('italic')">I</button>
        <button type="button" class="rounded px-2 py-1 underline hover:bg-sunken" title="Onderstrepen" @mousedown.prevent="format('underline')">U</button>
        <button type="button" class="rounded px-2 py-1 hover:bg-sunken" title="Lijst" @mousedown.prevent="format('insertUnorderedList')">•</button>
        <button type="button" class="rounded px-2 py-1 hover:bg-sunken" title="Link" @mousedown.prevent="format('createLink')">🔗</button>
      </div>

      <div
        ref="editor"
        contenteditable="true"
        class="compose-editor min-h-0 flex-1 overflow-y-auto px-4 py-3 outline-none"
      />

      <ul v-if="files.length" class="flex flex-wrap gap-2 border-t border-line px-4 py-2">
        <li v-for="(f, i) in files" :key="i" class="flex items-center gap-2 rounded-lg bg-sunken px-2.5 py-1 text-sm">
          <span class="max-w-48 truncate">{{ f.name }}</span>
          <span class="text-muted">{{ fileSize(f.size) }}</span>
          <button type="button" class="text-muted hover:text-ink" @click="files.splice(i, 1)">×</button>
        </li>
      </ul>
      <input ref="fileInput" type="file" multiple class="hidden" @change="addFiles" />
    </form>
  </div>
</template>

<style scoped>
.compose-editor :deep(blockquote) {
  margin: 0.5em 0 0 0.25em;
  padding-left: 0.75em;
  border-left: 3px solid var(--color-line);
  color: var(--color-muted);
}
.compose-editor :deep(img) {
  max-width: 100%;
}
</style>
