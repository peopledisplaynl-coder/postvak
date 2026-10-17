<script setup>
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import AccountForm from '../components/AccountForm.vue'
import { useSession } from '../stores/session.js'
import { useToasts } from '../stores/toasts.js'
import { t } from '../i18n/index.js'

const route = useRoute()
const router = useRouter()
const session = useSession()
const toasts = useToasts()

const tabs = ['general', 'swipe', 'accounts']
const tab = computed(() => (tabs.includes(route.params.tab) ? route.params.tab : 'general'))
const editing = ref(null) // account id, 'new' or null

const swipeActions = ['archive', 'trash', 'read', 'flag', 'none']
const actionColor = { archive: 'var(--color-archive)', trash: 'var(--color-danger)', read: 'var(--color-read)', flag: 'var(--color-flag)', none: 'var(--color-muted)' }

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

    <nav class="flex gap-1 px-4" role="tablist">
      <router-link
        v-for="name in tabs"
        :key="name"
        :to="`/settings/${name}`"
        role="tab"
        class="rounded-full px-4 py-2 text-sm"
        :class="tab === name ? 'bg-ink font-medium text-canvas' : 'text-muted hover:bg-sunken'"
      >{{ t(`settings.${name}`) }}</router-link>
    </nav>

    <main class="min-h-0 flex-1 overflow-y-auto px-4 py-6">
      <!-- General -->
      <div v-if="tab === 'general'" class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
        <label class="flex items-center justify-between gap-4 px-4 py-3">
          <span>{{ t('settings.theme') }}</span>
          <select :value="session.settings.theme" class="rounded-lg bg-sunken px-3 py-1.5" @change="set('theme', $event.target.value)">
            <option value="system">{{ t('settings.themeSystem') }}</option>
            <option value="light">{{ t('settings.themeLight') }}</option>
            <option value="dark">{{ t('settings.themeDark') }}</option>
          </select>
        </label>
        <label class="flex items-center justify-between gap-4 px-4 py-3">
          <span>{{ t('settings.density') }}</span>
          <select :value="session.settings.density" class="rounded-lg bg-sunken px-3 py-1.5" @change="set('density', $event.target.value)">
            <option value="comfortable">{{ t('settings.comfortable') }}</option>
            <option value="compact">{{ t('settings.compact') }}</option>
          </select>
        </label>
        <label class="flex items-center justify-between gap-4 px-4 py-3">
          <span>{{ t('settings.showImages') }}</span>
          <select :value="session.settings.show_images" class="rounded-lg bg-sunken px-3 py-1.5" @change="set('show_images', $event.target.value)">
            <option value="ask">{{ t('settings.imagesAsk') }}</option>
            <option value="always">{{ t('settings.imagesAlways') }}</option>
            <option value="never">{{ t('settings.imagesNever') }}</option>
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
