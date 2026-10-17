<script setup>
import { ref, reactive } from 'vue'
import { useSession } from '../stores/session.js'
import { useToasts } from '../stores/toasts.js'
import { t } from '../i18n/index.js'

/** Add a new account (account = null) or edit an existing one. */
const props = defineProps({ account: { type: Object, default: null } })
const emit = defineEmits(['done'])

const session = useSession()
const toasts = useToasts()
const isNew = !props.account

const form = reactive({
  email: props.account?.email ?? '',
  password: '',
  display_name: props.account?.display_name ?? '',
  color: props.account?.color ?? '#3646D9',
  signature_html: props.account?.signature_html ?? '',
  imap_host: props.account?.imap_host ?? '',
  imap_port: props.account?.imap_port ?? '',
  smtp_host: props.account?.smtp_host ?? '',
  smtp_port: props.account?.smtp_port ?? '',
  archive_folder: props.account?.archive_folder ?? '',
  trash_folder: props.account?.trash_folder ?? '',
})
const showServer = ref(false)
const busy = ref(false)
const error = ref('')

async function save() {
  busy.value = true
  error.value = ''
  try {
    const data = Object.fromEntries(Object.entries(form).filter(([k, v]) => v !== '' || ['display_name', 'signature_html', 'archive_folder', 'trash_folder'].includes(k)))
    if (!showServer.value) {
      for (const k of ['imap_host', 'imap_port', 'smtp_host', 'smtp_port']) delete data[k]
    }
    if (isNew) await session.addAccount(data)
    else {
      delete data.email
      await session.updateAccount(props.account.id, data)
    }
    toasts.show(t('toast.saved'))
    emit('done')
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

const field = 'mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 outline-none focus:border-accent'
</script>

<template>
  <form class="space-y-4" @submit.prevent="save">
    <div v-if="isNew">
      <label class="text-sm font-medium">{{ t('login.email') }}</label>
      <input v-model="form.email" type="email" required :class="field" />
    </div>
    <div>
      <label class="text-sm font-medium">{{ isNew ? t('login.password') : t('settings.newPassword') }}</label>
      <input v-model="form.password" type="password" :required="isNew" autocomplete="new-password" :class="field" />
      <p v-if="!isNew" class="mt-1 text-sm text-muted">{{ t('settings.newPasswordHint') }}</p>
    </div>
    <div class="grid grid-cols-[1fr_auto] gap-3">
      <div>
        <label class="text-sm font-medium">{{ t('settings.displayName') }}</label>
        <input v-model="form.display_name" :class="field" />
      </div>
      <div>
        <label class="text-sm font-medium">{{ t('settings.color') }}</label>
        <input v-model="form.color" type="color" class="mt-1 block h-[42px] w-14 cursor-pointer rounded-lg border border-line bg-surface p-1" />
      </div>
    </div>
    <div v-if="!isNew">
      <label class="text-sm font-medium">{{ t('settings.signature') }}</label>
      <textarea v-model="form.signature_html" rows="3" :class="field" />
    </div>

    <button type="button" class="text-sm text-muted hover:text-ink" @click="showServer = !showServer">{{ t('login.advanced') }}</button>
    <div v-if="showServer" class="grid grid-cols-[1fr_6rem] gap-2 text-sm">
      <input v-model="form.imap_host" placeholder="IMAP-server" :class="field" />
      <input v-model="form.imap_port" placeholder="993" inputmode="numeric" :class="field" />
      <input v-model="form.smtp_host" placeholder="SMTP-server" :class="field" />
      <input v-model="form.smtp_port" placeholder="465" inputmode="numeric" :class="field" />
      <template v-if="!isNew">
        <input v-model="form.archive_folder" :placeholder="`${t('folders.archive')} (automatisch)`" class="col-span-2" :class="field" />
        <input v-model="form.trash_folder" :placeholder="`${t('folders.trash')} (automatisch)`" class="col-span-2" :class="field" />
      </template>
    </div>

    <p v-if="error" class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger" role="alert">{{ error }}</p>

    <div class="flex justify-end gap-2">
      <button type="button" class="rounded-lg px-4 py-2 hover:bg-sunken" @click="emit('done')">{{ t('settings.cancel') }}</button>
      <button type="submit" :disabled="busy" class="rounded-lg bg-accent px-4 py-2 font-medium text-white disabled:opacity-60">
        {{ busy ? t('settings.adding') : isNew ? t('settings.add') : t('settings.save') }}
      </button>
    </div>
  </form>
</template>
