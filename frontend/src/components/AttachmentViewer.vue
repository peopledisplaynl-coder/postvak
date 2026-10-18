<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import AppIcon from './AppIcon.vue'
import { attachmentUrl } from '../api/client.js'
import { fileSize } from '../format.js'
import { t } from '../i18n/index.js'

/**
 * Look at attachments without downloading them: images full screen (← → to browse),
 * PDFs in a new tab (the browser's own viewer; framing is blocked by the CSP on purpose).
 */
const props = defineProps({
  items: { type: Array, required: true }, // attachments: { name, mime, size, url }
  start: { type: Number, default: 0 },
})
const emit = defineEmits(['close'])

const index = ref(props.start)
const item = computed(() => props.items[index.value])
const isImage = computed(() => item.value?.mime?.startsWith('image/'))
const inlineUrl = computed(() => attachmentUrl(item.value.url) + '&inline=1')
const downloadUrl = computed(() => attachmentUrl(item.value.url))
const loaded = ref(false)

function go(step) {
  if (props.items.length < 2) return
  index.value = (index.value + step + props.items.length) % props.items.length
  loaded.value = false
}

function onKey(e) {
  if (e.key === 'Escape') emit('close')
  else if (e.key === 'ArrowRight') go(1)
  else if (e.key === 'ArrowLeft') go(-1)
  else return
  e.preventDefault()
  e.stopPropagation()
}
onMounted(() => window.addEventListener('keydown', onKey, true))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey, true))

// Swipe left/right on touch screens.
let startX = null
const touchStart = (e) => (startX = e.touches[0].clientX)
function touchEnd(e) {
  if (startX === null) return
  const dx = e.changedTouches[0].clientX - startX
  if (Math.abs(dx) > 50) go(dx < 0 ? 1 : -1)
  startX = null
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex flex-col bg-black/90 text-white" role="dialog" :aria-label="item.name" @touchstart.passive="touchStart" @touchend="touchEnd">
    <header class="pt-safe flex items-center gap-2 px-3 py-2">
      <div class="min-w-0 flex-1 px-1">
        <p class="truncate font-medium">{{ item.name }}</p>
        <p class="text-xs text-white/60">
          {{ fileSize(item.size) }}<template v-if="items.length > 1"> · {{ index + 1 }} / {{ items.length }}</template>
        </p>
      </div>
      <a :href="downloadUrl" download class="rounded-lg p-2 text-white/80 hover:bg-white/10 hover:text-white" :title="t('viewer.download')">
        <AppIcon name="download" />
      </a>
      <button class="rounded-lg p-2 text-white/80 hover:bg-white/10 hover:text-white" :aria-label="t('compose.close')" @click="emit('close')">
        <AppIcon name="close" />
      </button>
    </header>

    <div class="relative flex min-h-0 flex-1 items-center justify-center p-4" @click.self="emit('close')">
      <template v-if="isImage">
        <div v-if="!loaded" class="absolute size-10 animate-spin rounded-full border-2 border-white/30 border-t-white" />
        <img :key="item.url" :src="inlineUrl" :alt="item.name" class="max-h-full max-w-full rounded object-contain shadow-2xl" @load="loaded = true" />
      </template>
      <div v-else class="flex flex-col items-center gap-4 text-center">
        <AppIcon name="paperclip" :size="48" class="text-white/60" />
        <p class="max-w-xs text-white/80">{{ t('viewer.pdfHint') }}</p>
        <div class="flex gap-2">
          <a :href="inlineUrl" target="_blank" rel="noopener" class="rounded-lg bg-white px-4 py-2 font-medium text-black">{{ t('viewer.openPdf') }}</a>
          <a :href="downloadUrl" download class="rounded-lg px-4 py-2 font-medium ring-1 ring-white/40 hover:bg-white/10">{{ t('viewer.download') }}</a>
        </div>
      </div>

      <template v-if="items.length > 1">
        <button class="absolute top-1/2 left-2 -translate-y-1/2 rounded-full bg-black/40 p-3 hover:bg-black/60" :aria-label="t('viewer.previous')" @click="go(-1)">
          <AppIcon name="back" />
        </button>
        <button class="absolute top-1/2 right-2 -translate-y-1/2 rounded-full bg-black/40 p-3 hover:bg-black/60" :aria-label="t('viewer.next')" @click="go(1)">
          <AppIcon name="chevron" />
        </button>
      </template>
    </div>
  </div>
</template>
