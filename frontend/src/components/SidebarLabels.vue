<script setup>
import { computed } from 'vue'
import { useSession } from '../stores/session.js'
import { useMail } from '../stores/mail.js'
import { labelName } from '../labels.js'
import { t } from '../i18n/index.js'

/** Labels in the sidebar: a click shows only the messages with that label in the current folder. */
const emit = defineEmits(['picked'])
const session = useSession()
const mail = useMail()
const labels = computed(() => session.settings.labels ?? [])

function pick(key) {
  mail.filterLabel(mail.labelFilter?.toLowerCase() === key.toLowerCase() ? null : key)
  emit('picked')
}
</script>

<template>
  <template v-if="labels.length">
    <li class="px-3 pt-4 pb-1 text-xs font-medium uppercase tracking-wide text-muted">{{ t('labels.title') }}</li>
    <li v-for="l in labels" :key="l.key">
      <button
        class="flex w-full items-center gap-3 rounded-xl px-3 py-1.5 text-left text-sm transition"
        :class="mail.labelFilter?.toLowerCase() === l.key.toLowerCase() ? 'bg-accent-soft font-medium text-accent' : 'hover:bg-sunken'"
        :aria-pressed="mail.labelFilter?.toLowerCase() === l.key.toLowerCase()"
        @click="pick(l.key)"
      >
        <span class="flex size-[19px] items-center justify-center"><span class="size-2.5 rounded-full" :style="{ background: l.color }" /></span>
        <span class="min-w-0 flex-1 truncate">{{ labelName(l) }}</span>
      </button>
    </li>
  </template>
</template>
