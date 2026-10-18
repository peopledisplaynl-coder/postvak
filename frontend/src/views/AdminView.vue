<script setup>
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import { api } from '../api/client.js'
import { useSession } from '../stores/session.js'
import { useToasts } from '../stores/toasts.js'
import { t, locale } from '../i18n/index.js'

const route = useRoute()
const router = useRouter()
const session = useSession()
const toasts = useToasts()

const tabs = ['overview', 'users', 'security', 'settings']
const tab = computed(() => (tabs.includes(route.params.tab) ? route.params.tab : 'overview'))

const overview = ref(null)
const users = ref([])
const security = ref(null)
const settings = ref(null)
const loading = ref(false)
const busy = ref(false)

const fail = (e) => toasts.error(t('toast.failed', { msg: e.message }))
const date = (s) => (s ? new Date(s.replace(' ', 'T')).toLocaleString(locale(), { dateStyle: 'medium', timeStyle: 'short' }) : t('admin.never'))

async function load() {
  loading.value = true
  try {
    if (tab.value === 'overview') overview.value = await api.get('/admin/overview')
    if (tab.value === 'users') users.value = await api.get('/admin/users')
    if (tab.value === 'security') security.value = await api.get('/admin/security')
    if (tab.value === 'settings') {
      settings.value = await api.get('/admin/settings')
      domainsText.value = settings.value.allowed_from_config ? '' : settings.value.allowed_login_domains.join('\n')
      defaults.value = { ...settings.value.user_defaults }
    }
  } catch (e) {
    fail(e)
  } finally {
    loading.value = false
  }
}
watch(tab, load, { immediate: true })

// ---- overview
async function migrate() {
  busy.value = true
  try {
    await api.post('/admin/migrate')
    toasts.show(t('admin.migrated'))
    await load()
  } catch (e) {
    fail(e)
  } finally {
    busy.value = false
  }
}

