<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { api } from '../api/client.js'
import { useSession } from '../stores/session.js'
import { useMail, ALL } from '../stores/mail.js'
import { fileSize } from '../format.js'
import { t } from '../i18n/index.js'

/**
 * How full the mailbox is (IMAP QUOTA), like Gmail's "3 GB of 15 GB used". In "All inboxes" the fullest account.
 * Nothing is shown when the mail server does not tell.
 */
const session = useSession()
const mail = useMail()
const quotas = ref({}) // accountId -> {used, limit} | null

const ids = computed(() => (mail.accountId === ALL ? session.accounts.map((a) => a.id) : mail.accountId ? [mail.accountId] : []))
async function load() {
  for (const id of ids.value) {
    try {
      quotas.value = { ...quotas.value, [id]: await api.get(`/accounts/${id}/quota`) }
    } catch {
      quotas.value = { ...quotas.value, [id]: null }
    }
  }
}
watch(ids, load, { immediate: true })
const timer = setInterval(load, 15 * 60 * 1000)
onBeforeUnmount(() => clearInterval(timer))

const shown = computed(() => {
  const list = ids.value.map((id) => ({ id, q: quotas.value[id] })).filter((x) => x.q?.limit)
  if (!list.length) return null
  const top = list.reduce((a, b) => (b.q.used / b.q.limit > a.q.used / a.q.limit ? b : a))
  const pct = Math.min(100, Math.round((top.q.used / top.q.limit) * 100))
  return { ...top.q, pct, email: list.length > 1 ? session.accountById(top.id)?.email : null }
})
</script>

<template>
  <div v-if="shown" class="mx-4 mb-2" :title="shown.email ?? ''">
    <div class="h-1.5 overflow-hidden rounded-full bg-sunken" role="progressbar" :aria-valuenow="shown.pct" aria-valuemin="0" aria-valuemax="100" :aria-label="t('quota.label')">
      <div class="h-full rounded-full" :class="shown.pct >= 90 ? 'bg-danger' : shown.pct >= 75 ? 'bg-flag' : 'bg-accent'" :style="{ width: Math.max(2, shown.pct) + '%' }" />
    </div>
    <p class="mt-1 text-xs" :class="shown.pct >= 90 ? 'font-medium text-danger' : 'text-muted'">
      {{ shown.pct >= 90 ? t('quota.almostFull', { pct: shown.pct }) : t('quota.used', { used: fileSize(shown.used), limit: fileSize(shown.limit) }) }}
    </p>
  </div>
</template>
