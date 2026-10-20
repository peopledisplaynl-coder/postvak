<script setup>
import { ref, computed, watch } from 'vue'
import AppIcon from './AppIcon.vue'
import { useSession } from '../stores/session.js'
import { useToasts } from '../stores/toasts.js'
import { labelName, newLabelKey } from '../labels.js'
import { t } from '../i18n/index.js'

/** Settings → Appearance: light/dark, accent colour, text size, list density, and the labels. */
const session = useSession()
const toasts = useToasts()

async function set(key, value) {
  try {
    await session.updateSettings({ [key]: value })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

const ACCENTS = { indigo: '#3646D9', blue: '#1D6FD8', teal: '#0E7C86', green: '#1E8A4C', orange: '#C2570C', rose: '#C7365F', purple: '#7C3AED', graphite: '#374151' }

// ---- labels (edited as a list, saved as a whole)
const labels = ref([])
watch(() => session.settings.labels, (l) => (labels.value = (l ?? []).map((x) => ({ ...x }))), { immediate: true, deep: true })
const LABEL_COLORS = ['#D23F3F', '#E07B1A', '#C98A06', '#0F8A5F', '#0E7490', '#2C7DA0', '#3646D9', '#7A5AC8', '#DB2777', '#677385']
const newName = ref('')

async function saveLabels(next) {
  try {
    await session.updateSettings({ labels: next })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

function rename(label, name) {
  saveLabels(labels.value.map((l) => (l.key === label.key ? { ...l, name: name.trim() } : l)))
}

function recolor(label, color) {
  saveLabels(labels.value.map((l) => (l.key === label.key ? { ...l, color } : l)))
}

function addLabel() {
  const name = newName.value.trim()
  if (!name) return
  const color = LABEL_COLORS[labels.value.length % LABEL_COLORS.length]
  saveLabels([...labels.value, { key: newLabelKey(name, labels.value), name, color }])
  newName.value = ''
}

function removeLabel(label) {
  if (!confirm(t('labels.confirmDelete', { name: labelName(label) }))) return
  saveLabels(labels.value.filter((l) => l.key !== label.key))
}

const field = 'rounded-lg bg-sunken px-3 py-1.5'

const compact = computed(() => session.settings.density === 'compact')
// The mail text follows the text size too (see MessageBody: 15px × the same factor).
const mailSize = computed(() => `${15 * ({ small: 0.92, normal: 1, large: 1.12, xlarge: 1.25 }[session.settings.text_size] ?? 1)}px`)
const previewRows = computed(() => [
  { from: t('appearance.sample1From'), subject: t('appearance.sample1Subject'), snippet: t('appearance.sample1Snippet'), time: '10:42', unread: true, initials: 'MV', color: '#B4478F' },
  { from: t('appearance.sample2From'), subject: t('appearance.sample2Subject'), snippet: t('appearance.sample2Snippet'), time: '09:15', unread: false, initials: 'PJ', color: '#3646D9' },
])
const canAdd = computed(() => labels.value.length < 20)
</script>

<template>
  <div class="space-y-8">
    <section class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
      <label class="flex items-center justify-between gap-4 px-4 py-3">
        <span>{{ t('appearance.mode') }}</span>
        <select :value="session.settings.theme" :class="field" @change="set('theme', $event.target.value)">
          <option value="system">{{ t('settings.themeSystem') }}</option>
          <option value="light">{{ t('settings.themeLight') }}</option>
          <option value="dark">{{ t('settings.themeDark') }}</option>
        </select>
      </label>

      <div class="px-4 py-3">
        <p class="mb-2">{{ t('appearance.accent') }}</p>
        <div class="flex flex-wrap gap-2" role="radiogroup" :aria-label="t('appearance.accent')">
          <button
            v-for="(color, name) in ACCENTS" :key="name"
            role="radio" :aria-checked="(session.settings.accent ?? 'indigo') === name" :title="t(`appearance.accents.${name}`)"
            class="flex size-9 items-center justify-center rounded-full ring-offset-2 ring-offset-surface transition"
            :class="(session.settings.accent ?? 'indigo') === name ? 'ring-2 ring-ink' : 'hover:scale-110'"
            :style="{ background: color }"
            @click="set('accent', name)"
          >
            <AppIcon v-if="(session.settings.accent ?? 'indigo') === name" name="check" :size="16" class="text-white" />
          </button>
        </div>
      </div>

      <label class="flex items-center justify-between gap-4 px-4 py-3">
        <span>{{ t('appearance.textSize') }}</span>
        <select :value="session.settings.text_size ?? 'normal'" :class="field" @change="set('text_size', $event.target.value)">
          <option value="small">{{ t('appearance.small') }}</option>
          <option value="normal">{{ t('appearance.normal') }}</option>
          <option value="large">{{ t('appearance.large') }}</option>
          <option value="xlarge">{{ t('appearance.xlarge') }}</option>
        </select>
      </label>

      <label class="flex items-center justify-between gap-4 px-4 py-3">
        <span>
          {{ t('settings.density') }}
          <span class="block text-sm text-muted">{{ t('appearance.densityHint') }}</span>
        </span>
        <select :value="session.settings.density" :class="field" @change="set('density', $event.target.value)">
          <option value="comfortable">{{ t('settings.comfortable') }}</option>
          <option value="compact">{{ t('settings.compact') }}</option>
        </select>
      </label>
    </section>

    <!-- Live preview: shows at once what text size, density and accent colour do -->
    <section>
      <h2 class="mb-1 font-medium">{{ t('appearance.preview') }}</h2>
      <p class="mb-3 text-sm text-muted">{{ t('appearance.previewHint') }}</p>
      <div class="overflow-hidden rounded-2xl bg-surface ring-1 ring-line" aria-hidden="true">
        <div v-for="(row, i) in previewRows" :key="i" class="flex gap-3 border-b border-line px-4 last:border-b-0" :class="[compact ? 'py-2' : 'py-3', i === 0 ? 'bg-accent-soft' : '']">
          <span v-if="!compact" class="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white" :style="{ background: row.color }">{{ row.initials }}</span>
          <span v-else class="mt-0.5 size-5 shrink-0 rounded-md ring-1 ring-line" />
          <div class="min-w-0 flex-1">
            <div class="flex items-baseline gap-2">
              <span v-if="row.unread" class="size-2 shrink-0 self-center rounded-full bg-accent" />
              <span class="truncate" :class="row.unread ? 'font-semibold' : 'text-ink/80'">{{ row.from }}</span>
              <span class="ml-auto shrink-0 text-xs tabular-nums" :class="row.unread ? 'font-medium text-accent' : 'text-muted'">{{ row.time }}</span>
            </div>
            <p class="truncate text-[0.875rem]" :class="row.unread ? 'text-ink' : 'text-muted'">
              {{ row.subject }}<span v-if="compact" class="text-muted"> – {{ row.snippet }}</span>
            </p>
            <p v-if="!compact" class="truncate text-[0.8125rem] text-muted">{{ row.snippet }}</p>
          </div>
        </div>
        <div class="border-t border-line px-4 py-4">
          <p class="mb-1 font-semibold">{{ t('appearance.sampleTitle') }}</p>
          <p class="text-muted" :style="{ fontSize: mailSize }">{{ t('appearance.sampleText') }}</p>
          <span class="mt-3 inline-block rounded-full bg-accent px-4 py-1.5 text-sm font-medium text-white">{{ t('actions.reply') }}</span>
        </div>
      </div>
    </section>

    <section>
      <h2 class="mb-1 font-medium">{{ t('labels.title') }}</h2>
      <p class="mb-3 text-sm text-muted">{{ t('labels.intro') }}</p>
      <ul class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
        <li v-for="l in labels" :key="l.key" class="flex items-center gap-3 px-4 py-2">
          <label class="relative size-6 shrink-0 cursor-pointer overflow-hidden rounded-full ring-1 ring-line" :style="{ background: l.color }" :title="t('calsettings.color')">
            <input type="color" :value="l.color" class="absolute inset-0 cursor-pointer opacity-0" @change="recolor(l, $event.target.value)" />
          </label>
          <input
            :value="l.name" :placeholder="labelName(l)" maxlength="40"
            class="min-w-0 flex-1 rounded-lg bg-transparent px-2 py-1 outline-none focus:bg-sunken"
            :aria-label="t('calsettings.name')"
            @change="rename(l, $event.target.value)"
          />
          <button class="rounded-lg p-1.5 text-muted hover:bg-sunken hover:text-danger" :title="t('actions.trash')" @click="removeLabel(l)"><AppIcon name="trash" :size="17" /></button>
        </li>
        <li v-if="!labels.length" class="px-4 py-3 text-sm text-muted">{{ t('labels.none') }}</li>
      </ul>
      <form v-if="canAdd" class="mt-3 flex gap-2" @submit.prevent="addLabel">
        <input v-model="newName" maxlength="40" :placeholder="t('labels.newPlaceholder')" class="min-w-0 flex-1 rounded-xl border border-line bg-surface px-3 py-2 outline-none focus:border-accent" />
        <button type="submit" :disabled="!newName.trim()" class="shrink-0 rounded-xl bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{{ t('labels.add') }}</button>
      </form>
      <p class="mt-2 text-xs text-muted">{{ t('labels.thunderbird') }}</p>
    </section>
  </div>
</template>
