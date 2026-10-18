<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import { api } from '../api/client.js'
import { useToasts } from '../stores/toasts.js'
import { timeZone, ymd, parseYmd, addDays, taskGroup } from '../calendar.js'
import { t, locale } from '../i18n/index.js'

/**
 * Tasks (VTODO): quick add, grouped by due date, done with one tap. Same lists as the
 * Reminders app on an iPhone or Tasks.org on Android when synced over CalDAV.
 */
const router = useRouter()
const toasts = useToasts()

const lists = ref([])
const tasks = ref([])
const loading = ref(true)
const listFilter = ref('all') // 'all' or list id
const showDone = ref(false)
const quick = ref('')
const quickInput = ref(null)
const editing = ref(null) // task being edited (copy)
const saving = ref(false)

const taskLists = computed(() => lists.value.filter((c) => c.type !== 'events'))

async function load() {
  loading.value = true
  try {
    const [cals, list] = await Promise.all([api.get('/calendars'), api.get('/tasks', { tz: timeZone() })])
    lists.value = cals
    tasks.value = list
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    loading.value = false
  }
}
onMounted(load)

const filtered = computed(() => tasks.value.filter((x) => listFilter.value === 'all' || x.calendar_id === listFilter.value))
const groups = computed(() => {
  const order = ['overdue', 'today', 'later', 'none']
  const map = Object.fromEntries(order.map((k) => [k, []]))
  for (const task of filtered.value) if (!task.completed) map[taskGroup(task)].push(task)
  return order.filter((k) => map[k].length).map((k) => ({ key: k, items: map[k] }))
})
const done = computed(() => filtered.value.filter((x) => x.completed).sort((a, b) => (b.completed_at ?? '').localeCompare(a.completed_at ?? '')))

function replace(task) {
  const i = tasks.value.findIndex((x) => x.id === task.id)
  if (i === -1) tasks.value.push(task)
  else tasks.value[i] = task
}

