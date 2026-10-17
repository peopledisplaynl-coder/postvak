<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Sidebar from '../components/Sidebar.vue'
import MessageList from '../components/MessageList.vue'
import MessageView from '../components/MessageView.vue'
import ComposeSheet from '../components/ComposeSheet.vue'
import AppIcon from '../components/AppIcon.vue'
import { useSession } from '../stores/session.js'
import { useMail } from '../stores/mail.js'
import { useToasts } from '../stores/toasts.js'
import { t } from '../i18n/index.js'

const route = useRoute()
const router = useRouter()
const session = useSession()
const mail = useMail()
const toasts = useToasts()

const drawer = ref(false)
const draft = ref(null)

const accountId = computed(() => Number(route.params.accountId) || session.loginAccount?.id)
const folder = computed(() => route.query.folder || 'INBOX')
const uid = computed(() => (route.query.uid ? Number(route.query.uid) : null))

const title = computed(() => {
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
watch(uid, (u) => (mail.openUid = u), { immediate: true })

function navigate(target) {
  drawer.value = false
  if (!target) return
  router.push({ name: 'mail', params: { accountId: target.accountId }, query: { folder: target.folder } })
}

function openMessage(m) {
  router.push({ query: { ...route.query, uid: m.uid } })
}

function closeMessage() {
  const { uid: _, ...rest } = route.query
  router.push({ query: rest })
}

function compose(mode = 'new', message = null) {
  draft.value = { accountId: mail.accountId, mode, message }
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
function onKey(e) {
  if (e.target.closest('input, textarea, select, [contenteditable]') || draft.value || e.metaKey || e.ctrlKey) return
  const list = mail.messages
  const i = list.findIndex((m) => m.uid === uid.value)
  const current = list[i]
  switch (e.key) {
    case 'j': if (list[i + 1]) openMessage(list[i + 1]); break
    case 'k': if (i > 0) openMessage(list[i - 1]); break
    case 'e': if (current) mail.act('archive', current).then((ok) => ok && closeMessage()); break
    case '#': case 'Delete': if (current) mail.act('trash', current).then((ok) => ok && closeMessage()); break
    case 'c': e.preventDefault(); compose(); break
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
</script>

<template>
  <div class="flex h-full overflow-hidden">
    <!-- Sidebar: fixed column on large screens, drawer elsewhere -->
    <aside class="hidden w-64 shrink-0 border-r border-line lg:block">
      <Sidebar @navigate="navigate" @compose="compose()" />
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
      class="min-w-0 flex-1 border-line md:w-[360px] md:border-r md:flex-none xl:w-[420px]"
      :class="uid ? 'hidden md:block' : 'block'"
    >
      <MessageList :title="title" @open="openMessage" @menu="drawer = true" />
    </div>

    <!-- Reader -->
    <div class="min-w-0 flex-1" :class="uid ? 'block' : 'hidden md:block'">
      <MessageView :uid="uid" @back="closeMessage" @reply="({ mode, message }) => compose(mode, message)" />
    </div>

    <!-- Floating compose button on phones and tablets -->
    <button
      v-if="!uid && !draft"
      class="fixed right-5 bottom-[calc(1.25rem+env(safe-area-inset-bottom))] z-20 flex size-14 items-center justify-center rounded-2xl bg-accent text-white shadow-lg transition active:scale-95 lg:hidden"
      :class="toasts.items.length ? '-translate-y-16 sm:translate-y-0' : ''"
      :aria-label="t('actions.compose')"
      @click="compose()"
    >
      <AppIcon name="compose" :size="24" />
    </button>

    <ComposeSheet v-if="draft" :draft="draft" @close="draft = null" />
  </div>
</template>
