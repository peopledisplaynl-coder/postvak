<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from './AppIcon.vue'
import { api } from '../api/client.js'
import { useSession } from '../stores/session.js'
import { useToasts } from '../stores/toasts.js'
import { folderLabel } from '../folders.js'
import { labelName, labelMap } from '../labels.js'
import { t } from '../i18n/index.js'

/**
 * Settings → Rules: out-of-office reply per account, and rules that sort new inbox mail.
 * Both run from the background job (cron), so they work with every mail server.
 */
const session = useSession()
const toasts = useToasts()
const route = useRoute()
const router = useRouter()

const rules = ref([])
const replies = ref([])
const cronOk = ref(true)
const loaded = ref(false)
const fail = (e) => toasts.error(t('toast.failed', { msg: e.message }))

async function load() {
  try {
    const [r, a, push] = await Promise.all([api.get('/rules'), api.get('/autoreplies'), api.get('/push').catch(() => null)])
    rules.value = r
    replies.value = a
    if (push?.cron) cronOk.value = push.cron.ok
    if (!replyAccount.value && a.length) replyAccount.value = a[0].account_id
    loaded.value = true
  } catch (e) {
    fail(e)
  }
}

// ---------------------------------------------------------------- out of office
const replyAccount = ref(null)
const reply = ref(null)
watch([replyAccount, replies], () => {
  const r = replies.value.find((x) => x.account_id === replyAccount.value)
  reply.value = r ? { ...r, starts_on: r.starts_on ?? '', ends_on: r.ends_on ?? '' } : null
})
const savingReply = ref(false)

async function saveReply() {
  savingReply.value = true
  try {
    const saved = await api.put(`/autoreplies/${replyAccount.value}`, reply.value)
    replies.value = replies.value.map((x) => (x.account_id === saved.account_id ? { ...x, ...saved } : x))
    toasts.show(saved.enabled ? (saved.active ? t('rules.replyOn') : t('rules.replyPlanned')) : t('rules.replyOff'))
  } catch (e) {
    fail(e)
  } finally {
    savingReply.value = false
  }
}

// ------------------------------------------------------------------------ rules
const accounts = computed(() => session.accounts)
const accountName = (id) => accounts.value.find((a) => a.id === id)?.email ?? ''
const folders = ref({}) // account id → folders

async function foldersOf(accountId) {
  if (!accountId || folders.value[accountId]) return
  try {
    const list = await api.get(`/accounts/${accountId}/folders`)
    folders.value = { ...folders.value, [accountId]: list.filter((f) => f.selectable && f.path !== 'INBOX' && !['trash', 'snoozed', 'scheduled', 'drafts'].includes(f.role)) }
  } catch (e) {
    fail(e)
  }
}

const editing = ref(null) // rule being edited (copy) or null
const blankCondition = () => ({ field: 'from', op: 'contains', value: '' })

function newRule(prefill = {}) {
  editing.value = {
    id: null,
    name: prefill.name ?? '',
    account_id: prefill.account_id ?? (accounts.value.length === 1 ? accounts.value[0].id : null),
    enabled: true,
    match_all: true,
    conditions: prefill.conditions ?? [blankCondition()],
    actions: { move: null, trash: false, read: false, flag: false, label: null },
    moveOn: false,
  }
  foldersOf(editing.value.account_id)
}

function editRule(rule) {
  editing.value = JSON.parse(JSON.stringify(rule))
  editing.value.moveOn = !!rule.actions.move
  foldersOf(rule.account_id)
}
watch(() => editing.value?.account_id, (id) => foldersOf(id))
// Ticking "move to" picks the first folder, so the rule can be saved at once.
watch(() => [editing.value?.moveOn, folders.value[editing.value?.account_id]], ([on, list]) => {
  if (on && list?.length && !list.some((f) => f.path === editing.value.actions.move)) editing.value.actions.move = list[0].path
})
const editorEl = ref(null)
watch(() => editing.value?.id ?? (editing.value ? 'new' : null), (v) => v && nextTick(() => editorEl.value?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })))