// ---- users
async function resetTwoFactor(u) {
  if (!confirm(t('admin.confirmReset2fa', { email: u.login_email }))) return
  try {
    await api.post(`/admin/users/${u.id}/reset-2fa`)
    u.two_factor = false
    toasts.show(t('admin.reset2faDone'))
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

async function toggleBlock(u) {
  if (!u.blocked && !confirm(t('admin.confirmBlock', { email: u.login_email }))) return
  try {
    const res = await api.patch(`/admin/users/${u.id}`, { blocked: !u.blocked })
    u.blocked = res.blocked
  } catch (e) {
    fail(e)
  }
}
async function removeUser(u) {
  if (!confirm(t('admin.confirmRemoveUser', { email: u.login_email }))) return
  try {
    await api.del(`/admin/users/${u.id}`)
    users.value = users.value.filter((x) => x.id !== u.id)
  } catch (e) {
    fail(e)
  }
}

// ---- security
async function release(body) {
  try {
    await api.post('/admin/security/release', body)
    toasts.show(t('admin.released'))
    await load()
  } catch (e) {
    fail(e)
  }
}

// ---- settings
const domainsText = ref('')
const defaults = ref({})
const defaultFields = [
  { key: 'swipe_right', label: () => t('settings.swipeRight'), options: ['archive', 'trash', 'read', 'flag', 'none'].map((v) => [v, t(`actions.${v}`)]) },
  { key: 'swipe_left', label: () => t('settings.swipeLeft'), options: ['archive', 'trash', 'read', 'flag', 'none'].map((v) => [v, t(`actions.${v}`)]) },
  { key: 'theme', label: () => t('settings.theme'), options: [['system', t('settings.themeSystem')], ['light', t('settings.themeLight')], ['dark', t('settings.themeDark')]] },
  { key: 'density', label: () => t('settings.density'), options: [['comfortable', t('settings.comfortable')], ['compact', t('settings.compact')]] },
  { key: 'show_images', label: () => t('settings.showImages'), options: [['ask', t('settings.imagesAsk')], ['always', t('settings.imagesAlways')], ['never', t('settings.imagesNever')]] },
  { key: 'sort', label: () => t('list.sort'), options: ['date_desc', 'date_asc', 'from', 'subject', 'size_desc'].map((v) => [v, t(`list.sortOptions.${v}`)]) },
  { key: 'undo_seconds', label: () => t('settings.undoSeconds'), options: [0, 3, 5, 10, 20].map((v) => [v, v ? t('settings.seconds', { n: v }) : t('actions.none')]) },
]

async function saveSettings() {
  busy.value = true
  try {
    const domains = domainsText.value.split(/[\s,;]+/).map((d) => d.trim()).filter(Boolean)
    settings.value = await api.patch('/admin/settings', { allowed_login_domains: domains, user_defaults: defaults.value })
    domainsText.value = settings.value.allowed_from_config ? '' : settings.value.allowed_login_domains.join('\n')
    toasts.show(t('admin.saved'))
  } catch (e) {
    fail(e)
  } finally {
    busy.value = false
  }
}

const cronOk = computed(() => !!overview.value?.cron && Date.parse(overview.value.cron.at) > Date.now() - 30 * 60 * 1000)
</script>

<template>
  <div class="pt-safe pb-safe mx-auto flex h-full max-w-3xl flex-col">
    <header class="flex items-center gap-2 px-3 py-3">
      <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('actions.back')" @click="router.push('/')">
        <AppIcon name="back" />
      </button>
      <h1 class="text-xl font-semibold">{{ t('admin.title') }}</h1>
      <div class="flex-1" />
      <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('list.refresh')" @click="load">
        <AppIcon name="refresh" :class="loading ? 'animate-spin' : ''" />
      </button>
    </header>

    <nav class="flex gap-1 overflow-x-auto px-4" role="tablist">
      <router-link
        v-for="name in tabs"
        :key="name"
        :to="`/admin/${name}`"
        role="tab"
        class="shrink-0 rounded-full px-4 py-2 text-sm"
        :class="tab === name ? 'bg-ink font-medium text-canvas' : 'text-muted hover:bg-sunken'"
      >{{ t(`admin.tabs.${name}`) }}</router-link>
    </nav>

    <main class="min-h-0 flex-1 overflow-y-auto px-4 py-6">
      <!-- Overview -->
      <div v-if="tab === 'overview' && overview" class="space-y-6">
        <p v-if="overview.debug" class="rounded-xl bg-flag/15 px-4 py-3 text-sm">{{ t('admin.debugOn') }}</p>

        <div class="rounded-2xl bg-surface px-4 py-3 ring-1 ring-line">
          <p v-if="!overview.database.migrations.pending.length" class="flex items-center gap-2">
            <span class="size-2.5 rounded-full bg-archive" />{{ t('admin.migrationsOk') }}
          </p>
          <div v-else class="flex flex-wrap items-center gap-3">
            <span class="flex flex-1 items-center gap-2"><span class="size-2.5 rounded-full bg-flag" />{{ t('admin.migrationsPending', { list: overview.database.migrations.pending.join(', ') }) }}</span>
            <button class="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-60" :disabled="busy" @click="migrate">{{ t('admin.runMigrations') }}</button>
          </div>
        </div>

        <section>
          <h2 class="mb-2 font-medium">{{ t('admin.statsTitle') }}</h2>
          <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-2xl bg-line ring-1 ring-line sm:grid-cols-4">
            <div v-for="[key, label] in [['users', 'statUsers'], ['accounts', 'statAccounts'], ['logins_24h', 'statLogins'], ['failed_24h', 'statFailed']]" :key="key" class="bg-surface px-4 py-3">
              <dt class="text-sm text-muted">{{ t(`admin.${label}`) }}</dt>
              <dd class="text-2xl font-semibold tabular-nums" :class="key === 'failed_24h' && overview.stats[key] ? 'text-danger' : ''">{{ overview.stats[key] }}</dd>
            </div>
          </dl>
        </section>

        <!-- Background jobs (cron) -->
        <section>
          <h2 class="mb-2 font-medium">{{ t('admin.cronTitle') }}</h2>
          <div class="space-y-3 rounded-2xl bg-surface px-4 py-3 ring-1 ring-line">
            <p class="flex items-center gap-2">
              <span class="size-2.5 shrink-0 rounded-full" :class="cronOk ? 'bg-archive' : 'bg-flag'" />
              <span>{{ overview.cron ? t('admin.cronLast', { date: new Date(overview.cron.at).toLocaleString(locale(), { dateStyle: 'medium', timeStyle: 'short' }), ms: overview.cron.took_ms }) : t('admin.cronNever') }}</span>
            </p>
            <p class="text-sm text-muted">{{ overview.push_devices === 1 ? t('admin.cronDevicesOne') : t('admin.cronDevices', { n: overview.push_devices }) }}</p>
            <details :open="!cronOk" class="text-sm">
              <summary class="cursor-pointer font-medium text-accent">{{ t('admin.cronHowTo') }}</summary>
              <ol class="mt-2 list-decimal space-y-1 pl-5 text-muted">
                <li>{{ t('admin.cronStep1') }}</li>
                <li>{{ t('admin.cronStep2') }}</li>
                <li>{{ t('admin.cronStep3') }} <code class="rounded bg-sunken px-1.5 py-0.5 text-ink">*/5 * * * *</code></li>
                <li>{{ t('admin.cronStep4') }}</li>
              </ol>
              <p class="mt-2 text-muted">{{ t('admin.cronUrl') }}</p>
            </details>
          </div>
        </section>

        <section>
          <h2 class="mb-2 font-medium">{{ t('admin.mailServer') }}</h2>
          <ul class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
            <li v-for="(s, kind) in overview.mail_server" :key="kind" class="flex flex-wrap items-center gap-x-3 gap-y-0.5 px-4 py-3">
              <span class="size-2.5 shrink-0 rounded-full" :class="s.ok === null ? 'bg-muted' : s.ok ? 'bg-archive' : 'bg-danger'" />
              <span class="w-12 shrink-0 font-medium uppercase">{{ kind }}</span>
              <span class="min-w-0 flex-1 truncate text-muted">{{ s.host }}:{{ s.port }}</span>
              <span class="w-full pl-[22px] text-sm sm:w-auto sm:shrink-0 sm:pl-0" :class="s.ok === false ? 'text-danger' : 'text-muted'">
                {{ s.ok === null ? t('admin.perDomain') : s.ok ? t('admin.reachable', { ms: s.ms }) : t('admin.unreachable', { note: s.note }) }}
              </span>
            </li>
          </ul>
        </section>

        <section>
          <h2 class="mb-2 font-medium">{{ t('admin.system') }}</h2>
          <dl class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
            <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="shrink-0 text-muted">{{ t('admin.version') }}</dt><dd>{{ overview.version }}</dd></div>
            <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="shrink-0 text-muted">{{ t('admin.php') }}</dt><dd>{{ overview.php }}</dd></div>
            <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="shrink-0 text-muted">{{ t('admin.database') }}</dt><dd class="truncate">{{ overview.database.server }}</dd></div>
            <div class="flex justify-between gap-4 px-4 py-2.5">
              <dt class="shrink-0 text-muted">{{ t('admin.extensions') }}</dt>
              <dd class="flex flex-wrap justify-end gap-1.5">
                <span v-for="(ok, ext) in overview.extensions" :key="ext" class="rounded-full px-2 py-0.5 text-xs" :class="ok ? 'bg-archive/15 text-archive' : 'bg-danger/15 text-danger'">{{ ext }}</span>
              </dd>
            </div>
            <div class="flex justify-between gap-4 px-4 py-2.5">
              <dt class="shrink-0 text-muted">{{ t('admin.limits') }}</dt>
              <dd class="text-right text-sm">upload {{ overview.limits.upload_max_filesize }}, post {{ overview.limits.post_max_size }}, geheugen {{ overview.limits.memory_limit }}, tijd {{ overview.limits.max_execution_time }}s</dd>
            </div>
          </dl>
        </section>
      </div>

      <!-- Users -->
      <ul v-else-if="tab === 'users'" class="space-y-3">
        <li v-for="u in users" :key="u.id" class="rounded-2xl bg-surface px-4 py-3 ring-1 ring-line" :class="u.blocked ? 'opacity-70' : ''">
          <div class="flex flex-wrap items-center gap-2">
            <span class="min-w-0 truncate font-medium">{{ u.login_email }}</span>
            <span v-if="u.is_admin" class="rounded-full bg-accent-soft px-2 py-0.5 text-xs text-accent">{{ t('admin.adminBadge') }}</span>
            <span v-if="u.blocked" class="rounded-full bg-danger/15 px-2 py-0.5 text-xs text-danger">{{ t('admin.blockedBadge') }}</span>
            <span v-if="u.two_factor" class="rounded-full bg-archive/15 px-2 py-0.5 text-xs text-archive">{{ t('admin.twoFactorBadge') }}</span>
            <div class="flex-1" />
            <template v-if="u.id !== session.user?.id">
              <button v-if="u.two_factor" class="rounded-lg px-3 py-1.5 text-sm ring-1 ring-line hover:bg-sunken" @click="resetTwoFactor(u)">{{ t('admin.reset2fa') }}</button>
              <button class="rounded-lg px-3 py-1.5 text-sm ring-1 ring-line hover:bg-sunken" @click="toggleBlock(u)">{{ u.blocked ? t('admin.unblock') : t('admin.block') }}</button>
              <button class="rounded-lg px-3 py-1.5 text-sm text-danger hover:bg-danger/10" @click="removeUser(u)">{{ t('admin.removeUser') }}</button>
            </template>
          </div>
          <p class="mt-1 text-sm text-muted">{{ t('admin.lastLogin', { date: date(u.last_login_at) }) }}</p>
          <p v-if="u.accounts.length > 1" class="mt-1 text-sm text-muted">{{ u.accounts.map((a) => a.email).join(', ') }}</p>
        </li>
      </ul>

      <!-- Security -->
      <div v-else-if="tab === 'security' && security" class="space-y-6">
        <p class="text-sm text-muted">{{ t('admin.limitsText', { email: security.limits.max_per_email, ip: security.limits.max_per_ip, minutes: security.limits.window_minutes }) }}</p>

        <section>
          <h2 class="mb-2 font-medium">{{ t('admin.blockedNow') }}</h2>
          <ul v-if="security.blocked_emails.length || security.blocked_ips.length" class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
            <li v-for="b in security.blocked_emails" :key="b.key" class="flex items-center gap-3 px-4 py-3">
              <span class="min-w-0 flex-1 truncate">{{ b.email || '?' }}</span>
              <span class="text-sm text-muted">{{ t('admin.attempts', { n: b.fails }) }}</span>
              <button class="rounded-lg px-3 py-1.5 text-sm font-medium text-accent ring-1 ring-line hover:bg-accent-soft" @click="release({ email_key: b.key })">{{ t('admin.release') }}</button>
            </li>
            <li v-for="b in security.blocked_ips" :key="b.key" class="flex items-center gap-3 px-4 py-3">
              <span class="min-w-0 flex-1 truncate tabular-nums">IP {{ b.ip || '?' }}</span>
              <span class="text-sm text-muted">{{ t('admin.attempts', { n: b.fails }) }}</span>
              <button class="rounded-lg px-3 py-1.5 text-sm font-medium text-accent ring-1 ring-line hover:bg-accent-soft" @click="release({ ip_key: b.key })">{{ t('admin.release') }}</button>
            </li>
          </ul>
          <p v-else class="text-sm text-muted">{{ t('admin.nothingBlocked') }}</p>
        </section>

        <section>
          <h2 class="mb-2 font-medium">{{ t('admin.recent') }}</h2>
          <div v-if="security.recent.length" class="overflow-x-auto rounded-2xl bg-surface ring-1 ring-line">
            <table class="w-full text-sm">
              <tbody class="divide-y divide-line">
                <tr v-for="(a, i) in security.recent" :key="i">
                  <td class="px-4 py-2 whitespace-nowrap text-muted tabular-nums">{{ date(a.at) }}</td>
                  <td class="px-2 py-2">{{ a.email }}</td>
                  <td class="px-2 py-2 text-muted tabular-nums">{{ a.ip }}</td>
                  <td class="px-4 py-2 text-right" :class="a.success ? 'text-archive' : 'text-danger'">{{ a.success ? t('admin.success') : t('admin.failed') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="text-sm text-muted">{{ t('admin.noAttempts') }}</p>
        </section>
      </div>

      <!-- Settings -->
      <form v-else-if="tab === 'settings' && settings" class="space-y-6" @submit.prevent="saveSettings">
        <section>
          <h2 class="font-medium">{{ t('admin.domainsTitle') }}</h2>
          <p class="mt-1 text-sm text-muted">{{ t('admin.domainsIntro') }}</p>
          <textarea
            v-model="domainsText"
            rows="4"
            class="mt-2 w-full rounded-xl border border-line bg-surface px-3 py-2 outline-none focus:border-accent"
            :placeholder="settings.allowed_login_domains.join('\n') || 'voorbeeld.nl'"
          />
          <p v-if="settings.allowed_from_config && settings.allowed_login_domains.length" class="mt-1 text-sm text-muted">{{ t('admin.domainsFromConfig') }}</p>
          <p v-else-if="!settings.allowed_login_domains.length" class="mt-1 text-sm text-flag">{{ t('admin.domainsEveryone') }}</p>
        </section>

        <section>
          <h2 class="font-medium">{{ t('admin.defaultsTitle') }}</h2>
          <p class="mt-1 text-sm text-muted">{{ t('admin.defaultsIntro') }}</p>
          <div class="mt-2 divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
            <label v-for="f in defaultFields" :key="f.key" class="flex items-center justify-between gap-4 px-4 py-3">
              <span>{{ f.label() }}</span>
              <select v-model="defaults[f.key]" class="rounded-lg bg-sunken px-3 py-1.5">
                <option v-for="[value, label] in f.options" :key="value" :value="value">{{ label }}</option>
              </select>
            </label>
          </div>
        </section>

        <section>
          <h2 class="font-medium">{{ t('admin.adminsTitle') }}</h2>
          <p class="mt-1 text-sm text-muted">
            {{ settings.admins.length ? t('admin.adminsConfig', { list: settings.admins.join(', ') }) : t('admin.adminsDefault') }}
          </p>
        </section>

        <div class="flex justify-end">
          <button class="rounded-lg bg-accent px-5 py-2 font-medium text-white disabled:opacity-60" :disabled="busy">{{ t('admin.save') }}</button>
        </div>
      </form>
    </main>
  </div>
</template>
