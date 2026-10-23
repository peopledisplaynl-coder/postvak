<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import AppIcon from './AppIcon.vue'
import PdfView from './PdfView.vue'
import { attachmentUrl } from '../api/client.js'
import { fileSize } from '../format.js'
import { t } from '../i18n/index.js'

/**
 * Look at attachments without downloading them: images full screen (← → to browse), PDFs drawn inside the
 * viewer (pdf.js). On a computer a PDF can also open in a browser tab; an installed app on a phone would show
 * that tab without a way back, so it is not offered there.
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
const isPdf = computed(() => item.value?.mime === 'application/pdf')
// A browser tab only where there is a real browser around it (not the installed app, not a phone).
const canOpenTab = typeof matchMedia === 'function' && !matchMedia('(display-mode: standalone)').matches && matchMedia('(pointer: fine)').matches

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
  // In a PDF a sideways swipe moves the zoomed page, not to the next attachment.
  if (startX === null || isPdf.value) return
  const dx = e.changedTouches[0].clientX - startX
  if (Math.abs(dx) > 50) go(dx < 0 ? 1 : -1)
  startX = null
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex flex-col text-white" :class="isPdf ? 'bg-black' : 'bg-black/90'" role="dialog" :aria-label="item.name" @touchstart.passive="touchStart" @touchend="touchEnd">
    <header class="pt-safe relative z-10 flex items-center gap-2 bg-black/40 px-3 py-2">
      <div class="min-w-0 flex-1 px-1">
        <p class="truncate font-medium">{{ item.name }}</p>
        <p class="text-xs text-white/60">
          {{ fileSize(item.size) }}<template v-if="items.length > 1"> · {{ index + 1 }} / {{ items.length }}</template>
        </p>
      </div>
      <a v-if="isPdf && canOpenTab" :href="inlineUrl" target="_blank" rel="noopener" class="rounded-lg p-2 text-white/80 hover:bg-white/10 hover:text-white" :title="t('viewer.openPdf')">
        <AppIcon name="external" />
      </a>
      <a :href="downloadUrl" download class="rounded-lg p-2 text-white/80 hover:bg-white/10 hover:text-white" :title="t('viewer.download')">
        <AppIcon name="download" />
      </a>
      <button class="rounded-lg p-2 text-white/80 hover:bg-white/10 hover:text-white" :aria-label="t('viewer.close')" @click="emit('close')">
        <AppIcon name="close" />
      </button>
    </header>

    <div class="relative flex min-h-0 flex-1 justify-center" :class="isPdf ? 'items-stretch overflow-hidden' : 'items-center p-4'" @click.self="emit('close')">
      <template v-if="isImage">
        <div v-if="!loaded" class="absolute size-10 animate-spin rounded-full border-2 border-white/30 border-t-white" />
        <img :key="item.url" :src="inlineUrl" :alt="item.name" class="max-h-full max-w-full rounded object-contain shadow-2xl" @load="loaded = true" />
      </template>
      <PdfView v-else-if="isPdf" :key="item.url" :url="inlineUrl" />

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
