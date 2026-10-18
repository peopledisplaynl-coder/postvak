<script setup>
import { onMounted, onBeforeUnmount } from 'vue'
import AppIcon from './AppIcon.vue'
import { t } from '../i18n/index.js'

const emit = defineEmits(['close'])

const keys = [
  ['j', 'next'], ['k', 'previous'], ['Esc', 'close'],
  ['r', 'reply'], ['a', 'replyAll'], ['f', 'forward'], ['c', 'compose'],
  ['e', 'archive'], ['#', 'trash'], ['!', 'junk'], ['b', 'snooze'], ['x', 'select'],
  ['/', 'search'], ['Ctrl + Enter', 'send'], ['?', 'help'],
]

function onKey(e) {
  if (e.key === 'Escape' || e.key === '?') {
    e.preventDefault()
    e.stopPropagation()
    emit('close')
  }
}
onMounted(() => window.addEventListener('keydown', onKey, true))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey, true))
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 p-4" @click.self="emit('close')">
    <div class="w-full max-w-md rounded-2xl bg-surface shadow-2xl ring-1 ring-line" role="dialog" :aria-label="t('shortcuts.title')">
      <header class="flex items-center gap-2 border-b border-line px-4 py-3">
        <AppIcon name="keyboard" :size="19" class="text-muted" />
        <h2 class="flex-1 font-semibold">{{ t('shortcuts.title') }}</h2>
        <button class="rounded-lg p-1.5 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('compose.close')" @click="emit('close')">
          <AppIcon name="close" />
        </button>
      </header>
      <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 px-4 py-4 text-sm">
        <template v-for="[key, label] in keys" :key="key">
          <dt class="text-right"><kbd class="rounded-md bg-sunken px-2 py-0.5 font-mono text-xs ring-1 ring-line">{{ key }}</kbd></dt>
          <dd>{{ t(`shortcuts.${label}`) }}</dd>
        </template>
      </dl>
    </div>
  </div>
</template>
