<script setup>
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import AppIcon from './AppIcon.vue'
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?worker&url'
import { t } from '../i18n/index.js'

/**
 * A PDF shown inside Postvak (pdf.js), so it opens in the viewer with a close button instead of in a separate
 * browser page: an installed app on a phone has no back or close button there. Pages are drawn to the width of the
 * screen; + and − zoom. pdf.js is loaded only when a PDF is opened.
 */
const props = defineProps({ url: { type: String, required: true } })

const box = ref(null)
const pages = ref(0)
const zoom = ref(1)
const state = ref('loading') // loading | ready | failed
let doc = null
let rendering = 0

async function open() {
  state.value = 'loading'
  pages.value = 0
  try {
    const pdfjs = await import('pdfjs-dist')
    pdfjs.GlobalWorkerOptions.workerSrc = workerUrl
    const res = await fetch(props.url, { credentials: 'same-origin' })
    if (!res.ok) throw new Error(String(res.status))
    const data = new Uint8Array(await res.arrayBuffer())
    doc?.destroy()
    doc = await pdfjs.getDocument({ data, isEvalSupported: false }).promise
    pages.value = doc.numPages
    state.value = 'ready'
    await nextTick()
    render()
  } catch (e) {
    console.error('[postvak] pdf', e)
    state.value = 'failed'
  }
}

async function render() {
  if (!doc || !box.value) return
  const run = ++rendering
  const width = box.value.clientWidth - 16
  const ratio = window.devicePixelRatio || 1
  for (let n = 1; n <= doc.numPages; n++) {
    if (run !== rendering) return // a newer zoom or size took over
    const page = await doc.getPage(n)
    const base = page.getViewport({ scale: 1 })
    const scale = (width / base.width) * zoom.value
    const viewport = page.getViewport({ scale: scale * ratio })
    const canvas = box.value?.querySelector(`canvas[data-page="${n}"]`)
    if (!canvas) return
    canvas.width = Math.floor(viewport.width)
    canvas.height = Math.floor(viewport.height)
    canvas.style.width = `${Math.floor(viewport.width / ratio)}px`
    canvas.style.height = `${Math.floor(viewport.height / ratio)}px`
    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise
  }
}

function setZoom(step) {
  zoom.value = Math.min(3, Math.max(1, Math.round((zoom.value + step) * 4) / 4))
}
watch(zoom, () => render())
watch(() => props.url, open)

let observer = null
let lastWidth = 0
onMounted(() => {
  open()
  if (typeof ResizeObserver !== 'undefined') {
    observer = new ResizeObserver(() => {
      const w = box.value?.clientWidth ?? 0
      if (Math.abs(w - lastWidth) > 20) {
        lastWidth = w
        render()
      }
    })
    observer.observe(box.value)
  }
})
onBeforeUnmount(() => {
  observer?.disconnect()
  rendering++
  doc?.destroy()
})
</script>

<template>
  <div class="relative flex min-h-0 w-full flex-1 flex-col">
    <div ref="box" class="min-h-0 flex-1 overflow-auto overscroll-contain" @click.stop>
      <div v-if="state === 'loading'" class="flex h-full items-center justify-center">
        <div class="size-10 animate-spin rounded-full border-2 border-white/30 border-t-white" :aria-label="t('viewer.loading')" />
      </div>
      <div v-else-if="state === 'failed'" class="flex h-full flex-col items-center justify-center gap-3 p-6 text-center text-white/80">
        <AppIcon name="paperclip" :size="40" class="text-white/60" />
        <p class="max-w-xs">{{ t('viewer.pdfFailed') }}</p>
      </div>
      <div v-else class="flex flex-col items-center gap-3 py-2" :style="{ width: zoom > 1 ? 'max-content' : 'auto', margin: '0 auto' }">
        <canvas v-for="n in pages" :key="n" :data-page="n" class="block bg-white shadow-xl" :aria-label="t('viewer.page', { n, total: pages })" />
      </div>
    </div>
    <div v-if="state === 'ready'" class="pointer-events-none absolute right-3 bottom-3 flex gap-2">
      <button class="pointer-events-auto rounded-full bg-black/60 p-3 hover:bg-black/80 disabled:opacity-40" :disabled="zoom <= 1" :aria-label="t('viewer.zoomOut')" @click.stop="setZoom(-0.5)">
        <span class="block size-5 text-center text-xl leading-5">−</span>
      </button>
      <button class="pointer-events-auto rounded-full bg-black/60 p-3 hover:bg-black/80 disabled:opacity-40" :disabled="zoom >= 3" :aria-label="t('viewer.zoomIn')" @click.stop="setZoom(0.5)">
        <span class="block size-5 text-center text-xl leading-5">+</span>
      </button>
    </div>
  </div>
</template>
