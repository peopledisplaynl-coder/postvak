<script setup>
import { t } from '../i18n/index.js'

/**
 * Drag handle on the right edge of a column. Emits the new width in pixels while dragging;
 * double-click resets. Arrow keys work too (the handle is focusable).
 */
const props = defineProps({
  width: { type: Number, required: true },
  min: { type: Number, default: 180 },
  max: { type: Number, default: 800 },
  label: { type: String, default: '' },
})
const emit = defineEmits(['update:width', 'reset'])

const clamp = (w) => Math.round(Math.min(props.max, Math.max(props.min, w)))

function start(e) {
  if (e.button !== 0) return
  e.preventDefault()
  const startX = e.clientX
  const startWidth = props.width
  const handle = e.currentTarget
  handle.setPointerCapture(e.pointerId)
  document.body.style.cursor = 'col-resize'
  document.body.style.userSelect = 'none'
  const move = (ev) => emit('update:width', clamp(startWidth + ev.clientX - startX))
  const stop = () => {
    handle.removeEventListener('pointermove', move)
    handle.removeEventListener('pointerup', stop)
    handle.removeEventListener('pointercancel', stop)
    document.body.style.cursor = ''
    document.body.style.userSelect = ''
  }
  handle.addEventListener('pointermove', move)
  handle.addEventListener('pointerup', stop)
  handle.addEventListener('pointercancel', stop)
}

function onKey(e) {
  const step = e.shiftKey ? 40 : 16
  if (e.key === 'ArrowLeft') emit('update:width', clamp(props.width - step))
  else if (e.key === 'ArrowRight') emit('update:width', clamp(props.width + step))
  else return
  e.preventDefault()
}
</script>

<template>
  <div
    class="group absolute inset-y-0 -right-1.5 z-10 w-3 cursor-col-resize touch-none outline-none"
    role="separator"
    aria-orientation="vertical"
    :aria-label="label || t('layout.resize')"
    :aria-valuenow="width"
    :aria-valuemin="min"
    :aria-valuemax="max"
    :title="t('layout.resizeHint')"
    tabindex="0"
    @pointerdown="start"
    @dblclick="emit('reset')"
    @keydown="onKey"
  >
    <div class="mx-auto h-full w-0.5 bg-transparent transition-colors group-hover:bg-accent/60 group-focus-visible:bg-accent group-active:bg-accent" />
  </div>
</template>
