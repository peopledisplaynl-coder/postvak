<script setup>
import { computed, ref } from 'vue'
import SidebarLabels from './SidebarLabels.vue'
import AppIcon from './AppIcon.vue'
import { useSession } from '../stores/session.js'
import { useMail, ALL } from '../stores/mail.js'
import { t } from '../i18n/index.js'
import { folderLabel, folderIcon } from '../folders.js'

const emit = defineEmits(['navigate', 'compose'])
const session = useSession()
const mail = useMail()

// While dragging in the unified inbox, show the folders of the dragged messages' account.
const dropAccountId = computed(() => (mail.unified && mail.drag?.accountId ? mail.drag.accountId : null))
const folderAccountId = computed(() => dropAccountId.value ?? mail.accountId)
const folders = computed(() => (mail.folders[folderAccountId.value] ?? []).filter((f) => f.selectable))

// ---- drop target for dragged messages
const overPath = ref(null)
function onDragover(e, f) {
  if (!mail.canDrop(f, folderAccountId.value)) return
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
  overPath.value = f.path
}
function onDragleave(e, f) {
  if (overPath.value === f.path && !e.currentTarget.contains(e.relatedTarget)) overPath.value = null
}
function onDrop(e, f) {
  e.preventDefault()
  overPath.value = null
  mail.dropOn(f, folderAccountId.value, folderLabel(f))
}
const showUnified = computed(() => session.accounts.length > 1 && session.settings.unified_inbox !== false)
// In the unified inbox: the inbox of every account as a shortcut.
const inboxes = computed(() => session.accounts.map((a) => ({ account: a, folder: (mail.folders[a.id] ?? []).find((f) => f.role === 'inbox') })))

function go(accountId, path = 'INBOX') {
  emit('navigate', { accountId, folder: path })
}
</script>

