<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, defineAsyncComponent } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Sidebar from '../components/Sidebar.vue'
import MessageList from '../components/MessageList.vue'
import MessageView from '../components/MessageView.vue'
import AppIcon from '../components/AppIcon.vue'
import TimePicker from '../components/TimePicker.vue'
import ShortcutsHelp from '../components/ShortcutsHelp.vue'
import ColumnResizer from '../components/ColumnResizer.vue'
import { useSession } from '../stores/session.js'
import { useMail, msgKey, ALL } from '../stores/mail.js'
import { useToasts } from '../stores/toasts.js'
import { useOutbox } from '../stores/outbox.js'
import { t } from '../i18n/index.js'

// The composer (with the rich text editor) is loaded on first use, keeping the list fast.
const ComposeSheet = defineAsyncComponent(() => import('../components/ComposeSheet.vue'))

const route = useRoute()
const router = useRouter()
const session = useSession()
const mail = useMail()
const toasts = useToasts()

const drawer = ref(false)
const draft = ref(null)

const accountId = computed(() => (route.params.accountId === ALL ? ALL : Number(route.params.accountId) || session.loginAccount?.id))
const folder = computed(() => route.query.folder || 'INBOX')
// The open message: ?uid=12&acc=3 (acc is needed in the unified inbox).
const target = computed(() => {
  if (!route.query.uid) return null
  const acc = Number(route.query.acc) || (accountId.value === ALL ? null : accountId.value)
  if (!acc) return null
  return { account_id: acc, folder: accountId.value === ALL ? 'INBOX' : folder.value, uid: Number(route.query.uid) }
})
const uid = computed(() => target.value?.uid ?? null)

const title = computed(() => {
  if (mail.unified) return t('folders.unified')
  const f = mail.currentFolder
  if (!f) return t('folders.inbox')
  if (f.role) return t(`folders.${f.role}`)
  return f.name
})

// Route is the source of truth: account + folder + open message.
watch([accountId, folder], ([a, f]) => {
  if (!a) return
  mail.search = ''
  mail.open(a, f)
}, { immediate: true })
watch(target, (tg) => (mail.openKey = tg ? msgKey(tg) : null), { immediate: true })

function navigate(target) {
  drawer.value = false
  if (!target) return
  router.push({ name: 'mail', params: { accountId: target.accountId }, query: { folder: target.folder } })
}

function openMessage(m) {
  // A draft opens in the composer, everything else in the reader.
  if (mail.folderRole === 'drafts') {
    draft.value = { accountId: m.account_id, mode: 'draft', message: m }
    return
  }
  router.push({ query: { ...route.query, uid: m.uid, acc: m.account_id } })
}

function closeMessage() {
  const { uid: _, acc: __, ...rest } = route.query
  router.push({ query: rest })
}

function compose(mode = 'new', message = null, extra = {}) {
  // Reply from the account the message belongs to; new mail from the open account.
  const from = message?.account_id ?? (mail.unified ? session.loginAccount?.id : mail.accountId)
  draft.value = { accountId: from, mode, message, ...extra }
}

// ?compose=jan@x.nl&name=Jan opens the composer (link from the contacts page).
watch(() => route.query.compose, (email) => {
  if (!email) return
  const name = route.query.name || ''
  const { compose: _, name: __, ...rest } = route.query
  router.replace({ query: rest })
  draft.value = { accountId: mail.unified ? session.loginAccount?.id : accountId.value, mode: 'new', to: [{ email: String(email), name: String(name) }] }
}, { immediate: true })

// Undo send: reopen the draft (once the composer is free).
const outbox = useOutbox()
watch([() => outbox.reopen, draft], ([ref, open]) => {
  if (ref && !open) {
    editDraft(ref)
    outbox.reopen = null
  }
})

/** A cancelled scheduled message is back in Drafts: continue it in the composer. */
function editDraft(ref) {
  draft.value = { accountId: ref.account_id, mode: 'draft', message: { account_id: ref.account_id, folder: ref.folder, uid: ref.uid } }
}

