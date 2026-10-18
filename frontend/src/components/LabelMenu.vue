<script setup>
import { ref, computed } from 'vue'
import AppIcon from './AppIcon.vue'
import { useSession } from '../stores/session.js'
import { useMail } from '../stores/mail.js'
import { labelName } from '../labels.js'
import { t } from '../i18n/index.js'

/**
 * Label button with a menu of the user's labels. `messages` are list rows (a conversation row labels every
 * message in it); `extra` is the message object of the reader, kept in step.
 */
const props = defineProps({
  messages: { type: Array, required: true },
  extra: { type: Array, default: () => [] },
  buttonClass: { type: String, default: 'rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink' },
})
const session = useSession()
const mail = useMail()
const open = ref(false)
const labels = computed(() => session.settings.labels ?? [])

const has = (m, key) => (m.labels ?? []).some((k) => k.toLowerCase() === key.toLowerCase())
const targets = computed(() => (props.messages.length ? props.messages : props.extra))
const state = (key) => {
  const n = targets.value.filter((m) => has(m, key)).length
  return n === 0 ? 'none' : n === targets.value.length ? 'all' : 'some'
}

function toggle(key) {
  const on = state(key) !== 'all'
  mail.setLabel(props.messages, key, on, props.extra)
}
</script>

<template>
  <div class="relative">
    <button :class="buttonClass" :title="t('labels.title')" :aria-expanded="open" @click.stop="open = !open">
      <AppIcon name="tag" />
    </button>
    <div v-if="open" class="fixed inset-0 z-30" @click.stop="open = false" />
    <div v-if="open" class="absolute right-0 z-40 mt-1 w-56 overflow-hidden rounded-xl bg-surface py-1 text-sm text-ink shadow-lg ring-1 ring-line" role="menu">
      <button
        v-for="l in labels" :key="l.key" role="menuitemcheckbox" :aria-checked="state(l.key) === 'all'"
        class="flex w-full items-center gap-2.5 px-3 py-2 text-left hover:bg-sunken"
        @click.stop="toggle(l.key)"
      >
        <span class="flex size-4 shrink-0 items-center justify-center rounded border" :style="{ borderColor: l.color, background: state(l.key) === 'all' ? l.color : 'transparent' }">
          <AppIcon v-if="state(l.key) === 'all'" name="check" :size="12" class="text-white" />
          <span v-else-if="state(l.key) === 'some'" class="h-0.5 w-2 rounded" :style="{ background: l.color }" />
        </span>
        <span class="min-w-0 flex-1 truncate">{{ labelName(l) }}</span>
      </button>
      <router-link to="/settings/appearance" class="block border-t border-line px-3 py-2 text-muted hover:bg-sunken hover:text-ink" @click="open = false">{{ t('labels.manage') }}</router-link>
    </div>
  </div>
</template>
