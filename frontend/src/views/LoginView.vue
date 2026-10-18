<script setup>
import { ref, nextTick, onMounted } from 'vue'
import { api } from '../api/client.js'
import { useRouter } from 'vue-router'
import { useSession } from '../stores/session.js'
import { t } from '../i18n/index.js'

const session = useSession()
const router = useRouter()

const email = ref('')
const password = ref('')
const advanced = ref(false)
const customServers = ref(false) // only when the admin allows own server settings
const stay = ref(true)
onMounted(async () => {
  try {
    customServers.value = !!(await api.get('/auth/options')).custom_servers
  } catch {}
})
const server = ref({ imap_host: '', imap_port: '', smtp_host: '', smtp_port: '' })
const busy = ref(false)
const error = ref('')

// Two-factor step
const step = ref('password') // password | code
const code = ref('')
const useRecovery = ref(false)
const remember = ref(true)
const codeInput = ref(null)

async function submit() {
  error.value = ''
  busy.value = true
  try {
    const result = await session.login({ email: email.value, password: password.value, remember: stay.value, ...(advanced.value && customServers.value ? server.value : {}) })
    if (result.twoFactor) {
      step.value = 'code'
      password.value = ''
      await nextTick()
      codeInput.value?.focus()
      return
    }
    router.replace('/')
  } catch (e) {
    error.value = e.status === 0 ? t('errors.offline') : e.message
  } finally {
    busy.value = false
  }
}

async function submitCode() {
  error.value = ''
  busy.value = true
  try {
    await session.verifyTwoFactor(code.value.trim(), remember.value)
    router.replace('/')
  } catch (e) {
    code.value = ''
    if (e.status === 401 || e.status === 429) {
      // Expired or too many tries: start again with the password.
      step.value = 'password'
      error.value = e.status === 429 ? t('login.tooManyCodes') : t('login.codeExpired')
    } else {
      error.value = e.status === 0 ? t('errors.offline') : useRecovery.value ? t('login.wrongRecovery') : t('login.wrongCode')
      codeInput.value?.focus()
    }
  } finally {
    busy.value = false
  }
}

function toggleRecovery() {
  useRecovery.value = !useRecovery.value
  code.value = ''
  error.value = ''
  nextTick(() => codeInput.value?.focus())
}

// A full 6-digit code from the app: send right away.
function onCodeInput() {
  if (!useRecovery.value && /^\d{6}$/.test(code.value.replace(/\s/g, '')) && !busy.value) submitCode()
}
</script>

<template>
  <main class="pt-safe pb-safe flex min-h-full items-center justify-center p-5">
    <!-- Step 2: code from the authenticator app -->
    <form v-if="step === 'code'" class="w-full max-w-sm" @submit.prevent="submitCode">
      <img src="/icon.svg" alt="" class="mb-6 size-14" />
      <h1 class="text-2xl font-semibold tracking-tight">{{ t('login.codeTitle') }}</h1>
      <p class="mt-2 text-muted">{{ useRecovery ? t('login.recoveryIntro') : t('login.codeIntro') }}</p>

      <input
        id="code"
        ref="codeInput"
        v-model="code"
        :inputmode="useRecovery ? 'text' : 'numeric'"
        :autocomplete="useRecovery ? 'off' : 'one-time-code'"
        :maxlength="useRecovery ? 12 : 7"
        :placeholder="useRecovery ? 'xxxx-xxxx' : '123456'"
        :aria-label="t('login.codeTitle')"
        class="mt-6 w-full rounded-xl border border-line bg-surface px-4 py-3 text-center text-2xl tracking-[0.3em] tabular-nums outline-none focus:border-accent"
        required
        @input="onCodeInput"
      />

      <label class="mt-4 flex items-center gap-2 text-sm">
        <input v-model="remember" type="checkbox" class="size-4 accent-accent" />
        {{ t('login.rememberDevice') }}
      </label>

      <p v-if="error" class="mt-4 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger" role="alert">{{ error }}</p>

      <button
        type="submit"
        :disabled="busy || !code.trim()"
        class="mt-6 w-full rounded-xl bg-accent px-4 py-3 font-medium text-white transition hover:brightness-110 disabled:opacity-60"
      >{{ busy ? t('login.busy') : t('login.verify') }}</button>
      <div class="mt-4 flex justify-between text-sm">
        <button type="button" class="text-muted hover:text-ink" @click="toggleRecovery">{{ useRecovery ? t('login.useApp') : t('login.useRecovery') }}</button>
        <button type="button" class="text-muted hover:text-ink" @click="step = 'password'; error = ''">{{ t('login.back') }}</button>
      </div>
    </form>

    <form v-else class="w-full max-w-sm" @submit.prevent="submit">
      <img src="/icon.svg" alt="" class="mb-6 size-14" />
      <h1 class="text-3xl font-semibold tracking-tight">{{ t('appName') }}</h1>
      <p class="mt-2 text-muted">{{ t('login.intro') }}</p>

      <label class="mt-8 block text-sm font-medium" for="email">{{ t('login.email') }}</label>
      <input
        id="email" v-model="email" type="email" autocomplete="username" required
        class="mt-1.5 w-full rounded-xl border border-line bg-surface px-4 py-3 outline-none focus:border-accent"
      />

      <label class="mt-4 block text-sm font-medium" for="password">{{ t('login.password') }}</label>
      <input
        id="password" v-model="password" type="password" autocomplete="current-password" required
        class="mt-1.5 w-full rounded-xl border border-line bg-surface px-4 py-3 outline-none focus:border-accent"
      />

      <label class="mt-4 flex items-center gap-2 text-sm">
        <input v-model="stay" type="checkbox" class="size-4 accent-accent" />
        {{ t('login.stay') }}
      </label>

      <button v-if="customServers" type="button" class="mt-3 text-sm text-muted hover:text-ink" @click="advanced = !advanced">
        {{ t('login.advanced') }}
      </button>
      <div v-if="advanced && customServers" class="mt-3 grid grid-cols-[1fr_6rem] gap-2 text-sm">
        <p class="col-span-2 text-muted">{{ t('login.advancedHint') }}</p>
        <input v-model="server.imap_host" placeholder="IMAP-server" class="rounded-lg border border-line bg-surface px-3 py-2" />
        <input v-model="server.imap_port" placeholder="993" inputmode="numeric" class="rounded-lg border border-line bg-surface px-3 py-2" />
        <input v-model="server.smtp_host" placeholder="SMTP-server" class="rounded-lg border border-line bg-surface px-3 py-2" />
        <input v-model="server.smtp_port" placeholder="465" inputmode="numeric" class="rounded-lg border border-line bg-surface px-3 py-2" />
      </div>

      <p v-if="error" class="mt-4 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger" role="alert">{{ error }}</p>

      <button
        type="submit"
        :disabled="busy"
        class="mt-6 w-full rounded-xl bg-accent px-4 py-3 font-medium text-white transition hover:brightness-110 disabled:opacity-60"
      >{{ busy ? t('login.busy') : t('login.submit') }}</button>
    </form>
  </main>
</template>
