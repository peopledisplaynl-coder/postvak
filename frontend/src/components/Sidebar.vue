<script setup>
import { computed } from 'vue'
import AppIcon from './AppIcon.vue'
import { useSession } from '../stores/session.js'
import { useMail } from '../stores/mail.js'
import { t } from '../i18n/index.js'

const emit = defineEmits(['navigate', 'compose'])
const session = useSession()
const mail = useMail()

const roleIcon = { inbox: 'inbox', drafts: 'drafts', sent: 'sent', archive: 'archive', junk: 'junk', trash: 'trash' }

function folderLabel(f) {
  if (f.role) return t(`folders.${f.role}`)
  // Show nested folders without the INBOX prefix: "INBOX.Band.2024" -> "Band / 2024"
  const parts = f.full_name.split(f.delimiter)
  if (parts[0].toUpperCase() === 'INBOX') parts.shift()
  return parts.join(' / ')
}

const folders = computed(() => mail.currentFolders.filter((f) => f.selectable))

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

    <!-- Accounts -->
    <div v-if="session.accounts.length > 1" class="flex gap-1.5 overflow-x-auto px-3 pb-2">
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
    <p v-else class="truncate px-6 pb-2 text-sm text-muted">{{ session.accounts[0]?.email }}</p>

    <!-- Folders of the active account -->
    <ul class="min-h-0 flex-1 overflow-y-auto px-2">
      <li v-for="f in folders" :key="f.path">
        <button
          class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left transition"
          :class="f.path === mail.folderPath ? 'bg-accent-soft font-medium text-accent' : 'hover:bg-sunken'"
          @click="go(mail.accountId, f.path)"
        >
          <AppIcon :name="roleIcon[f.role] ?? 'folder'" :size="19" />
          <span class="min-w-0 flex-1 truncate">{{ folderLabel(f) }}</span>
          <span v-if="f.unseen && f.role !== 'trash' && f.role !== 'sent'" class="text-sm tabular-nums" :class="f.role === 'inbox' ? 'font-semibold' : 'text-muted'">
            {{ f.unseen }}
          </span>
        </button>
      </li>
    </ul>

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