const snoozeCount = computed(() => (mail.snoozeRequest ?? []).reduce((n, m) => n + (m.thread?.count ?? 1), 0))
function onSnoozePick(date) {
  const messages = mail.snoozeRequest ?? []
  const openOne = messages.some((m) => msgKey(m) === mail.openKey)
  if (mail.snooze(messages, date) && openOne && mail.folderRole !== 'snoozed') closeMessage()
}

function onComposeClose(result) {
  draft.value = null
  // Drafts folder shows saved/sent drafts: refresh it.
  if (mail.folderRole === 'drafts' || result?.saved) mail.loadMessages({ silent: true })
}

// ---- poll for new mail
let pollTimer
function schedulePoll() {
  clearTimeout(pollTimer)
  pollTimer = setTimeout(async () => {
    if (document.visibilityState === 'visible' && !mail.search) {
      await Promise.all([mail.loadMessages({ silent: true }), mail.loadFolders()])
    }
    schedulePoll()
  }, (session.settings.poll_seconds ?? 60) * 1000)
}

function onVisible() {
  if (document.visibilityState === 'visible') mail.loadMessages({ silent: true })
}

// ---- keyboard shortcuts (desktop)
const showShortcuts = ref(false)
const listRef = ref(null)

/** r / a / f on the open message (the newest one of a conversation). */
async function replyByKey(mode) {
  try {
    compose(mode, await mail.fetchMessage(target.value))
  } catch (err) {
    toasts.error(t('toast.failed', { msg: err.message }))
  }
}

function onKey(e) {
  if (e.target.closest('input, textarea, select, [contenteditable]') || draft.value || mail.snoozeRequest || showShortcuts.value || e.metaKey || e.ctrlKey || e.altKey) return
  const list = mail.messages
  const i = list.findIndex((m) => msgKey(m) === mail.openKey)
  const current = list[i]
  switch (e.key) {
    case 'j': if (list[i + 1]) openMessage(list[i + 1]); break
    case 'k': if (i > 0) openMessage(list[i - 1]); break
    case 'e':
      if (mail.selecting) mail.bulk('archive')
      else if (current) mail.act('archive', current).then((ok) => ok && closeMessage())
      break
    case '#': case 'Delete':
      if (mail.selecting) mail.bulk('trash')
      else if (current) mail.act('trash', current).then((ok) => ok && closeMessage())
      break
    case '!':
      if (mail.selecting) mail.bulk('junk')
      else if (current) mail.act('junk', current).then((ok) => ok && closeMessage())
      break
    case 'b':
      if (mail.selecting) mail.bulk('snooze')
      else if (current) mail.snoozeRequest = [current]
      break
    case 'x': if (current) mail.toggleSelect(msgKey(current)); break
    case 'c': e.preventDefault(); compose(); break
    case 'r': case 'a': case 'f':
      if (target.value) {
        e.preventDefault()
        replyByKey({ r: 'reply', a: 'replyAll', f: 'forward' }[e.key])
      }
      break
    case '/': e.preventDefault(); listRef.value?.openSearch(); break
    case '?': e.preventDefault(); showShortcuts.value = true; break
    case 'Escape': if (uid.value) closeMessage(); break
    default: return
  }
}

onMounted(() => {
  schedulePoll()
  document.addEventListener('visibilitychange', onVisible)
  window.addEventListener('keydown', onKey)
})
onBeforeUnmount(() => {
  clearTimeout(pollTimer)
  document.removeEventListener('visibilitychange', onVisible)
  window.removeEventListener('keydown', onKey)
})