async function saveRule() {
  const r = editing.value
  const body = {
    name: r.name, account_id: r.account_id, enabled: r.enabled, match_all: r.match_all,
    conditions: r.conditions.filter((c) => c.value.trim()),
    actions: { ...r.actions, move: r.moveOn && !r.actions.trash ? r.actions.move : null },
  }
  try {
    const saved = r.id ? await api.put(`/rules/${r.id}`, body) : await api.post('/rules', body)
    rules.value = r.id ? rules.value.map((x) => (x.id === saved.id ? saved : x)) : [...rules.value, saved]
    editing.value = null
    toasts.show(t('rules.saved'))
  } catch (e) {
    fail(e)
  }
}

async function toggle(rule) {
  try {
    Object.assign(rule, await api.patch(`/rules/${rule.id}`, { enabled: !rule.enabled }))
  } catch (e) {
    fail(e)
  }
}

async function remove(rule) {
  if (!confirm(t('rules.confirmDelete', { name: rule.name }))) return
  try {
    await api.del(`/rules/${rule.id}`)
    rules.value = rules.value.filter((x) => x.id !== rule.id)
    if (editing.value?.id === rule.id) editing.value = null
  } catch (e) {
    fail(e)
  }
}

async function move(rule, dir) {
  const list = [...rules.value]
  const i = list.indexOf(rule)
  const j = i + dir
  if (j < 0 || j >= list.length) return
  ;[list[i], list[j]] = [list[j], list[i]]
  rules.value = list
  try {
    rules.value = await api.post('/rules/order', { ids: list.map((x) => x.id) })
  } catch (e) {
    fail(e)
  }
}

const applying = ref(null)
async function applyNow(rule = null) {
  applying.value = rule?.id ?? 'all'
  try {
    const res = await api.post('/rules/apply', rule ? { rule_id: rule.id } : {})
    toasts.show(t('rules.applied', { n: res.matched }))
    load()
  } catch (e) {
    fail(e)
  } finally {
    applying.value = null
  }
}

// A folder path as people know it: "INBOX.Facturen" → "Facturen".
function pathLabel(path) {
  for (const list of Object.values(folders.value)) {
    const f = list.find((x) => x.path === path)
    if (f) return folderLabel(f)
  }
  return String(path).replace(/^INBOX[./]/i, '')
}
const fieldLabel = (f) => t(`rules.field.${f}`)
const opLabel = (o) => t(`rules.op.${o}`)
function summary(rule) {
  const cond = rule.conditions.map((c) => `${fieldLabel(c.field)} ${opLabel(c.op)} "${c.value}"`).join(rule.match_all ? t('rules.and') : t('rules.or'))
  const acts = []
  if (rule.actions.trash) acts.push(t('rules.act.trash'))
  if (rule.actions.move) acts.push(t('rules.act.moveTo', { folder: pathLabel(rule.actions.move) }))
  if (rule.actions.read) acts.push(t('rules.act.read'))
  if (rule.actions.flag) acts.push(t('rules.act.flag'))
  if (rule.actions.label) acts.push(t('rules.act.label', { name: labelName(labelMap(session.settings)[rule.actions.label.toLowerCase()] ?? { key: rule.actions.label, name: '' }) }))
  return `${t('rules.if')} ${cond} → ${acts.join(', ')}`
}

onMounted(async () => {
  await load()
  // From a message: "make a rule for this sender".
  if (route.query.from) {
    newRule({
      name: String(route.query.name || route.query.from),
      account_id: route.query.account ? Number(route.query.account) : undefined,
      conditions: [{ field: 'from', op: 'contains', value: String(route.query.from) }],
    })
    router.replace({ query: {} })
  }
})

const field = 'rounded-lg border border-line bg-surface px-2.5 py-1.5 outline-none focus:border-accent'
</script>

