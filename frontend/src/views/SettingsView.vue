<script setup>
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import AccountForm from '../components/AccountForm.vue'
import CalendarSettings from '../components/CalendarSettings.vue'
import RulesSettings from '../components/RulesSettings.vue'
import AppearanceSettings from '../components/AppearanceSettings.vue'
import { useSession } from '../stores/session.js'
import { useToasts } from '../stores/toasts.js'
import { api } from '../api/client.js'
import { useMail } from '../stores/mail.js'
import { folderLabel, folderIcon, folderOwnName } from '../folders.js'
import { t, locale, languageNames } from '../i18n/index.js'
import { pushSupport, currentSubscription, enablePush, disablePush, sendTestPush } from '../push.js'

const route = useRoute()
const router = useRouter()
const session = useSession()
const toasts = useToasts()
const mail = useMail()

const tabs = ['general', 'appearance', 'swipe', 'notifications', 'rules', 'calendar', 'privacy', 'security', 'folders', 'accounts']
const tab = computed(() => (tabs.includes(route.params.tab) ? route.params.tab : 'general'))
const editing = ref(null) // account id, 'new' or null

const swipeActions = ['archive', 'trash', 'junk', 'snooze', 'read', 'flag', 'none']
const actionColor = { archive: 'var(--color-archive)', trash: 'var(--color-danger)', junk: 'var(--color-junk)', snooze: 'var(--color-snooze)', read: 'var(--color-read)', flag: 'var(--color-flag)', none: 'var(--color-muted)' }

