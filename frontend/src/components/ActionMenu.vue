<script setup>
import { ref, nextTick, onMounted, onBeforeUnmount, computed } from 'vue'
import AppIcon from './AppIcon.vue'
import { t } from '../i18n/index.js'

/**
 * A pop-up menu at a screen position (right-click menu, or under a ⋮ button). Items come from
 * messageActions(): { id, icon, label, run, danger?, checked?, color?, keepOpen?, children? }; null = divider.
 * `align: 'right'` puts the right edge of the menu at x (for a button at the right of the screen).
 */
const props = defineProps({
  items: { type: Array, required: true },
  x: { type: Number, required: true },
  y: { type: Number, required: true },
  align: { type: String, default: 'left' },
})
const emit = defineEmits(['close'])

const sub = ref(null) // item whose children are shown
const shown = computed(() => (sub.value ? sub.value.children : props.items))
const menu = ref(null)
const pos = ref({ left: props.x, top: props.y })

async function place() {
  await nextTick()
  const el = menu.value
  if (!el) return
  const { width, height } = el.getBoundingClientRect()
  const margin = 8
  let left = props.align === 'right' ? props.x - width : props.x
  let top = props.y
  left = Math.max(margin, Math.min(left, window.innerWidth - width - margin))
  if (top + height > window.innerHeight - margin) top = Math.max(margin, window.innerHeight - height - margin)
  pos.value = { left, top }
}
onMounted(() => {
  place()
  el()?.focus()
  window.addEventListener('resize', close)
  // Capture: Esc and the arrows belong to the menu, not to the shortcuts of the page behind it.
  window.addEventListener('keydown', onKey, true)
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', close)
  window.removeEventListener('keydown', onKey, true)
})
const el = () => menu.value?.querySelector('[role^=menuitem]')

function close() {
  emit('close')
}
function choose(item) {
  if (item.children) {
    sub.value = item
    place()
    nextTick(() => el()?.focus())
    return
  }
  item.run?.()
  if (!item.keepOpen) close()
}
function onKey(e) {
  // Enter, space and Tab work on the focused item as usual; other keys do not reach the page shortcuts.
  if (!['Enter', ' ', 'Tab'].includes(e.key)) e.stopPropagation()
  if (e.key === 'Escape') {
    if (sub.value) {
      sub.value = null
      place()
    } else close()
    return
  }
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault()
    const all = [...(menu.value?.querySelectorAll('[role^=menuitem]') ?? [])]
    const i = all.indexOf(document.activeElement)
    const next = all[(i + (e.key === 'ArrowDown' ? 1 : -1) + all.length) % all.length]
    next?.focus()
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[70]" @click="close" @contextmenu.prevent="close" />
    <div
      ref="menu"
      class="fixed z-[71] max-h-[calc(100vh-16px)] w-72 overflow-y-auto rounded-xl bg-surface py-1 text-sm text-ink shadow-xl ring-1 ring-line"
      :style="{ left: pos.left + 'px', top: pos.top + 'px' }"
      role="menu"
      @contextmenu.prevent
    >
      <button
        v-if="sub"
        role="menuitem"
        class="flex w-full items-center gap-3 border-b border-line px-3 py-2 text-left text-muted outline-none hover:bg-sunken focus-visible:bg-sunken"
        @click="sub = null; place()"
      >
        <AppIcon name="back" :size="18" />{{ sub.label }}
      </button>
      <template v-for="(item, i) in shown" :key="item ? item.id : 'd' + i">
        <div v-if="!item" class="my-1 border-t border-line" role="separator" />
        <button
          v-else
          :role="item.checked !== undefined ? 'menuitemcheckbox' : 'menuitem'"
          :aria-checked="item.checked !== undefined ? item.checked : undefined"
          class="flex w-full items-center gap-3 px-3 py-2 text-left outline-none hover:bg-sunken focus-visible:bg-sunken"
          :class="item.danger ? 'text-danger' : ''"
          @click.stop="choose(item)"
        >
          <span v-if="item.checked !== undefined" class="flex size-4 shrink-0 items-center justify-center rounded border" :style="{ borderColor: item.color, background: item.checked ? item.color : 'transparent' }">
            <AppIcon v-if="item.checked" name="check" :size="12" class="text-white" />
          </span>
          <AppIcon v-else :name="item.icon" :size="18" :class="item.danger ? '' : 'text-muted'" />
          <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
          <AppIcon v-if="item.children" name="chevronRight" :size="16" class="text-muted" />
        </button>
      </template>
      <span class="sr-only">{{ t('menu.escToClose') }}</span>
    </div>
  </Teleport>
</template>
