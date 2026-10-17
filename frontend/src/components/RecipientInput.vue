<script setup>
import { ref, watch } from 'vue'
import { api } from '../api/client.js'

// List of {email, name} as chips, with address autocomplete from /contacts.
const model = defineModel({ type: Array, default: () => [] })
const props = defineProps({ label: String, autofocus: Boolean })

const text = ref('')
const suggestions = ref([])
const highlighted = ref(0)
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

let timer
watch(text, (q) => {
  clearTimeout(timer)
  if (q.trim().length < 2) {
    suggestions.value = []
    return
  }
  timer = setTimeout(async () => {
    try {
      const known = new Set(model.value.map((r) => r.email))
      suggestions.value = (await api.get('/contacts', { q: q.trim() })).filter((c) => !known.has(c.email))
      highlighted.value = 0
    } catch {
      suggestions.value = []
    }
  }, 200)
})

function add(entry) {
  if (!model.value.some((r) => r.email === entry.email)) model.value = [...model.value, entry]
  text.value = ''
  suggestions.value = []
}

/** Turn typed text into chips; accepts "a@b.nl, c@d.nl" and "Name <a@b.nl>". */
function commitText() {
  const parts = text.value.split(/[,;\s]+(?=[^>]*(?:<|$))/).map((s) => s.trim()).filter(Boolean)
  const left = []
  for (const p of parts) {
    const m = p.match(/^(.*)<([^>]+)>$/)
    const email = (m ? m[2] : p).trim()
    if (EMAIL.test(email)) add({ email, name: m ? m[1].trim().replace(/^"|"$/g, '') : '' })
    else left.push(p)
  }
  text.value = left.join(' ')
}

function onKeydown(e) {
  if (suggestions.value.length && ['ArrowDown', 'ArrowUp'].includes(e.key)) {
    e.preventDefault()
    const n = suggestions.value.length
    highlighted.value = (highlighted.value + (e.key === 'ArrowDown' ? 1 : n - 1)) % n
  } else if (e.key === 'Enter' || e.key === 'Tab' || e.key === ',') {
    if (suggestions.value.length && e.key !== ',') {
      e.preventDefault()
      add(suggestions.value[highlighted.value])
    } else if (text.value.trim()) {
      e.preventDefault()
      commitText()
    }
  } else if (e.key === 'Backspace' && !text.value && model.value.length) {
    model.value = model.value.slice(0, -1)
  }
}

function remove(email) {
  model.value = model.value.filter((r) => r.email !== email)
}

defineExpose({ commitText })
</script>

<template>
  <div class="relative flex min-h-11 flex-wrap items-center gap-1.5 border-b border-line py-1.5 pr-20 pl-4">
    <span class="mr-1 text-sm text-muted">{{ label }}</span>
    <span
      v-for="r in model"
      :key="r.email"
      class="flex items-center gap-1 rounded-full bg-sunken py-0.5 pr-1 pl-2.5 text-sm"
      :title="r.email"
    >
      {{ r.name || r.email }}
      <button type="button" class="rounded-full px-1 text-muted hover:text-ink" :aria-label="`${r.email} verwijderen`" @click="remove(r.email)">×</button>
    </span>
    <input
      v-model="text"
      type="email"
      multiple
      class="min-w-32 flex-1 bg-transparent py-1 outline-none focus-visible:outline-none"
      :autofocus="autofocus"
      autocomplete="off"
      @keydown="onKeydown"
      @blur="commitText"
    />
    <ul v-if="suggestions.length" class="absolute top-full left-12 z-10 mt-1 w-72 overflow-hidden rounded-xl bg-surface py-1 shadow-lg ring-1 ring-line">
      <li v-for="(s, i) in suggestions" :key="s.email">
        <button
          type="button"
          class="block w-full px-3 py-2 text-left text-sm"
          :class="i === highlighted ? 'bg-accent-soft' : 'hover:bg-sunken'"
          @mousedown.prevent="add(s)"
        >
          <span class="block truncate font-medium">{{ s.name || s.email }}</span>
          <span v-if="s.name" class="block truncate text-muted">{{ s.email }}</span>
        </button>
      </li>
    </ul>
  </div>
</template>