async function addQuick() {
  const title = quick.value.trim()
  if (!title) return
  quick.value = ''
  try {
    const task = await api.post('/tasks', {
      title,
      calendar_id: listFilter.value === 'all' ? undefined : listFilter.value,
      timezone: timeZone(),
    })
    tasks.value.unshift(task)
  } catch (e) {
    quick.value = title
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

async function toggle(task) {
  const before = { ...task }
  task.completed = !task.completed
  task.completed_at = task.completed ? new Date().toISOString() : null
  try {
    replace(await api.patch(`/tasks/${task.id}`, { completed: task.completed, timezone: timeZone() }))
    if (task.completed) toasts.show(t('tasks.doneToast'), { action: { label: t('actions.undo'), run: () => toggle(tasks.value.find((x) => x.id === task.id)) } })
  } catch (e) {
    replace(before)
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

// ---- editor
const dueDate = ref('')
const dueTime = ref('')
function edit(task) {
  editing.value = { ...task }
  dueDate.value = task.due ? task.due.slice(0, 10) : ''
  dueTime.value = task.due && task.due.length > 10 ? task.due.slice(11, 16) : ''
}
function quickDue(days) {
  dueDate.value = days === null ? '' : ymd(addDays(new Date(), days))
  if (days === null) dueTime.value = ''
}
async function saveEdit() {
  const e = editing.value
  saving.value = true
  try {
    const task = await api.patch(`/tasks/${e.id}`, {
      title: e.title,
      description: e.description,
      priority: e.priority,
      calendar_id: e.calendar_id,
      due: dueDate.value ? (dueTime.value ? `${dueDate.value}T${dueTime.value}` : dueDate.value) : null,
      reminder: !!dueDate.value && e.reminder,
      timezone: timeZone(),
    })
    replace(task)
    editing.value = null
  } catch (err) {
    toasts.error(t('toast.failed', { msg: err.message }))
  } finally {
    saving.value = false
  }
}
async function removeTask() {
  const e = editing.value
  if (!confirm(t('tasks.confirmDelete', { title: e.title }))) return
  try {
    await api.del(`/tasks/${e.id}`)
    tasks.value = tasks.value.filter((x) => x.id !== e.id)
    editing.value = null
    toasts.show(t('tasks.deleted'))
  } catch (err) {
    toasts.error(t('toast.failed', { msg: err.message }))
  }
}
function openMail(task) {
  const m = task.mail
  router.push({ path: `/mail/${m.account_id}`, query: { folder: m.folder, uid: m.uid, acc: m.account_id } })
}

// ---- formatting
function dueLabel(task) {
  if (!task.due) return ''
  const day = task.due.slice(0, 10)
  const d = parseYmd(day)
  let label
  if (day === ymd(new Date())) label = t('dates.today')
  else if (day === ymd(addDays(new Date(), 1))) label = t('dates.tomorrow')
  else if (day === ymd(addDays(new Date(), -1))) label = t('dates.yesterday')
  else label = d.toLocaleDateString(locale(), { weekday: 'short', day: 'numeric', month: 'short' })
  return task.due.length > 10 ? `${label} ${task.due.slice(11, 16)}` : label
}
const listOf = (task) => lists.value.find((c) => c.id === task.calendar_id)
const priorityColor = { 1: 'text-danger', 5: 'text-flag', 9: 'text-muted' }
const priorityMark = { 1: '!!!', 5: '!!', 9: '!' }
const priorityName = { 1: 'high', 5: 'medium', 9: 'low' }
const field = 'w-full rounded-xl border border-line bg-surface px-3 py-2 outline-none focus:border-accent'

function focusQuick() {
  nextTick(() => quickInput.value?.focus())
}
</script>

<template>
  <div class="pt-safe pb-safe flex h-full flex-col bg-surface">
    <header class="flex items-center gap-1 border-b border-line px-2 py-2">
      <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('actions.back')" @click="router.push('/')">
        <AppIcon name="back" />
      </button>
      <h1 class="flex-1 px-1 text-lg font-semibold">{{ t('tasks.title') }}</h1>
      <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :title="t('calendar.title')" @click="router.push('/calendar')"><AppIcon name="calendar" /></button>
    </header>

    <div class="mx-auto flex min-h-0 w-full max-w-2xl flex-1 flex-col">
      <div v-if="taskLists.length > 1" class="flex gap-1 no-scrollbar overflow-x-auto px-3 pt-3">
        <button class="shrink-0 rounded-full px-3 py-1 text-sm ring-1 ring-line" :class="listFilter === 'all' ? 'bg-ink text-surface ring-ink' : ''" @click="listFilter = 'all'">{{ t('tasks.allLists') }}</button>
        <button
          v-for="l in taskLists" :key="l.id"
          class="flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1 text-sm ring-1 ring-line"
          :class="listFilter === l.id ? 'bg-ink text-surface ring-ink' : ''"
          @click="listFilter = l.id"
        >
          <span class="size-2.5 rounded-full" :style="{ background: l.color }" />{{ l.name }}
        </button>
      </div>

      <form class="flex items-center gap-2 px-3 py-3" @submit.prevent="addQuick">
        <AppIcon name="plus" class="shrink-0 text-accent" />
        <input
          ref="quickInput" v-model="quick" :placeholder="t('tasks.add')" enterkeyhint="done"
          class="min-w-0 flex-1 rounded-xl bg-sunken px-3 py-2.5 outline-none focus:ring-2 focus:ring-accent"
        />
      </form>

      <div class="min-h-0 flex-1 overflow-y-auto pb-24">
        <div v-if="loading" class="space-y-2 px-4">
          <div v-for="i in 4" :key="i" class="h-10 animate-pulse rounded-xl bg-sunken" />
        </div>

        <template v-else>
          <section v-for="g in groups" :key="g.key">
            <h2 class="px-4 pb-1 pt-3 text-sm font-medium" :class="g.key === 'overdue' ? 'text-danger' : 'text-muted'">{{ t(`tasks.group.${g.key}`) }}</h2>
            <ul>
              <li v-for="task in g.items" :key="task.id" class="group flex items-start gap-2 px-2 hover:bg-sunken/60">
                <button class="rounded-full p-2 text-muted hover:text-accent" :aria-label="t('tasks.markDone')" @click="toggle(task)">
                  <AppIcon name="circle" :size="22" :style="{ color: listOf(task)?.color }" />
                </button>
                <button class="min-w-0 flex-1 py-2 text-left" @click="edit(task)">
                  <span class="block truncate">{{ task.title }}</span>
                  <span v-if="(task.due && !(g.key === 'today' && task.due.length === 10)) || task.mail || task.description || task.reminder" class="flex items-center gap-2 text-sm text-muted">
                    <span v-if="task.due && !(g.key === 'today' && task.due.length === 10)" :class="g.key === 'overdue' ? 'text-danger' : ''">{{ dueLabel(task) }}</span>
                    <AppIcon v-if="task.reminder" name="bell" :size="13" />
                    <span v-if="task.mail" class="flex min-w-0 items-center gap-1 truncate"><AppIcon name="mail" :size="14" class="shrink-0" />{{ task.mail.from || task.mail.subject }}</span>
                    <span v-else-if="task.description" class="truncate">{{ task.description }}</span>
                  </span>
                </button>
                <span v-if="task.priority" class="mt-2.5 shrink-0 text-sm font-bold" :class="priorityColor[task.priority]" :title="t('tasks.priority') + ': ' + t(`tasks.prio.${priorityName[task.priority]}`)">{{ priorityMark[task.priority] }}</span>
                <button v-if="task.mail" class="rounded-lg p-2 text-muted hover:text-ink" :title="t('tasks.openMail')" @click="openMail(task)"><AppIcon name="mail" :size="18" /></button>
              </li>
            </ul>
          </section>

          <div v-if="!groups.length" class="px-6 py-12 text-center text-muted">
            <AppIcon name="checkCircle" :size="40" class="mx-auto mb-2 text-archive" />
            <p>{{ t('tasks.empty') }}</p>
            <button class="mt-3 text-accent" @click="focusQuick">{{ t('tasks.add') }}</button>
          </div>

          <section v-if="done.length" class="mt-2">
            <button class="flex w-full items-center gap-1 px-4 py-2 text-sm font-medium text-muted hover:text-ink" @click="showDone = !showDone">
              <AppIcon name="chevron" :size="16" class="transition" :class="showDone ? 'rotate-90' : ''" />
              {{ t('tasks.done', { n: done.length }) }}
            </button>
            <ul v-if="showDone">
              <li v-for="task in done" :key="task.id" class="flex items-center gap-2 px-2">
                <button class="rounded-full p-2 text-archive" :aria-label="t('tasks.markOpen')" @click="toggle(task)"><AppIcon name="checkCircle" :size="22" /></button>
                <button class="min-w-0 flex-1 truncate py-2 text-left text-muted line-through" @click="edit(task)">{{ task.title }}</button>
              </li>
            </ul>
          </section>
        </template>
      </div>
    </div>

    <!-- Edit a task -->
    <div v-if="editing" class="fixed inset-0 z-40 flex items-end justify-center bg-ink/40 sm:items-center sm:p-4" @click.self="editing = null">
      <form class="pb-safe flex max-h-[92vh] w-full flex-col rounded-t-2xl bg-surface shadow-2xl ring-1 ring-line sm:max-w-md sm:rounded-2xl" @submit.prevent="saveEdit" @keydown.esc="editing = null">
        <header class="flex items-center gap-2 border-b border-line px-3 py-2">
          <button type="button" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('compose.close')" @click="editing = null"><AppIcon name="close" /></button>
          <h2 class="flex-1 font-semibold">{{ t('tasks.edit') }}</h2>
          <button type="button" class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-danger" :title="t('actions.trash')" @click="removeTask"><AppIcon name="trash" /></button>
          <button type="submit" :disabled="saving" class="rounded-xl bg-accent px-4 py-2 font-medium text-white disabled:opacity-60">{{ t('calendar.save') }}</button>
        </header>
        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
          <input v-model="editing.title" class="w-full bg-transparent text-xl font-semibold outline-none" :placeholder="t('tasks.titlePlaceholder')" />

          <div>
            <span class="mb-1 block text-sm text-muted">{{ t('tasks.due') }}</span>
            <div class="mb-2 flex flex-wrap gap-1.5 text-sm">
              <button type="button" class="rounded-full px-3 py-1 ring-1 ring-line hover:bg-sunken" @click="quickDue(0)">{{ t('calendar.today') }}</button>
              <button type="button" class="rounded-full px-3 py-1 ring-1 ring-line hover:bg-sunken" @click="quickDue(1)">{{ t('tasks.tomorrow') }}</button>
              <button type="button" class="rounded-full px-3 py-1 ring-1 ring-line hover:bg-sunken" @click="quickDue(7)">{{ t('tasks.nextWeek') }}</button>
              <button type="button" class="rounded-full px-3 py-1 text-muted ring-1 ring-line hover:bg-sunken" @click="quickDue(null)">{{ t('tasks.noDate') }}</button>
            </div>
            <div class="flex gap-2">
              <input v-model="dueDate" type="date" :class="field" />
              <input v-model="dueTime" type="time" :disabled="!dueDate" :class="field" class="w-32 disabled:opacity-50" />
            </div>
            <label v-if="dueDate" class="mt-2 flex items-center gap-2 text-sm">
              <input v-model="editing.reminder" type="checkbox" class="size-4 accent-accent" />
              {{ dueTime ? t('tasks.remindAtTime') : t('tasks.remindMorning') }}
            </label>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <label class="block">
              <span class="mb-1 block text-sm text-muted">{{ t('tasks.priority') }}</span>
              <select v-model="editing.priority" :class="field">
                <option :value="0">{{ t('tasks.prio.none') }}</option>
                <option :value="1">{{ t('tasks.prio.high') }}</option>
                <option :value="5">{{ t('tasks.prio.medium') }}</option>
                <option :value="9">{{ t('tasks.prio.low') }}</option>
              </select>
            </label>
            <label class="block">
              <span class="mb-1 block text-sm text-muted">{{ t('tasks.list') }}</span>
              <select v-model="editing.calendar_id" :class="field">
                <option v-for="l in taskLists" :key="l.id" :value="l.id">{{ l.name }}</option>
              </select>
            </label>
          </div>

          <label class="block">
            <span class="mb-1 block text-sm text-muted">{{ t('calendar.notes') }}</span>
            <textarea v-model="editing.description" rows="3" :class="field" />
          </label>

          <button v-if="editing.mail" type="button" class="flex w-full items-center gap-3 rounded-xl bg-sunken px-3 py-2.5 text-left hover:bg-line" @click="openMail(editing); editing = null">
            <AppIcon name="mail" class="shrink-0 text-muted" />
            <span class="min-w-0 flex-1">
              <span class="block truncate font-medium">{{ editing.mail.subject || t('list.noSubject') }}</span>
              <span class="block truncate text-sm text-muted">{{ editing.mail.from }}</span>
            </span>
            <AppIcon name="chevron" :size="16" class="text-muted" />
          </button>
        </div>
      </form>
    </div>

    <button
      style="bottom: calc(1.25rem + env(safe-area-inset-bottom))"
      class="fixed right-5 z-20 flex size-14 items-center justify-center rounded-2xl bg-accent text-white shadow-lg sm:hidden"
      :aria-label="t('tasks.add')"
      @click="focusQuick"
    >
      <AppIcon name="plus" :size="26" />
    </button>
  </div>
</template>