async function set(key, value) {
  try {
    await session.updateSettings({ [key]: value })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

async function remove(account) {
  if (!confirm(t('settings.confirmRemove', { email: account.email }))) return
  try {
    await session.removeAccount(account.id)
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

// ---- safe senders
const trusted = ref([])
const trustedInput = ref('')

async function loadTrusted() {
  try {
    trusted.value = await api.get('/trusted-senders')
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}
watch(tab, (name) => name === 'privacy' && loadTrusted(), { immediate: true })

async function addTrusted() {
  const value = trustedInput.value.trim().toLowerCase().replace(/^@/, '')
  const kind = value.includes('@') ? 'email' : 'domain'
  try {
    await api.post('/trusted-senders', { kind, value })
    trustedInput.value = ''
    loadTrusted()
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

async function removeTrusted(item) {
  try {
    await api.del(`/trusted-senders/${item.id}`)
    trusted.value = trusted.value.filter((x) => x.id !== item.id)
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

// ---- folders
const folderAccountId = ref(session.loginAccount?.id)
const folderList = ref([])
const newFolder = ref('')
const newFolderParent = ref('')
const renaming = ref(null)
const renameValue = ref('')
const folderBusy = ref(false)
const editableFolders = computed(() => folderList.value.filter((f) => f.selectable && f.role !== 'trash' && f.role !== 'junk'))

async function loadFolders() {
  try {
    folderList.value = await api.get(`/accounts/${folderAccountId.value}/folders`)
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}
watch([tab, folderAccountId], ([name]) => name === 'folders' && loadFolders(), { immediate: true })

async function folderCall(fn) {
  folderBusy.value = true
  try {
    const res = await fn()
    if (res?.folders) folderList.value = res.folders
    // Keep the sidebar in sync when it shows this account.
    if (mail.accountId === folderAccountId.value && res?.folders) mail.folders[mail.accountId] = res.folders
    return true
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
    return false
  } finally {
    folderBusy.value = false
  }
}
async function createFolder() {
  const ok = await folderCall(() => api.post(`/accounts/${folderAccountId.value}/folders`, { name: newFolder.value.trim(), parent: newFolderParent.value || null }))
  if (ok) newFolder.value = ''
}
function startRename(f) {
  renaming.value = f.path
  renameValue.value = folderOwnName(f)
}
async function renameFolder(f) {
  if (await folderCall(() => api.patch(`/accounts/${folderAccountId.value}/folders`, { path: f.path, name: renameValue.value.trim() }))) renaming.value = null
}
async function deleteFolder(f) {
  const msg = f.total ? t('settings.confirmDeleteFolderMessages', { name: folderLabel(f), n: f.total }) : t('settings.confirmDeleteFolder', { name: folderLabel(f) })
  if (!confirm(msg)) return
  await folderCall(() => api.del(`/accounts/${folderAccountId.value}/folders?path=${encodeURIComponent(f.path)}`))
}

// ---- notifications (Web Push)
const push = ref(null)            // GET /push: public key, devices, cron status
const pushOn = ref(false)         // this device is subscribed
const pushBusy = ref(false)
const support = pushSupport()
const permission = ref(typeof Notification !== 'undefined' ? Notification.permission : 'default')

async function loadPush() {
  try {
    push.value = await api.get('/push')
    pushOn.value = !!(await currentSubscription())
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}
watch(tab, (name) => name === 'notifications' && loadPush(), { immediate: true })

async function togglePush(on, checkbox) {
  pushBusy.value = true
  try {
    if (on) {
      await enablePush(push.value.public_key)
      pushOn.value = true
      toasts.show(t('notify.enabled'))
    } else {
      await disablePush()
      pushOn.value = false
    }
    push.value = await api.get('/push')
  } catch (e) {
    pushOn.value = !!(await currentSubscription())
    if (e.code === 'denied') toasts.error(t('notify.denied'))
    else if (e.code !== 'default') toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    permission.value = typeof Notification !== 'undefined' ? Notification.permission : 'default'
    pushBusy.value = false
    // The checkbox shows the real state (e.g. off again when the browser blocked notifications).
    if (checkbox) checkbox.checked = pushOn.value
  }
}

async function testPush() {
  pushBusy.value = true
  try {
    await sendTestPush()
    toasts.show(t('notify.testSent'))
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    pushBusy.value = false
  }
}

async function setAccountNotify(account, on) {
  try {
    await session.updateAccount(account.id, { notify: on })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}

const cronLastRun = computed(() => (push.value?.cron.last_run ? new Date(push.value.cron.last_run).toLocaleString(locale(), { dateStyle: 'medium', timeStyle: 'short' }) : null))

// ---- security: two-factor authentication
const sec = ref(null)            // GET /security
const setup = ref(null)          // { secret, uri, qr } while setting up
const secCode = ref('')
const secBusy = ref(false)
const recoveryCodes = ref(null)  // shown once after enabling / regenerating
const secAction = ref(null)      // 'disable' | 'recovery' while asking for a code

async function loadSecurity() {
  try {
    sec.value = await api.get('/security')
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}
watch(tab, (name) => name === 'security' && loadSecurity(), { immediate: true })

async function startSetup() {
  secBusy.value = true
  try {
    const data = await api.post('/security/2fa/setup')
    const { renderSVG } = await import('uqr')
    setup.value = { ...data, qr: renderSVG(data.uri, { border: 2 }) }
    secCode.value = ''
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  } finally {
    secBusy.value = false
  }
}

async function secCall(fn) {
  secBusy.value = true
  try {
    return await fn()
  } catch (e) {
    toasts.error(e.status === 400 ? t('security.wrongCode') : t('toast.failed', { msg: e.message }))
    secCode.value = ''
    return null
  } finally {
    secBusy.value = false
  }
}

async function enableTwoFactor() {
  const res = await secCall(() => api.post('/security/2fa/enable', { code: secCode.value }))
  if (!res) return
  recoveryCodes.value = res.recovery_codes
  sec.value = res.status
  setup.value = null
  secCode.value = ''
  session.user.two_factor = true
}

async function confirmSecAction() {
  if (secAction.value === 'disable') {
    const res = await secCall(() => api.post('/security/2fa/disable', { code: secCode.value }))
    if (!res) return
    sec.value = res
    session.user.two_factor = false
    toasts.show(t('security.disabled'))
  } else {
    const res = await secCall(() => api.post('/security/2fa/recovery', { code: secCode.value }))
    if (!res) return
    recoveryCodes.value = res.recovery_codes
    await loadSecurity()
  }
  secAction.value = null
  secCode.value = ''
}

async function forgetDevices() {
  if (!confirm(t('security.forgetConfirm'))) return
  const res = await secCall(() => api.post('/security/devices/forget'))
  if (res) sec.value = res
}

function copyCodes() {
  navigator.clipboard?.writeText(recoveryCodes.value.join('\n')).then(() => toasts.show(t('security.copied')))
}
function downloadCodes() {
  const text = `${t('security.fileTitle')}\n${session.user?.login_email ?? ''}\n\n${recoveryCodes.value.join('\n')}\n`
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }))
  a.download = 'postvak-herstelcodes.txt'
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}

/** "Chrome op Windows" from a user agent string. */
function deviceName(ua) {
  if (!ua) return t('security.unknownDevice')
  const browser = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : ua.split(/[ /]/)[0]
  const os = /iPhone/.test(ua) ? 'iPhone' : /iPad/.test(ua) ? 'iPad' : /Android/.test(ua) ? 'Android' : /Windows/.test(ua) ? 'Windows' : /Mac OS X/.test(ua) ? 'Mac' : /Linux/.test(ua) ? 'Linux' : ''
  return os ? t('security.deviceOn', { browser, os }) : browser
}
const shortDate = (s) => (s ? new Date(s.replace(' ', 'T')).toLocaleDateString(locale(), { day: 'numeric', month: 'short', year: 'numeric' }) : '–')
const secretGroups = computed(() => (setup.value?.secret ?? '').match(/.{1,4}/g)?.join(' ') ?? '')

async function logout() {
  await session.logout()
  router.push('/login')
}

const pollOptions = [30, 60, 120, 300, 600]
</script>

<template>
  <div class="pt-safe pb-safe mx-auto flex h-full max-w-2xl flex-col">
    <header class="flex items-center gap-2 px-3 py-3">
      <button class="rounded-lg p-2 text-muted hover:bg-sunken hover:text-ink" :aria-label="t('actions.back')" @click="router.push('/')">
        <AppIcon name="back" />
      </button>
      <h1 class="text-xl font-semibold">{{ t('settings.title') }}</h1>
    </header>

    <nav class="flex flex-wrap gap-1 gap-y-1.5 px-4" role="tablist">
      <router-link
        v-for="name in tabs"
        :key="name"
        :to="`/settings/${name}`"
        role="tab"
        class="shrink-0 rounded-full px-4 py-2 text-sm"
        :class="tab === name ? 'bg-ink font-medium text-canvas' : 'text-muted hover:bg-sunken'"
      >{{ t(`settings.${name}`) }}</router-link>
    </nav>

    <main class="min-h-0 flex-1 overflow-y-auto px-4 py-6">
      <!-- General -->
      <div v-if="tab === 'general'" class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
        <label class="flex items-center justify-between gap-4 px-4 py-3">
          <span>{{ t('settings.language') }}</span>
          <select :value="session.settings.language ?? 'auto'" class="min-w-0 rounded-lg bg-sunken px-3 py-1.5" @change="set('language', $event.target.value)">
            <option value="auto">{{ t('settings.languageAuto') }}</option>
            <option v-for="(name, code) in languageNames" :key="code" :value="code">{{ name }}</option>
          </select>
        </label>
        <label class="flex items-center justify-between gap-4 px-4 py-3">
          <span>{{ t('settings.pollSeconds') }}</span>
          <select :value="session.settings.poll_seconds" class="rounded-lg bg-sunken px-3 py-1.5" @change="set('poll_seconds', Number($event.target.value))">
            <option v-for="s in pollOptions" :key="s" :value="s">
              {{ s < 120 ? t('settings.seconds', { n: s }) : t('settings.minutes', { n: s / 60 }) }}
            </option>
          </select>
        </label>
        <label v-if="session.accounts.length > 1" class="flex items-center justify-between gap-4 px-4 py-3">
          <span>{{ t('settings.unifiedInbox') }}</span>
          <input type="checkbox" class="size-5 accent-accent" :checked="session.settings.unified_inbox" @change="set('unified_inbox', $event.target.checked)" />
        </label>
        <label class="flex items-center justify-between gap-4 px-4 py-3">
          <span>
            {{ t('settings.conversations') }}
            <span class="block text-sm text-muted">{{ t('settings.conversationsHint') }}</span>
          </span>
          <input type="checkbox" class="size-5 shrink-0 accent-accent" :checked="session.settings.conversations !== false" @change="set('conversations', $event.target.checked)" />
        </label>
        <label class="flex items-center justify-between gap-4 px-4 py-3">
          <span>
            {{ t('settings.undoSend') }}
            <span class="block text-sm text-muted">{{ t('settings.undoSendHint') }}</span>
          </span>
          <select :value="session.settings.undo_send ?? 5" class="shrink-0 rounded-lg bg-sunken px-3 py-1.5" @change="set('undo_send', Number($event.target.value))">
            <option :value="0">{{ t('settings.undoSendOff') }}</option>
            <option v-for="n in [5, 10, 20, 30]" :key="n" :value="n">{{ t('settings.seconds', { n }) }}</option>
          </select>
        </label>
        <label class="flex items-center justify-between gap-4 px-4 py-3">
          <span>{{ t('settings.markReadOnOpen') }}</span>
          <input type="checkbox" class="size-5 accent-accent" :checked="session.settings.mark_read_on_open" @change="set('mark_read_on_open', $event.target.checked)" />
        </label>
      </div>
      <div v-if="tab === 'general'" class="mt-6 flex items-center justify-between gap-4 rounded-2xl bg-surface px-4 py-3 ring-1 ring-line">
        <span class="min-w-0 truncate text-muted">{{ session.user?.login_email }}</span>
        <button class="flex shrink-0 items-center gap-2 rounded-lg px-3 py-1.5 font-medium text-danger hover:bg-danger/10" @click="logout">
          <AppIcon name="logout" :size="18" />
          {{ t('actions.logout') }}
        </button>
      </div>

      <!-- Swipe -->
      <div v-else-if="tab === 'swipe'" class="space-y-6">
        <p class="text-muted">{{ t('settings.swipeIntro') }}</p>

        <!-- Live preview of both directions -->
        <div class="flex h-14 overflow-hidden rounded-2xl text-sm font-medium text-white ring-1 ring-line">
          <div class="flex flex-1 items-center gap-2 px-4" :style="{ background: actionColor[session.settings.swipe_right] }">
            <AppIcon :name="session.settings.swipe_right" />
            {{ t(`actions.${session.settings.swipe_right}`) }}
          </div>
          <div class="flex flex-1 items-center justify-end gap-2 px-4" :style="{ background: actionColor[session.settings.swipe_left] }">
            {{ t(`actions.${session.settings.swipe_left}`) }}
            <AppIcon :name="session.settings.swipe_left" />
          </div>
        </div>

        <fieldset v-for="key in ['swipe_right', 'swipe_left']" :key="key">
          <legend class="mb-2 font-medium">{{ key === 'swipe_left' ? t('settings.swipeLeft') : t('settings.swipeRight') }}</legend>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="a in swipeActions"
              :key="a"
              type="button"
              class="flex items-center gap-2 rounded-full px-4 py-2 text-sm ring-1 transition"
              :class="session.settings[key] === a ? 'font-medium text-white ring-transparent' : 'bg-surface ring-line hover:bg-sunken'"
              :style="session.settings[key] === a ? { background: actionColor[a] } : {}"
              :aria-pressed="session.settings[key] === a"
              @click="set(key, a)"
            >
              <AppIcon :name="a" :size="17" />
              {{ t(`actions.${a}`) }}
            </button>
          </div>
        </fieldset>

        <div class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
          <label class="flex items-center justify-between gap-4 px-4 py-3">
            <span>{{ t('settings.undoSeconds') }}</span>
            <select :value="session.settings.undo_seconds" class="rounded-lg bg-sunken px-3 py-1.5" @change="set('undo_seconds', Number($event.target.value))">
              <option v-for="s in [0, 3, 5, 10, 20]" :key="s" :value="s">{{ s === 0 ? t('actions.none') : t('settings.seconds', { n: s }) }}</option>
            </select>
          </label>
          <label class="flex items-center justify-between gap-4 px-4 py-3">
            <span>{{ t('settings.confirmDelete') }}</span>
            <input type="checkbox" class="size-5 accent-accent" :checked="session.settings.confirm_delete" @change="set('confirm_delete', $event.target.checked)" />
          </label>
        </div>
      </div>

      <!-- Notifications -->
      <div v-else-if="tab === 'notifications'" class="space-y-6">
        <p class="text-muted">{{ t('notify.intro') }}</p>

        <div v-if="!support.supported || support.needsHomeScreen" class="rounded-2xl bg-sunken px-4 py-3 text-sm">
          <p v-if="support.needsHomeScreen">{{ t('notify.iosHomeScreen') }}</p>
          <p v-else>{{ t('notify.unsupported') }}</p>
        </div>

        <template v-else>
          <div class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
            <label class="flex items-center justify-between gap-4 px-4 py-3">
              <span>
                {{ t('notify.thisDevice') }}
                <span class="block text-sm text-muted">{{ !push ? '…' : push.devices === 0 ? t('notify.devicesNone') : push.devices === 1 ? t('notify.devicesOne') : t('notify.devices', { n: push.devices }) }}</span>
              </span>
              <input
                type="checkbox"
                class="size-5 shrink-0 accent-accent"
                :checked="pushOn"
                :disabled="pushBusy || !push"
                @change="togglePush($event.target.checked, $event.target)"
              />
            </label>
            <div v-if="pushOn" class="flex items-center justify-between gap-4 px-4 py-3">
              <span class="text-sm text-muted">{{ t('notify.testHint') }}</span>
              <button class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium text-accent ring-1 ring-line hover:bg-accent-soft disabled:opacity-60" :disabled="pushBusy" @click="testPush">
                {{ t('notify.test') }}
              </button>
            </div>
          </div>
          <p v-if="permission === 'denied'" class="rounded-2xl bg-danger/10 px-4 py-3 text-sm text-danger">{{ t('notify.denied') }}</p>
        </template>

        <section v-if="session.accounts.length > 1">
          <h2 class="font-medium">{{ t('notify.accountsTitle') }}</h2>
          <div class="mt-3 divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
            <label v-for="a in session.accounts" :key="a.id" class="flex items-center justify-between gap-4 px-4 py-3">
              <span class="flex min-w-0 items-center gap-2">
                <span class="size-2.5 shrink-0 rounded-full" :style="{ background: a.color || 'var(--color-accent)' }" />
                <span class="truncate">{{ a.display_name ? `${a.display_name} (${a.email})` : a.email }}</span>
              </span>
              <input type="checkbox" class="size-5 shrink-0 accent-accent" :checked="a.notify !== false" @change="setAccountNotify(a, $event.target.checked)" />
            </label>
          </div>
        </section>

        <div v-if="push && !push.cron.ok" class="rounded-2xl bg-flag/15 px-4 py-3 text-sm">
          <p class="font-medium">{{ t('notify.cronMissingTitle') }}</p>
          <p class="mt-1">{{ cronLastRun ? t('notify.cronStale', { date: cronLastRun }) : t('notify.cronNever') }}</p>
          <p class="mt-1 text-muted">{{ session.user?.is_admin ? t('notify.cronAdmin') : t('notify.cronUser') }}</p>
        </div>
        <p v-else-if="push" class="text-sm text-muted">{{ t('notify.cronOk', { date: cronLastRun }) }}</p>
      </div>

      <!-- Privacy -->
      <div v-else-if="tab === 'privacy'" class="space-y-6">
        <div class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
        <label class="flex items-center justify-between gap-4 px-4 py-3">
          <span>{{ t('settings.showImages') }}</span>
          <select :value="session.settings.show_images" class="rounded-lg bg-sunken px-3 py-1.5" @change="set('show_images', $event.target.value)">
            <option value="ask">{{ t('settings.imagesAsk') }}</option>
            <option value="always">{{ t('settings.imagesAlways') }}</option>
            <option value="never">{{ t('settings.imagesNever') }}</option>
          </select>
        </label>
        </div>

        <section>
          <h2 class="font-medium">{{ t('settings.trustedTitle') }}</h2>
          <p class="mt-1 text-sm text-muted">{{ t('settings.trustedIntro') }}</p>

          <form class="mt-3 flex gap-2" @submit.prevent="addTrusted">
            <input
              v-model="trustedInput"
              class="min-w-0 flex-1 rounded-lg border border-line bg-surface px-3 py-2 outline-none focus:border-accent"
              :placeholder="t('settings.trustedAddPlaceholder')"
            />
            <button class="rounded-lg bg-accent px-4 py-2 font-medium text-white disabled:opacity-60" :disabled="!trustedInput.trim()">
              {{ t('settings.add') }}
            </button>
          </form>

          <ul v-if="trusted.length" class="mt-3 divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
            <li v-for="item in trusted" :key="item.id" class="flex items-center gap-3 px-4 py-2.5">
              <span class="min-w-0 flex-1 truncate">{{ item.kind === 'domain' ? '@' + item.value : item.value }}</span>
              <span v-if="item.kind === 'domain'" class="rounded-full bg-sunken px-2 py-0.5 text-xs text-muted">{{ t('settings.trustedDomainLabel') }}</span>
              <button class="rounded-lg px-2 py-1 text-sm text-danger hover:bg-danger/10" @click="removeTrusted(item)">{{ t('settings.remove') }}</button>
            </li>
          </ul>
          <p v-else class="mt-3 text-sm text-muted">{{ t('settings.trustedEmpty') }}</p>
        </section>
      </div>

      <!-- Security: two-factor authentication -->
      <div v-else-if="tab === 'security'" class="space-y-6">
        <p class="text-muted">{{ t('security.intro') }}</p>

        <!-- Recovery codes, shown once -->
        <section v-if="recoveryCodes" class="rounded-2xl bg-surface p-4 ring-2 ring-accent">
          <h2 class="font-semibold">{{ t('security.codesTitle') }}</h2>
          <p class="mt-1 text-sm text-muted">{{ t('security.codesIntro') }}</p>
          <ul class="mt-3 grid grid-cols-2 gap-2 font-mono text-lg tabular-nums">
            <li v-for="c in recoveryCodes" :key="c" class="rounded-lg bg-sunken px-3 py-1.5 text-center">{{ c }}</li>
          </ul>
          <div class="mt-4 flex flex-wrap gap-2">
            <button class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-line hover:bg-sunken" @click="copyCodes">{{ t('security.copy') }}</button>
            <button class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-line hover:bg-sunken" @click="downloadCodes">{{ t('security.download') }}</button>
            <button class="ml-auto rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white" @click="recoveryCodes = null">{{ t('security.saved') }}</button>
          </div>
        </section>

        <!-- Setup -->
        <section v-else-if="setup" class="rounded-2xl bg-surface p-4 ring-1 ring-line">
          <h2 class="font-semibold">{{ t('security.setupTitle') }}</h2>
          <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-muted">
            <li>{{ t('security.step1') }}</li>
            <li>{{ t('security.step2') }}</li>
            <li>{{ t('security.step3') }}</li>
          </ol>
          <div class="mt-4 flex flex-col items-center gap-3 sm:flex-row sm:items-start">
            <div class="size-48 shrink-0 overflow-hidden rounded-xl bg-white p-1 ring-1 ring-line" v-html="setup.qr" />
            <div class="min-w-0 space-y-2 text-sm">
              <p class="text-muted">{{ t('security.cantScan') }}</p>
              <p class="rounded-lg bg-sunken px-3 py-2 font-mono break-all select-all">{{ secretGroups }}</p>
              <a :href="setup.uri" class="inline-block font-medium text-accent sm:hidden">{{ t('security.openApp') }}</a>
            </div>
          </div>
          <form class="mt-4 flex gap-2" @submit.prevent="enableTwoFactor">
            <input
              v-model="secCode"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength="7"
              placeholder="123456"
              :aria-label="t('security.codeLabel')"
              class="w-36 rounded-lg border border-line bg-surface px-3 py-2 text-center text-lg tracking-widest tabular-nums outline-none focus:border-accent"
            />
            <button class="rounded-lg bg-accent px-4 py-2 font-medium text-white disabled:opacity-60" :disabled="secBusy || secCode.replace(/\D/g, '').length !== 6">{{ t('security.enable') }}</button>
            <button type="button" class="rounded-lg px-3 py-2 text-muted hover:bg-sunken" @click="setup = null">{{ t('settings.cancel') }}</button>
          </form>
        </section>

        <!-- Status -->
        <section v-else-if="sec" class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
          <div class="flex items-center gap-3 px-4 py-3">
            <span class="size-2.5 shrink-0 rounded-full" :class="sec.enabled ? 'bg-archive' : 'bg-muted'" />
            <span class="min-w-0 flex-1">
              {{ sec.enabled ? t('security.on') : t('security.off') }}
              <span v-if="sec.enabled" class="block text-sm text-muted">{{ t('security.onSince', { date: shortDate(sec.enabled_at), n: sec.recovery_left }) }}</span>
            </span>
            <button v-if="!sec.enabled" class="shrink-0 rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-60" :disabled="secBusy" @click="startSetup">{{ t('security.setup') }}</button>
          </div>
          <template v-if="sec.enabled">
            <div v-if="!secAction" class="flex flex-wrap gap-2 px-4 py-3">
              <button class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-line hover:bg-sunken" @click="secAction = 'recovery'">{{ t('security.newCodes') }}</button>
              <button class="rounded-lg px-3 py-1.5 text-sm font-medium text-danger ring-1 ring-line hover:bg-danger/10" @click="secAction = 'disable'">{{ t('security.disable') }}</button>
            </div>
            <form v-else class="space-y-2 px-4 py-3" @submit.prevent="confirmSecAction">
              <p class="text-sm text-muted">{{ secAction === 'disable' ? t('security.disableHint') : t('security.newCodesHint') }}</p>
              <div class="flex gap-2">
                <input v-model="secCode" :inputmode="secAction === 'disable' ? 'text' : 'numeric'" autocomplete="one-time-code" maxlength="12" class="w-40 rounded-lg border border-line bg-surface px-3 py-2 text-center tracking-widest tabular-nums outline-none focus:border-accent" :aria-label="t('security.codeLabel')" />
                <button class="rounded-lg px-4 py-2 font-medium text-white disabled:opacity-60" :class="secAction === 'disable' ? 'bg-danger' : 'bg-accent'" :disabled="secBusy || !secCode.trim()">{{ t('security.confirm') }}</button>
                <button type="button" class="rounded-lg px-3 py-2 text-muted hover:bg-sunken" @click="secAction = null; secCode = ''">{{ t('settings.cancel') }}</button>
              </div>
            </form>
          </template>
        </section>

        <section v-if="sec?.enabled && !setup">
          <h2 class="font-medium">{{ t('security.devicesTitle') }}</h2>
          <p class="mt-1 text-sm text-muted">{{ t('security.devicesIntro') }}</p>
          <ul v-if="sec.devices.length" class="mt-3 divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
            <li v-for="d in sec.devices" :key="d.id" class="flex items-center gap-3 px-4 py-2.5">
              <span class="min-w-0 flex-1 truncate">{{ deviceName(d.user_agent) }}<span v-if="d.current" class="ml-2 rounded-full bg-accent-soft px-2 py-0.5 text-xs text-accent">{{ t('security.thisDevice') }}</span></span>
              <span class="shrink-0 text-sm text-muted">{{ shortDate(d.last_used_at || d.created_at) }}</span>
            </li>
          </ul>
          <p v-else class="mt-3 text-sm text-muted">{{ t('security.noDevices') }}</p>
          <button v-if="sec.devices.length" class="mt-3 text-sm font-medium text-danger" @click="forgetDevices">{{ t('security.forget') }}</button>
        </section>
      </div>

      <!-- Calendar and tasks: calendars, linking phones (CalDAV) -->
      <CalendarSettings v-else-if="tab === 'calendar'" />
      <RulesSettings v-else-if="tab === 'rules'" />
      <AppearanceSettings v-else-if="tab === 'appearance'" />

      <!-- Folders -->
      <div v-else-if="tab === 'folders'" class="space-y-4">
        <label v-if="session.accounts.length > 1" class="flex items-center justify-between gap-4">
          <span class="text-muted">{{ t('settings.foldersOf') }}</span>
          <select v-model="folderAccountId" class="rounded-lg bg-sunken px-3 py-1.5">
            <option v-for="a in session.accounts" :key="a.id" :value="a.id">{{ a.email }}</option>
          </select>
        </label>

        <form class="flex flex-wrap gap-2" @submit.prevent="createFolder">
          <input v-model="newFolder" class="min-w-0 flex-1 rounded-lg border border-line bg-surface px-3 py-2 outline-none focus:border-accent" :placeholder="t('folderPicker.newName')" />
          <select v-model="newFolderParent" class="rounded-lg bg-sunken px-3 py-2" :title="t('settings.folderParent')">
            <option value="">{{ t('settings.folderTopLevel') }}</option>
            <option v-for="f in editableFolders" :key="f.path" :value="f.path">{{ t('settings.folderUnder', { name: folderLabel(f) }) }}</option>
          </select>
          <button class="rounded-lg bg-accent px-4 py-2 font-medium text-white disabled:opacity-60" :disabled="!newFolder.trim() || folderBusy">{{ t('settings.add') }}</button>
        </form>

        <ul class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
          <li v-for="f in folderList" :key="f.path" class="flex items-center gap-3 px-4 py-2.5">
            <AppIcon :name="folderIcon(f)" :size="18" class="shrink-0 text-muted" />
            <template v-if="renaming === f.path">
              <form class="flex min-w-0 flex-1 gap-2" @submit.prevent="renameFolder(f)">
                <input v-model="renameValue" class="min-w-0 flex-1 rounded-lg border border-line bg-surface px-2 py-1 outline-none focus:border-accent" autofocus />
                <button class="rounded-lg bg-accent px-3 py-1 text-sm font-medium text-white" :disabled="folderBusy">{{ t('settings.save') }}</button>
                <button type="button" class="rounded-lg px-2 py-1 text-sm hover:bg-sunken" @click="renaming = null">{{ t('settings.cancel') }}</button>
              </form>
            </template>
            <template v-else>
              <span class="min-w-0 flex-1 truncate">{{ folderLabel(f) }}</span>
              <span v-if="f.total !== null" class="shrink-0 text-sm text-muted tabular-nums">{{ f.total }}</span>
              <template v-if="!f.role">
                <button class="rounded-lg px-2 py-1 text-sm hover:bg-sunken" @click="startRename(f)">{{ t('settings.rename') }}</button>
                <button class="rounded-lg px-2 py-1 text-sm text-danger hover:bg-danger/10" @click="deleteFolder(f)">{{ t('settings.remove') }}</button>
              </template>
            </template>
          </li>
        </ul>
        <p class="text-sm text-muted">{{ t('settings.foldersHint') }}</p>
      </div>

      <!-- Accounts -->
      <div v-else class="space-y-3">
        <div v-for="a in session.accounts" :key="a.id" class="rounded-2xl bg-surface ring-1 ring-line">
          <div class="flex items-center gap-3 px-4 py-3">
            <span class="size-3 shrink-0 rounded-full" :style="{ background: a.color || 'var(--color-accent)' }" />
            <div class="min-w-0 flex-1">
              <p class="truncate font-medium">{{ a.display_name || a.email }}</p>
              <p class="truncate text-sm text-muted">{{ a.email }}<template v-if="a.is_login">, {{ t('settings.loginAccount').toLowerCase() }}</template></p>
            </div>
            <button class="rounded-lg px-3 py-1.5 text-sm hover:bg-sunken" @click="editing = editing === a.id ? null : a.id">
              {{ editing === a.id ? t('settings.cancel') : t('settings.edit') }}
            </button>
          </div>
          <div v-if="editing === a.id" class="border-t border-line px-4 py-4">
            <AccountForm :account="a" @done="editing = null" />
            <button v-if="!a.is_login" class="mt-4 text-sm text-danger hover:underline" @click="remove(a)">{{ t('settings.removeAccount') }}</button>
          </div>
        </div>

        <div v-if="editing === 'new'" class="rounded-2xl bg-surface px-4 py-4 ring-1 ring-line">
          <h2 class="mb-4 font-medium">{{ t('settings.addAccount') }}</h2>
          <AccountForm @done="editing = null" />
        </div>
        <button v-else class="flex w-full items-center justify-center gap-2 rounded-2xl border border-dashed border-line px-4 py-4 text-muted hover:bg-sunken hover:text-ink" @click="editing = 'new'">
          <AppIcon name="plus" />
          {{ t('settings.addAccount') }}
        </button>
      </div>
    </main>
  </div>
</template>
