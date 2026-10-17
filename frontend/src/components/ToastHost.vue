<script setup>
import { useToasts } from '../stores/toasts.js'

const toasts = useToasts()

function run(toast) {
  toast.action?.run()
  toasts.dismiss(toast.id)
}
</script>

<template>
  <div class="pb-safe pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-center gap-2 p-4" aria-live="polite">
    <TransitionGroup
      enter-from-class="translate-y-3 opacity-0"
      leave-to-class="opacity-0"
      enter-active-class="transition duration-200"
      leave-active-class="transition duration-150"
    >
      <div
        v-for="toast in toasts.items"
        :key="toast.id"
        class="pointer-events-auto flex min-w-64 max-w-[calc(100vw-2rem)] items-center gap-4 rounded-xl px-4 py-3 text-sm shadow-lg"
        :class="toast.tone === 'error' ? 'bg-danger text-white' : 'bg-ink text-canvas'"
      >
        <span class="flex-1">{{ toast.text }}</span>
        <button
          v-if="toast.action"
          class="-my-1 rounded-lg px-2 py-1 font-semibold text-accent-soft hover:bg-white/10"
          @click="run(toast)"
        >{{ toast.action.label }}</button>
      </div>
    </TransitionGroup>
  </div>
</template>
