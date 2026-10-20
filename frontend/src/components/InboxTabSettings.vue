<script setup>
import { ref, computed, onMounted } from 'vue'
import AppIcon from './AppIcon.vue'
import { api } from '../api/client.js'
import { useSession } from '../stores/session.js'
import { useMail, INBOX_TABS } from '../stores/mail.js'
import { useToasts } from '../stores/toasts.js'
import { t } from '../i18n/index.js'

/**
 * Settings → General, under "Inbox tabs": which tabs are on (Primary always is), and the senders that were moved
 * to another tab by hand, with a way to undo that.
 */
const session = useSession()
const mail = useMail()
const toasts = useToasts()

const on = computed(() => session.settings.inbox_tab_list ?? INBOX_TABS)
async function toggle(key, checked) {
  const next = INBOX_TABS.filter((k) => (k === key ? checked : on.value.includes(k)))
  try {
    await session.updateSettings({ inbox_tab_list: next })
    if (mail.accountId !== null) mail.loadMessages({ silent: true, restart: true })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

const senders = ref([])
const showAll = ref(false)
onMounted(async () => {
  try {
    senders.value = await api.get('/inbox-tab-senders')
  } catch {
    senders.value = []
  }
})
const shown = computed(() => (showAll.value ? senders.value : senders.value.slice(0, 5)))
const accountEmail = (id) => session.accounts.find((a) => a.id === id)?.email ?? ''

async function remove(s) {
  try {
    await api.del(`/accounts/${s.account_id}/inbox-tab-senders`, { email: s.sender })
    senders.value = senders.value.filter((x) => x !== s)
    if (mail.accountId !== null) mail.loadMessages({ silent: true, restart: true })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}
</script>

<template>
  <div class="space-y-3 px-4 py-3">
    <fieldset>
      <legend class="mb-2 text-sm text-muted">{{ t('tabs.which') }}</legend>
      <div class="flex flex-wrap gap-2">
        <label class="flex items-center gap-2 rounded-xl bg-sunken px-3 py-2 text-sm text-muted">
          <input type="checkbox" class="size-4 accent-accent" checked disabled />{{ t('tabs.primary') }}
        </label>
        <label v-for="key in INBOX_TABS" :key="key" class="flex cursor-pointer items-center gap-2 rounded-xl bg-sunken px-3 py-2 text-sm">
          <input type="checkbox" class="size-4 accent-accent" :checked="on.includes(key)" @change="toggle(key, $event.target.checked)" />{{ t(`tabs.${key}`) }}
        </label>
      </div>
      <p v-if="!on.length" class="mt-2 text-sm text-muted">{{ t('tabs.noneOn') }}</p>
    </fieldset>

    <div v-if="senders.length">
      <p class="mb-1 text-sm text-muted">{{ t('tabs.movedTitle') }}</p>
      <ul class="divide-y divide-line rounded-xl ring-1 ring-line">
        <li v-for="s in shown" :key="s.account_id + s.sender" class="flex items-center gap-3 px-3 py-2 text-sm">
          <span class="min-w-0 flex-1">
            <span class="block truncate">{{ s.sender }}</span>
            <span v-if="session.accounts.length > 1" class="block truncate text-xs text-muted">{{ accountEmail(s.account_id) }}</span>
          </span>
          <span class="shrink-0 rounded-full bg-accent-soft px-2 py-0.5 text-xs text-accent">{{ t(`tabs.${s.category}`) }}</span>
          <button class="shrink-0 rounded-lg p-1.5 text-muted hover:bg-sunken hover:text-ink" :title="t('tabs.reset')" :aria-label="t('tabs.resetFor', { email: s.sender })" @click="remove(s)">
            <AppIcon name="close" :size="16" />
          </button>
        </li>
      </ul>
      <button v-if="senders.length > 5 && !showAll" class="mt-1 text-sm text-accent" @click="showAll = true">{{ t('tabs.showAll', { n: senders.length }) }}</button>
    </div>
    <p v-else class="text-sm text-muted">{{ t('tabs.moveHint') }}</p>
  </div>
</template>
