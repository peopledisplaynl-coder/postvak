<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import AppIcon from './AppIcon.vue'
import MessageRow from './MessageRow.vue'
import FolderPicker from './FolderPicker.vue'
import LabelMenu from './LabelMenu.vue'
import ActionMenu from './ActionMenu.vue'
import { useRouter } from 'vue-router'
import { messageActions } from '../messageActions.js'
import { useSession } from '../stores/session.js'
import { labelName, labelMap } from '../labels.js'
import { useMail, msgKey } from '../stores/mail.js'
import { useToasts } from '../stores/toasts.js'
import { t } from '../i18n/index.js'

const props = defineProps({ title: { type: String, default: '' } })
const session = useSession()
const activeLabel = computed(() => (mail.labelFilter ? labelMap(session.settings)[mail.labelFilter.toLowerCase()] : null))
const emit = defineEmits(['open', 'menu', 'shortcuts', 'reply'])

const mail = useMail()
const scroller = ref(null)
const sentinel = ref(null)
const searchInput = ref(mail.search)
const searching = ref(false)

// ---- infinite scroll
let observer
onMounted(() => {
  observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting) mail.loadMore()
  }, { root: scroller.value, rootMargin: '150px' })
  if (sentinel.value) observer.observe(sentinel.value)
})
onBeforeUnmount(() => observer?.disconnect())

// ---- search (debounced)
let searchTimer
watch(searchInput, (q) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    mail.search = q.trim()
    mail.loadMessages()
  }, 350)
})
const moreOpen = ref(false)
const searchField = ref(null)

function toggleSearchBody() {
  mail.searchBody = !mail.searchBody
  if (mail.search) mail.loadMessages()
  searchField.value?.focus()
}

/** "/" shortcut: open the search field. */
function openSearch() {
  searching.value = true
  nextTick(() => searchField.value?.focus())
}
defineExpose({ openSearch })

// "More from this sender" (from the reader) opens the search with the sender filter.
watch(() => mail.filters.from, (from) => { if (from) searching.value = true }, { immediate: true })
const periods = [7, 30, 365]
const INBOX_TABS = [
  { key: 'primary', icon: 'inbox' }, { key: 'promotions', icon: 'tag' },
  { key: 'social', icon: 'contacts' }, { key: 'updates', icon: 'bell' },
]
const periodOpen = ref(false)
function choosePeriod(days) {
  periodOpen.value = false
  mail.setFilter('since', days)
}

function closeSearch() {
  searching.value = false
  mail.clearFilters()
  if (searchInput.value) searchInput.value = ''
}

// ---- pull to refresh (touch only, at the top of the list)
const pull = ref(0)
let pullStart = null
function onTouchStart(e) {
  pullStart = scroller.value.scrollTop <= 0 ? e.touches[0].clientY : null
}
function onTouchMove(e) {
  if (pullStart === null) return
  const dy = e.touches[0].clientY - pullStart
  pull.value = dy > 0 ? Math.min(90, dy * 0.5) : 0
}
async function onTouchEnd() {
  if (pull.value >= 60) {
    pull.value = 48
    await mail.loadMessages({ silent: true })
  }
  pull.value = 0
  pullStart = null
}

// ---- multi-select
const picking = ref(false)
const pickAccount = ref(null)
function startMove() {
  // Moving works within one mailbox account.
  const accounts = new Set(mail.selectedMessages.map((m) => m.account_id))
  if (accounts.size > 1) {
    useToasts().error(t('list.moveOneAccount'))
    return
  }
  pickAccount.value = [...accounts][0]
  picking.value = true
}
const allSeen = computed(() => mail.selectedMessages.every((m) => m.seen))
// Messages the folder picker moves: the selection, or the row of the right-click menu.
const pickTargets = ref(null)
function moveSelected({ path, label }) {
  picking.value = false
  mail.moveTo(pickTargets.value ?? mail.selectedMessages, path, label)
  pickTargets.value = null
}