// Column widths on larger screens, adjustable by dragging the column edge (remembered on this device).
const columns = (() => {
  const read = (key) => {
    try {
      const v = Number(localStorage.getItem(key))
      return Number.isFinite(v) && v > 0 ? v : null
    } catch {
      return null
    }
  }
  const sidebar = ref(read('pv-col-sidebar'))
  const list = ref(read('pv-col-list'))
  const save = (key, v) => {
    try {
      v === null ? localStorage.removeItem(key) : localStorage.setItem(key, String(v))
    } catch {}
  }
  watch(sidebar, (v) => save('pv-col-sidebar', v))
  watch(list, (v) => save('pv-col-list', v))
  return { sidebar, list }
})()
const viewportWidth = ref(window.innerWidth)
const onResize = () => (viewportWidth.value = window.innerWidth)
onMounted(() => window.addEventListener('resize', onResize))
onBeforeUnmount(() => window.removeEventListener('resize', onResize))
// Defaults match the Tailwind classes: sidebar 16rem, list 360px (420px from 1280px).
const sidebarWidth = computed(() => columns.sidebar.value ?? 256)
const listWidth = computed(() => columns.list.value ?? (viewportWidth.value >= 1280 ? 420 : 360))
// The reader keeps at least 360px.
const listMax = computed(() => Math.max(300, viewportWidth.value - (viewportWidth.value >= 1024 ? sidebarWidth.value : 0) - 360))
const layoutStyle = computed(() => ({
  ...(columns.sidebar.value ? { '--pv-side-w': `${columns.sidebar.value}px` } : {}),
  ...(columns.list.value ? { '--pv-list-w': `${Math.min(columns.list.value, listMax.value)}px` } : {}),
}))
</script>

<template>
  <div class="flex h-full overflow-hidden" :style="layoutStyle">
    <!-- Sidebar: fixed column on large screens, drawer elsewhere -->
    <aside class="relative hidden shrink-0 border-r border-line lg:block" :class="columns.sidebar.value ? 'w-(--pv-side-w)' : 'w-64'">
      <Sidebar @navigate="navigate" @compose="compose()" />
      <ColumnResizer :width="sidebarWidth" :min="200" :max="420" :label="t('layout.resizeSidebar')" @update:width="(w) => (columns.sidebar.value = w)" @reset="columns.sidebar.value = null" />
    </aside>
    <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
      <div v-if="drawer" class="fixed inset-0 z-30 bg-ink/40 lg:hidden" @click="drawer = false" />
    </Transition>
    <Transition enter-from-class="-translate-x-full" leave-to-class="-translate-x-full" enter-active-class="transition duration-200 ease-out" leave-active-class="transition duration-150">
      <aside v-if="drawer" class="fixed inset-y-0 left-0 z-30 w-[min(20rem,85vw)] shadow-xl lg:hidden">
        <Sidebar @navigate="navigate" @compose="drawer = false; compose()" />
      </aside>
    </Transition>

    <!-- List: full width on phones (hidden while reading), fixed column from tablet up -->
    <div
      class="relative min-w-0 flex-1 border-line md:flex-none md:border-r"
      :class="[uid ? 'hidden md:block' : 'block', columns.list.value ? 'md:w-(--pv-list-w)' : 'md:w-[360px] xl:w-[420px]']"
    >
      <MessageList ref="listRef" :title="title" @open="openMessage" @menu="drawer = true" @shortcuts="showShortcuts = true" />
      <ColumnResizer class="hidden md:block" :width="listWidth" :min="280" :max="listMax" :label="t('layout.resizeList')" @update:width="(w) => (columns.list.value = w)" @reset="columns.list.value = null" />
    </div>

    <!-- Reader -->
    <div class="min-w-0 flex-1" :class="uid ? 'block' : 'hidden md:block'">
      <MessageView :target="target" @back="closeMessage" @reply="({ mode, message, text, send }) => compose(mode, message, { text, send })" @edit-draft="editDraft" />
    </div>

    <!-- Floating compose button on phones and tablets -->
    <button
      v-if="!uid && !draft && !mail.selecting"
      class="fixed right-5 bottom-[calc(1.25rem+env(safe-area-inset-bottom))] z-20 flex size-14 items-center justify-center rounded-2xl bg-accent text-white shadow-lg transition active:scale-95 lg:hidden"
      :class="toasts.items.length ? '-translate-y-16 sm:translate-y-0' : ''"
      :aria-label="t('actions.compose')"
      @click="compose()"
    >
      <AppIcon name="compose" :size="24" />
    </button>

    <ShortcutsHelp v-if="showShortcuts" @close="showShortcuts = false" />
    <TimePicker v-if="mail.snoozeRequest?.length" mode="snooze" :count="snoozeCount" @pick="onSnoozePick" @close="mail.snoozeRequest = null" />
    <ComposeSheet v-if="draft" :key="draft.message ? msgKey(draft.message) : 'new'" :draft="draft" @close="onComposeClose" />
  </div>
</template>
