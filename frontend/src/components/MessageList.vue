<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import AppIcon from './AppIcon.vue'
import MessageRow from './MessageRow.vue'
import FolderPicker from './FolderPicker.vue'
import { useMail, msgKey } from '../stores/mail.js'
import { useToasts } from '../stores/toasts.js'
import { t } from '../i18n/index.js'

const props = defineProps({ title: { type: String, default: '' } })
const emit = defineEmits(['open', 'menu', 'shortcuts'])

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
  }, { root: scroller.value, rootMargin: '400px' })
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

function closeSearch() {
  searching.value = false
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
function moveSelected({ path, label }) {
  picking.value = false
  mail.moveTo(mail.selectedMessages, path, label)
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
        />
      </ul>

      <div ref="sentinel" class="h-px" />
      <p v-if="mail.loadingMore" class="py-4 text-center text-sm text-muted">…</p>
    </div>

    <FolderPicker v-if="picking" :account-id="pickAccount" :exclude="mail.folderPath" :count="mail.selected.length" @close="picking = false" @pick="moveSelected" />
  </section>
</template>