<template>
  <div class="space-y-8">
    <p v-if="loaded && !cronOk" class="flex gap-2 rounded-xl bg-flag/10 px-3 py-2 text-sm">
      <AppIcon name="clock" :size="17" class="mt-0.5 shrink-0" />{{ t('rules.noCron') }}
    </p>

    <!-- Out of office -->
    <section>
      <h2 class="mb-1 font-medium">{{ t('rules.replyTitle') }}</h2>
      <p class="mb-3 text-sm text-muted">{{ t('rules.replyIntro') }}</p>
      <div v-if="reply" class="space-y-3 rounded-2xl bg-surface p-4 ring-1 ring-line">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <select v-if="replies.length > 1" v-model="replyAccount" :class="field">
            <option v-for="r in replies" :key="r.account_id" :value="r.account_id">{{ r.email }}{{ r.active ? ' · ' + t('rules.on') : '' }}</option>
          </select>
          <label class="flex items-center gap-2 font-medium">
            <input v-model="reply.enabled" type="checkbox" class="size-5 accent-accent" />{{ t('rules.replyEnabled') }}
          </label>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block text-sm">
            <span class="mb-1 block text-muted">{{ t('rules.from') }}</span>
            <input v-model="reply.starts_on" type="date" :class="field" class="w-full" />
          </label>
          <label class="block text-sm">
            <span class="mb-1 block text-muted">{{ t('rules.until') }}</span>
            <input v-model="reply.ends_on" type="date" :min="reply.starts_on || undefined" :class="field" class="w-full" />
          </label>
        </div>
        <label class="block text-sm">
          <span class="mb-1 block text-muted">{{ t('rules.replySubject') }}</span>
          <input v-model="reply.subject" maxlength="255" :placeholder="t('rules.replySubjectPlaceholder')" :class="field" class="w-full" />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block text-muted">{{ t('rules.replyBody') }}</span>
          <textarea v-model="reply.body" rows="5" maxlength="5000" :placeholder="t('rules.replyBodyPlaceholder')" :class="field" class="w-full" />
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="reply.contacts_only" type="checkbox" class="size-4 accent-accent" />{{ t('rules.contactsOnly') }}
        </label>
        <p class="text-xs text-muted">{{ t('rules.replyNote') }}</p>
        <button class="rounded-xl bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-60" :disabled="savingReply" @click="saveReply">{{ t('rules.save') }}</button>
      </div>
    </section>

    <!-- Rules -->
    <section>
      <h2 class="mb-1 font-medium">{{ t('rules.title') }}</h2>
      <p class="mb-3 text-sm text-muted">{{ t('rules.intro') }}</p>

      <ul v-if="rules.length" class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
        <li v-for="(rule, i) in rules" :key="rule.id" class="flex items-start gap-3 px-4 py-3">
          <input type="checkbox" :checked="rule.enabled" class="mt-1 size-4 shrink-0 accent-accent" :aria-label="t('rules.enabled')" @change="toggle(rule)" />
          <button class="min-w-0 flex-1 text-left" @click="editRule(rule)">
            <span class="block font-medium" :class="rule.enabled ? '' : 'text-muted line-through'">{{ rule.name }}</span>
            <span class="block text-sm text-muted">{{ summary(rule) }}</span>
            <span class="block text-xs text-muted">
              {{ rule.account_id ? accountName(rule.account_id) : t('rules.allAccounts') }}<template v-if="rule.times_applied"> · {{ t('rules.timesApplied', { n: rule.times_applied }) }}</template>
            </span>
          </button>
          <div class="flex shrink-0 items-center">
            <button class="rounded p-1 text-muted hover:bg-sunken hover:text-ink disabled:opacity-30" :disabled="i === 0" :title="t('rules.up')" @click="move(rule, -1)"><AppIcon name="chevron" :size="16" class="-rotate-90" /></button>
            <button class="rounded p-1 text-muted hover:bg-sunken hover:text-ink disabled:opacity-30" :disabled="i === rules.length - 1" :title="t('rules.down')" @click="move(rule, 1)"><AppIcon name="chevron" :size="16" class="rotate-90" /></button>
            <button class="rounded p-1 text-muted hover:bg-sunken hover:text-danger" :title="t('actions.trash')" @click="remove(rule)"><AppIcon name="trash" :size="16" /></button>
          </div>
        </li>
      </ul>

      <!-- Editor -->
      <form v-if="editing" ref="editorEl" class="mt-3 space-y-4 rounded-2xl bg-surface p-4 ring-2 ring-accent/40" @submit.prevent="saveRule">
        <input v-model="editing.name" required maxlength="100" :placeholder="t('rules.namePlaceholder')" :aria-label="t('rules.name')" :class="field" class="w-full font-medium" />
        <div class="flex flex-wrap items-center gap-2 text-sm">
          <span class="text-muted">{{ t('rules.forAccount') }}</span>
          <select v-model="editing.account_id" :class="field">
            <option :value="null">{{ t('rules.allAccounts') }}</option>
            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.email }}</option>
          </select>
        </div>

        <div class="space-y-2">
          <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="font-medium">{{ t('rules.if') }}</span>
            <select v-if="editing.conditions.length > 1" v-model="editing.match_all" :class="field">
              <option :value="true">{{ t('rules.matchAll') }}</option>
              <option :value="false">{{ t('rules.matchAny') }}</option>
            </select>
          </div>
          <div v-for="(c, i) in editing.conditions" :key="i" class="flex flex-wrap items-center gap-2">
            <select v-model="c.field" :class="field" class="text-sm">
              <option v-for="f in ['from', 'to', 'subject', 'any']" :key="f" :value="f">{{ fieldLabel(f) }}</option>
            </select>
            <select v-model="c.op" :class="field" class="text-sm">
              <option v-for="o in ['contains', 'not_contains', 'is', 'starts_with', 'ends_with']" :key="o" :value="o">{{ opLabel(o) }}</option>
            </select>
            <input v-model="c.value" required maxlength="255" :class="field" class="min-w-0 flex-1 text-sm" :aria-label="t('rules.value')" />
            <button type="button" class="rounded p-1 text-muted hover:text-danger disabled:opacity-30" :disabled="editing.conditions.length === 1" :title="t('rules.removeCondition')" @click="editing.conditions.splice(i, 1)"><AppIcon name="close" :size="16" /></button>
          </div>
          <button v-if="editing.conditions.length < 10" type="button" class="flex items-center gap-1 text-sm text-accent" @click="editing.conditions.push(blankCondition())"><AppIcon name="plus" :size="15" />{{ t('rules.addCondition') }}</button>
        </div>

        <div class="space-y-2 text-sm">
          <p class="font-medium">{{ t('rules.then') }}</p>
          <label class="flex flex-wrap items-center gap-2">
            <input v-model="editing.moveOn" type="checkbox" class="size-4 accent-accent" :disabled="editing.actions.trash" />{{ t('rules.moveTo') }}
            <select v-if="editing.moveOn && editing.account_id" v-model="editing.actions.move" required :class="field">
              <option v-for="f in folders[editing.account_id] ?? []" :key="f.path" :value="f.path">{{ folderLabel(f) }}</option>
            </select>
            <span v-else-if="editing.moveOn" class="text-xs text-danger">{{ t('rules.chooseAccount') }}</span>
          </label>
          <label class="flex items-center gap-2"><input v-model="editing.actions.read" type="checkbox" class="size-4 accent-accent" />{{ t('rules.markRead') }}</label>
          <label class="flex items-center gap-2"><input v-model="editing.actions.flag" type="checkbox" class="size-4 accent-accent" />{{ t('rules.star') }}</label>
          <label v-if="session.settings.labels?.length" class="flex flex-wrap items-center gap-2">
            <span>{{ t('rules.addLabel') }}</span>
            <select v-model="editing.actions.label" :class="field">
              <option :value="null">{{ t('rules.noLabel') }}</option>
              <option v-for="l in session.settings.labels" :key="l.key" :value="l.key">{{ labelName(l) }}</option>
            </select>
          </label>
          <label class="flex items-center gap-2"><input v-model="editing.actions.trash" type="checkbox" class="size-4 accent-accent" />{{ t('rules.toTrash') }}</label>
        </div>

        <div class="flex flex-wrap gap-2">
          <button type="submit" class="rounded-xl bg-accent px-4 py-2 text-sm font-medium text-white">{{ t('rules.save') }}</button>
          <button type="button" class="rounded-xl px-4 py-2 text-sm text-muted hover:text-ink" @click="editing = null">{{ t('calendar.cancel') }}</button>
          <button v-if="editing.id" type="button" class="ml-auto rounded-xl px-3 py-2 text-sm text-accent hover:bg-sunken disabled:opacity-60" :disabled="applying !== null" @click="applyNow(editing)">
            {{ applying === editing.id ? t('rules.applying') : t('rules.applyThis') }}
          </button>
        </div>
      </form>

      <div class="mt-3 flex flex-wrap gap-2">
        <button v-if="!editing" class="flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm ring-1 ring-line hover:bg-sunken" @click="newRule()"><AppIcon name="plus" :size="16" />{{ t('rules.new') }}</button>
        <button v-if="rules.length" class="flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm ring-1 ring-line hover:bg-sunken disabled:opacity-60" :disabled="applying !== null" @click="applyNow()">
          <AppIcon name="refresh" :size="16" :class="applying === 'all' ? 'animate-spin' : ''" />{{ t('rules.applyAll') }}
        </button>
      </div>
    </section>
  </div>
</template>
