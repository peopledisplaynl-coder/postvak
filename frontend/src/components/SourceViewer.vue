<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import AppIcon from './AppIcon.vue'
import { t } from '../i18n/index.js'

/**
 * The original message (all headers and the raw text) in a window inside Postvak, with close and download.
 * A separate browser page would have no way back in the installed app on a phone.
 */
const props = defineProps({
  url: { type: String, required: true },
  download: { type: String, required: true },
})
const emit = defineEmits(['close'])

const text = ref('')
const state = ref('loading')
onMounted(async () => {
  window.addEventListener('keydown', onKey, true)
  try {
    // Demo mode has no server: it shows a sample original.
    const url = import.meta.env.VITE_MOCK === 'true' ? '/demo/voorbeeld.eml' : props.url
    const res = await fetch(url, { credentials: 'same-origin' })
    if (!res.ok) throw new Error(String(res.status))
    text.value = await res.text()
    state.value = 'ready'
  } catch {
    state.value = 'failed'
  }
})
onBeforeUnmount(() => window.removeEventListener('keydown', onKey, true))
function onKey(e) {
  if (e.key !== 'Escape') return
  e.stopPropagation()
  emit('close')
}

const copied = ref(false)
async function copy() {
  try {
    await navigator.clipboard.writeText(text.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    // clipboard refused: the text can still be selected by hand
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex flex-col bg-surface" role="dialog" :aria-label="t('source.show')">
      <header class="pt-safe flex items-center gap-1 border-b border-line px-2 py-2">
        <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('viewer.close')" @click="emit('close')">
          <AppIcon name="close" />
        </button>
        <h2 class="min-w-0 flex-1 truncate px-1 font-semibold">{{ t('source.show') }}</h2>
        <button v-if="state === 'ready'" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="copied ? t('source.copied') : t('source.copy')" @click="copy">
          <AppIcon :name="copied ? 'check' : 'copy'" />
        </button>
        <a :href="download" download class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('source.download')">
          <AppIcon name="download" />
        </a>
      </header>
      <div class="pb-safe min-h-0 flex-1 overflow-auto">
        <p v-if="state === 'loading'" class="p-4 text-muted">{{ t('viewer.loading') }}</p>
        <p v-else-if="state === 'failed'" class="p-4 text-muted">{{ t('source.failed') }}</p>
        <pre v-else class="p-4 font-mono text-xs leading-relaxed break-words whitespace-pre-wrap select-text">{{ text }}</pre>
      </div>
    </div>
  </Teleport>
</template>