<template>
  <nav class="pt-safe pb-safe flex h-full flex-col bg-canvas">
    <div class="px-3 pt-4 pb-3">
      <button
        class="flex w-full items-center justify-center gap-2 rounded-2xl bg-accent px-4 py-3 font-medium text-white shadow-sm transition hover:brightness-110 active:scale-[.98]"
        @click="emit('compose')"
      >
        <AppIcon name="compose" />
        {{ t('actions.compose') }}
      </button>
    </div>

    <!-- Unified inbox -->
    <div v-if="showUnified" class="px-2 pb-2">
      <button
        class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left transition"
        :class="mail.unified ? 'bg-accent-soft font-medium text-accent' : 'hover:bg-sunken'"
        @click="go(ALL)"
      >
        <AppIcon name="inboxes" :size="19" />
        <span class="min-w-0 flex-1 truncate">{{ t('folders.unified') }}</span>
        <span v-if="mail.unifiedUnseen" class="text-sm font-semibold tabular-nums">{{ mail.unifiedUnseen }}</span>
      </button>
    </div>

    <!-- Accounts -->
    <!-- In the unified inbox the list below already shows every account (with unread count). -->
    <div v-if="session.accounts.length > 1 && !mail.unified" class="flex flex-wrap gap-1.5 px-3 pb-2">
      <button
        v-for="a in session.accounts"
        :key="a.id"
        class="flex shrink-0 items-center gap-2 rounded-full px-3 py-1.5 text-sm transition"
        :class="a.id === mail.accountId ? 'bg-surface font-medium shadow-sm ring-1 ring-line' : 'text-muted hover:bg-sunken'"
        :title="a.email"
        @click="go(a.id)"
      >
        <span class="size-2.5 rounded-full" :style="{ background: a.color || 'var(--color-accent)' }" />
        {{ a.display_name || a.email.split('@')[0] }}
      </button>
    </div>
    <p v-else-if="session.accounts.length <= 1" class="truncate px-6 pb-2 text-sm text-muted">{{ session.accounts[0]?.email }}</p>

    <!-- Unified: inbox per account -->
    <ul v-if="mail.unified && !dropAccountId" class="min-h-0 flex-1 overflow-y-auto px-2">
      <li v-for="{ account, folder } in inboxes" :key="account.id">
        <button class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left transition hover:bg-sunken" @click="go(account.id)">
          <span class="flex size-[19px] items-center justify-center"><span class="size-2.5 rounded-full" :style="{ background: account.color || 'var(--color-accent)' }" /></span>
          <span class="min-w-0 flex-1 truncate">{{ account.display_name || account.email }}</span>
          <span v-if="folder?.unseen" class="text-sm text-muted tabular-nums">{{ folder.unseen }}</span>
        </button>
      </li>
      <SidebarLabels @picked="emit('navigate', null)" />
    </ul>

    <!-- Folders of the active account (or of the dragged messages' account) -->
    <ul v-else class="min-h-0 flex-1 overflow-y-auto px-2">
      <li v-if="dropAccountId" class="truncate px-3 pb-1 text-xs font-medium text-muted">
        {{ t('list.dropOn', { account: session.accountById(dropAccountId)?.email ?? '' }) }}
      </li>
      <li v-for="f in folders" :key="f.path">
        <button
          class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left transition"
          :class="[
            overPath === f.path
              ? 'bg-accent text-white ring-2 ring-accent'
              : !dropAccountId && f.path === mail.folderPath ? 'bg-accent-soft font-medium text-accent' : 'hover:bg-sunken',
            mail.drag && !mail.canDrop(f, folderAccountId) ? 'opacity-40' : '',
          ]"
          @click="go(folderAccountId, f.path)"
          @dragover="onDragover($event, f)"
          @dragleave="onDragleave($event, f)"
          @drop="onDrop($event, f)"
        >
          <AppIcon :name="folderIcon(f)" :size="19" />
          <span class="min-w-0 flex-1 truncate">{{ folderLabel(f) }}</span>
          <span v-if="f.unseen && f.role !== 'trash' && f.role !== 'sent'" class="text-sm tabular-nums" :class="overPath === f.path ? 'text-white' : f.role === 'inbox' ? 'font-semibold' : 'text-muted'">
            {{ f.unseen }}
          </span>
        </button>
      </li>
      <SidebarLabels v-if="!dropAccountId" @picked="emit('navigate', null)" />
    </ul>

    <router-link
      to="/calendar"
      class="mx-2 flex items-center gap-2 rounded-xl px-3 py-2 text-muted hover:bg-sunken hover:text-ink"
      @click="emit('navigate', null)"
    >
      <AppIcon name="calendar" :size="19" />
      {{ t('calendar.title') }}
    </router-link>
    <router-link
      to="/tasks"
      class="mx-2 flex items-center gap-2 rounded-xl px-3 py-2 text-muted hover:bg-sunken hover:text-ink"
      @click="emit('navigate', null)"
    >
      <AppIcon name="task" :size="19" />
      {{ t('tasks.title') }}
    </router-link>
    <router-link
      to="/contacts"
      class="mx-2 flex items-center gap-2 rounded-xl px-3 py-2 text-muted hover:bg-sunken hover:text-ink"
      @click="emit('navigate', null)"
    >
      <AppIcon name="contacts" :size="19" />
      {{ t('contacts.title') }}
    </router-link>
    <router-link
      v-if="session.user?.is_admin"
      to="/admin"
      class="mx-2 mb-1 flex items-center gap-2 rounded-xl px-3 py-2 text-muted hover:bg-sunken hover:text-ink"
      @click="emit('navigate', null)"
    >
      <AppIcon name="shield" :size="19" />
      {{ t('admin.title') }}
    </router-link>
    <div class="flex items-center gap-1 border-t border-line px-2 py-2">
      <router-link to="/settings" class="flex min-w-0 flex-1 items-center gap-2 rounded-xl px-2 py-2 hover:bg-sunken" @click="emit('navigate', null)">
        <AppIcon name="settings" :size="19" />
        {{ t('actions.settings') }}
      </router-link>
      <button class="flex shrink-0 items-center gap-2 rounded-xl px-2 py-2 text-muted hover:bg-sunken hover:text-ink" @click="session.logout().then(() => $router.push('/login'))">
        <AppIcon name="logout" :size="19" />
        {{ t('actions.logout') }}
      </button>
    </div>
  </nav>
</template>
