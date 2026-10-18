<script setup>
import { ref, computed, onMounted } from 'vue'
import AppIcon from './AppIcon.vue'
import { useMail } from '../stores/mail.js'
import { useToasts } from '../stores/toasts.js'
import { folderLabel, folderIcon } from '../folders.js'
import { t } from '../i18n/index.js'

/** Choose a folder to move messages to. Emits pick({ path, label }). */
const props = defineProps({
  accountId: { type: [Number, String], required: true },
  exclude: { type: String, default: '' },
  count: { type: Number, default: 1 },
})
const emit = defineEmits(['pick', 'close'])

const mail = useMail()
const toasts = useToasts()
const filter = ref('')
const creating = ref(false)
const newName = ref('')
const busy = ref(false)
const input = ref(null)

const folders = computed(() =>
  (mail.folders[props.accountId] ?? [])
    .filter((f) => f.selectable && f.path !== props.exclude && f.role !== 'drafts')
    .filter((f) => !filter.value || folderLabel(f).toLowerCase().includes(filter.value.toLowerCase()))
)

onMounted(() => input.value?.focus())

function pick(f) {
  emit('pick', { path: f.path, label: folderLabel(f) })
}

async function create() {
  const name = newName.value.trim()
  if (!name) return
  busy.value = true
  try {
    const path = await mail.createFolder(name, null, props.accountId)
    emit('pick', { path, label: name })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-40 flex items-end justify-center bg-ink/40 sm:items-center sm:p-4" @click.self="emit('close')" @keydown.esc="emit('close')">
    <div class="pb-safe flex max-h-[80vh] w-full flex-col rounded-t-2xl bg-surface shadow-2xl ring-1 ring-line sm:max-w-sm sm:rounded-2xl" role="dialog" :aria-label="t('folderPicker.title')">
      <header class="flex items-center gap-2 border-b border-line px-4 py-3">
        <h2 class="flex-1 font-semibold">{{ t('folderPicker.title', { n: count }) }}</h2>
        <button class="rounded-lg p-1.5 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('settings.cancel')" @click="emit('close')">
          <AppIcon name="close" />
        </button>
      </header>
      <div class="border-b border-line px-4 py-2">
        <input
          ref="input"
          v-model="filter"
          type="search"
          class="w-full rounded-lg bg-sunken px-3 py-2 outline-none placeholder:text-muted"
          :placeholder="t('folderPicker.search')"
        />
      </div>
      <ul class="min-h-0 flex-1 overflow-y-auto py-1">
        <li v-for="f in folders" :key="f.path">
          <button class="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-sunken" @click="pick(f)">
            <AppIcon :name="folderIcon(f)" :size="19" class="text-muted" />
            <span class="truncate">{{ folderLabel(f) }}</span>
          </button>
        </li>
        <li v-if="!folders.length" class="px-4 py-3 text-sm text-muted">{{ t('folderPicker.none') }}</li>
      </ul>
      <footer class="border-t border-line px-4 py-3">
        <form v-if="creating" class="flex gap-2" @submit.prevent="create">
          <input
            v-model="newName"
            class="min-w-0 flex-1 rounded-lg border border-line bg-surface px-3 py-2 outline-none focus:border-accent"
            :placeholder="t('folderPicker.newName')"
            autofocus
          />
          <button class="rounded-lg bg-accent px-3 py-2 text-sm font-medium text-white disabled:opacity-60" :disabled="busy || !newName.trim()">
            {{ t('folderPicker.createAndMove') }}
          </button>
        </form>
        <button v-else class="flex items-center gap-2 text-sm font-medium text-accent" @click="creating = true">
          <AppIcon name="plus" :size="18" /> {{ t('folderPicker.newFolder') }}
        </button>
      </footer>
    </div>
  </div>
</template>