// ---- right-click menu: acts on the selection if the row is part of it, otherwise on that row
const router = useRouter()
const context = ref(null)
function openContext({ message, x, y }) {
  const key = msgKey(message)
  const messages = mail.selecting && mail.selected.includes(key) ? mail.selectedMessages : [message]
  const items = messageActions({
    messages,
    router,
    onOpen: () => emit('open', message),
    onReply: (mode) => emit('reply', { mode, message }),
    onMove: () => {
      const accounts = new Set(messages.map((m) => m.account_id))
      if (accounts.size > 1) {
        useToasts().error(t('list.moveOneAccount'))
        return
      }
      pickTargets.value = messages
      pickAccount.value = [...accounts][0]
      picking.value = true
    },
  })
  context.value = { items, x, y }
}
function onKey(e) {
  if (e.key === 'Escape' && mail.selecting && !picking.value) mail.clearSelection()
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

// ---- sorting
const sortOpen = ref(false)
const sortKeys = ['date_desc', 'date_asc', 'from', 'subject', 'size_desc']
function chooseSort(key) {
  sortOpen.value = false
  if (key !== mail.sort) mail.setSort(key)
}

// ---- empty trash / spam
const emptyable = computed(() => !mail.unified && (mail.folderRole === 'trash' || mail.folderRole === 'junk'))
const emptying = ref(false)
async function emptyFolder() {
  emptying.value = true
  try {
    await mail.emptyCurrentFolder()
  } finally {
    emptying.value = false
  }
}

const emptyText = computed(() => (mail.search ? t('list.emptySearch') : t('list.empty')))
</script>

<template>
  <section class="flex h-full min-h-0 flex-col bg-surface">
    <header class="pt-safe flex items-center gap-1 border-b border-line px-2 py-2">
      <!-- Selection mode -->
      <template v-if="mail.selecting">
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('list.clearSelection')" @click="mail.clearSelection()">
          <AppIcon name="close" />
        </button>
        <span class="min-w-0 flex-1 truncate px-1 font-semibold tabular-nums">
          <span class="sm:hidden">{{ mail.selected.length }}</span>
          <span class="hidden sm:inline">{{ t('list.selected', { n: mail.selected.length }) }}</span>
        </span>
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('list.selectAll')" @click="mail.selectAll()">
          <AppIcon name="selectAll" />
        </button>
        <button v-if="mail.folderRole !== 'archive'" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.archive')" @click="mail.bulk('archive')">
          <AppIcon name="archive" />
        </button>
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-danger" :title="t('actions.trash')" @click="mail.bulk('trash')">
          <AppIcon name="trash" />
        </button>
        <button
          v-if="!['sent', 'drafts', 'scheduled'].includes(mail.folderRole)"
          class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink"
          :title="mail.folderRole === 'junk' ? t('actions.notJunk') : t('actions.junk')"
          @click="mail.bulk('junk')"
        >
          <AppIcon :name="mail.folderRole === 'junk' ? 'inbox' : 'junk'" />
        </button>
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.read')" @click="mail.bulk('read')">
          <AppIcon :name="allSeen ? 'unread' : 'read'" />
        </button>
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.flag')" @click="mail.bulk('flag')">
          <AppIcon name="flag" />
        </button>
        <LabelMenu v-if="session.settings.labels?.length" :messages="mail.selectedMessages" />
        <button v-if="mail.folderRole === 'snoozed'" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.unsnooze')" @click="mail.bulk('unsnooze')">
          <AppIcon name="inbox" />
        </button>
        <button v-if="!['drafts', 'sent', 'scheduled'].includes(mail.folderRole)" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.snooze')" @click="mail.bulk('snooze')">
          <AppIcon name="clock" />
        </button>
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('actions.move')" @click="startMove">
          <AppIcon name="move" />
        </button>
      </template>
      <template v-else-if="!searching">
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink lg:hidden" :aria-label="t('actions.settings')" @click="emit('menu')">
          <AppIcon name="menu" />
        </button>
        <h1 class="min-w-0 flex-1 truncate px-2 text-lg font-semibold">{{ title }}</h1>
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('list.search')" @click="searching = true">
          <AppIcon name="search" />
        </button>
        <div class="relative">
          <button
            class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink"
            :aria-label="t('list.sort')"
            :title="t(`list.sortOptions.${mail.sort}`)"
            :aria-expanded="sortOpen"
            @click="sortOpen = !sortOpen"
          >
            <AppIcon name="sort" />
          </button>
          <div v-if="sortOpen" class="fixed inset-0 z-20" @click="sortOpen = false" />
          <ul v-if="sortOpen" class="absolute right-0 z-30 mt-1 w-52 overflow-hidden rounded-xl bg-surface py-1 shadow-lg ring-1 ring-line" role="menu">
            <li v-for="key in sortKeys" :key="key">
              <button
                role="menuitemradio"
                :aria-checked="mail.sort === key"
                class="flex w-full items-center justify-between px-4 py-2 text-left text-sm hover:bg-sunken"
                :class="mail.sort === key ? 'font-medium text-accent' : ''"
                @click="chooseSort(key)"
              >
                {{ t(`list.sortOptions.${key}`) }}
                <span v-if="mail.sort === key">✓</span>
              </button>
            </li>
          </ul>
        </div>
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('list.refresh')" @click="mail.loadMessages()">
          <AppIcon name="refresh" :class="mail.loading ? 'animate-spin' : ''" />
        </button>
        <div class="relative">
          <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('list.more')" :aria-expanded="moreOpen" @click="moreOpen = !moreOpen">
            <AppIcon name="more" />
          </button>
          <div v-if="moreOpen" class="fixed inset-0 z-20" @click="moreOpen = false" />
          <div v-if="moreOpen" class="absolute right-0 z-30 mt-1 w-60 overflow-hidden rounded-xl bg-surface py-1 shadow-lg ring-1 ring-line" role="menu">
            <button role="menuitem" class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-sunken" @click="moreOpen = false; mail.markAllRead()">
              <AppIcon name="read" :size="18" class="text-muted" /> {{ t('list.markAllRead') }}
            </button>
            <button role="menuitem" class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-sunken" @click="moreOpen = false; emit('shortcuts')">
              <AppIcon name="keyboard" :size="18" class="text-muted" /> {{ t('list.shortcuts') }}
            </button>
          </div>
        </div>
      </template>
      <template v-else>
        <button class="rounded-lg p-2 text-muted hover:bg-sunken" :aria-label="t('actions.back')" @click="closeSearch">
          <AppIcon name="back" />
        </button>
        <input
          ref="searchField"
          v-model="searchInput"
          type="search"
          :placeholder="t('list.search')"
          class="min-w-0 flex-1 rounded-lg bg-sunken px-3 py-2 outline-none placeholder:text-muted"
          autofocus
        />
        <button
          class="shrink-0 rounded-lg px-2.5 py-1.5 text-xs font-medium ring-1 transition"
          :class="mail.searchBody ? 'bg-accent-soft text-accent ring-accent/40' : 'text-muted ring-line hover:bg-sunken'"
          :aria-pressed="mail.searchBody"
          :title="t('list.searchBodyHint')"
          @click="toggleSearchBody"
        >{{ t('list.searchBody') }}</button>
      </template>
    </header>
    <!-- Inbox tabs, like Gmail (setting "Inbox tabs") -->
    <div v-if="mail.tabsActive && !searching" class="flex border-b border-line" role="tablist" :aria-label="t('tabs.label')">
      <button
        v-for="tab in INBOX_TABS.filter((x) => mail.enabledTabs.includes(x.key))" :key="tab.key" role="tab" :aria-selected="mail.currentTab === tab.key"
        class="flex min-w-0 flex-1 items-center justify-center gap-1.5 border-b-2 px-2 py-2.5 text-sm transition"
        :class="mail.currentTab === tab.key ? 'border-accent font-medium text-accent' : 'border-transparent text-muted hover:bg-sunken hover:text-ink'"
        @click="mail.setCategory(tab.key)"
      >
        <AppIcon :name="tab.icon" :size="16" class="shrink-0" /><span class="truncate">{{ t(`tabs.${tab.key}`) }}</span>
      </button>
    </div>

    <!-- Search filters -->
    <div v-if="searching" class="flex flex-wrap items-center gap-1.5 border-b border-line px-3 py-2" role="group" :aria-label="t('filters.label')">
      <button
        v-for="key in ['unseen', 'flagged', 'attachment']" :key="key"
        class="rounded-full px-3 py-1 text-sm ring-1 transition"
        :class="mail.filters[key] ? 'bg-accent-soft font-medium text-accent ring-accent/40' : 'text-muted ring-line hover:bg-sunken'"
        :aria-pressed="mail.filters[key]"
        @click="mail.setFilter(key, !mail.filters[key])"
      >{{ t(`filters.${key}`) }}</button>
      <div class="relative">
        <button
          class="flex items-center gap-1 rounded-full px-3 py-1 text-sm ring-1 transition"
          :class="mail.filters.since ? 'bg-accent-soft font-medium text-accent ring-accent/40' : 'text-muted ring-line hover:bg-sunken'"
          :aria-expanded="periodOpen"
          @click="periodOpen = !periodOpen"
        >{{ mail.filters.since ? t(`filters.since${mail.filters.since}`) : t('filters.period') }} ▾</button>
        <div v-if="periodOpen" class="fixed inset-0 z-20" @click="periodOpen = false" />
        <ul v-if="periodOpen" class="absolute left-0 z-30 mt-1 w-48 overflow-hidden rounded-xl bg-surface py-1 shadow-lg ring-1 ring-line" role="menu">
          <li v-for="d in [0, ...periods]" :key="d">
            <button role="menuitemradio" :aria-checked="mail.filters.since === d" class="flex w-full justify-between px-4 py-2 text-left text-sm hover:bg-sunken" :class="mail.filters.since === d ? 'font-medium text-accent' : ''" @click="choosePeriod(d)">
              {{ d ? t(`filters.since${d}`) : t('filters.anyTime') }}<span v-if="mail.filters.since === d">✓</span>
            </button>
          </li>
        </ul>
      </div>
      <span v-if="mail.filters.from" class="flex min-w-0 items-center gap-1 rounded-full bg-accent-soft py-1 pr-1 pl-3 text-sm font-medium text-accent ring-1 ring-accent/40">
        <span class="truncate">{{ t('filters.from', { email: mail.filters.from }) }}</span>
        <button class="rounded-full px-1.5 hover:bg-accent/10" :aria-label="t('filters.clearFrom')" @click="mail.setFilter('from', '')">✕</button>
      </span>
      <button v-if="mail.filtersActive" class="ml-auto rounded-lg px-2 py-1 text-sm text-muted hover:bg-sunken hover:text-ink" @click="mail.clearFilters()">{{ t('filters.clear') }}</button>
    </div>

    <!-- Label filter -->
    <div v-if="mail.labelFilter" class="flex items-center gap-2 border-b border-line px-4 py-2 text-sm">
      <span class="text-muted">{{ t('labels.filtered') }}</span>
      <span class="flex items-center gap-1.5 rounded-full px-2.5 py-0.5 font-medium" :style="{ background: (activeLabel?.color ?? '#677385') + '22', color: activeLabel?.color }">
        <span class="size-2 rounded-full" :style="{ background: activeLabel?.color }" />{{ activeLabel ? labelName(activeLabel) : mail.labelFilter }}
      </span>
      <button class="ml-auto rounded-lg px-2 py-1 text-muted hover:bg-sunken hover:text-ink" @click="mail.filterLabel(null)">{{ t('labels.clearFilter') }}</button>
    </div>

    <div
      ref="scroller"
      class="relative min-h-0 flex-1 overflow-y-auto overscroll-contain"
      @touchstart.passive="onTouchStart"
      @touchmove.passive="onTouchMove"
      @touchend="onTouchEnd"
    >
      <div
        class="flex items-center justify-center overflow-hidden text-muted transition-[height] duration-150"
        :style="{ height: pull + 'px' }"
      >
        <AppIcon name="refresh" :class="pull >= 48 ? 'animate-spin text-accent' : ''" :style="{ transform: `rotate(${pull * 3}deg)` }" />
      </div>

      <!-- Trash / spam: empty the whole folder -->
      <div
        v-if="emptyable && mail.messages.length && !mail.search"
        class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line bg-sunken/60 px-4 py-2.5 text-sm"
      >
        <span class="min-w-0 flex-1 text-muted">{{ mail.folderRole === 'trash' ? t('list.trashHint') : t('list.junkHint') }}</span>
        <button
          class="shrink-0 rounded-lg px-2 py-1 font-medium text-danger hover:bg-danger/10 disabled:opacity-60"
          :disabled="emptying"
          @click="emptyFolder"
        >
          {{ mail.folderRole === 'trash' ? t('list.emptyTrash') : t('list.emptyJunk') }}
        </button>
      </div>

      <div v-if="mail.loading && !mail.messages.length" class="space-y-px">
        <div v-for="i in 8" :key="i" class="flex gap-3 px-4 py-3">
          <div class="size-10 animate-pulse rounded-full bg-sunken" />
          <div class="flex-1 space-y-2 py-1">
            <div class="h-3 w-1/3 animate-pulse rounded bg-sunken" />
            <div class="h-3 w-2/3 animate-pulse rounded bg-sunken" />
          </div>
        </div>
      </div>

      <p v-else-if="!mail.messages.length" class="px-6 py-16 text-center text-muted">{{ emptyText }}</p>

      <ul v-else class="divide-y divide-line/70">
        <MessageRow
          v-for="m in mail.messages"
          :key="msgKey(m)"
          :message="m"
          :active="msgKey(m) === mail.openKey"
          @open="emit('open', $event)"
          @context="openContext"
        />
      </ul>

      <div ref="sentinel" class="h-px" />
      <p v-if="mail.loadingMore" class="py-4 text-center text-sm text-muted">…</p>
      <div v-else-if="mail.hasMore && !mail.loading && mail.messages.length" class="py-3 text-center">
        <button class="rounded-xl px-4 py-2 text-sm text-accent hover:bg-sunken" @click="mail.loadMore()">
          {{ t('list.loadMore', { shown: mail.messages.length, total: mail.total }) }}
        </button>
      </div>
    </div>

    <FolderPicker v-if="picking" :account-id="pickAccount" :exclude="mail.folderPath" :count="(pickTargets ?? mail.selectedMessages).length" @close="picking = false; pickTargets = null" @pick="moveSelected" />
    <ActionMenu v-if="context" :items="context.items" :x="context.x" :y="context.y" @close="context = null" />
  </section>
</template>
