<script setup>
import { ref, computed, nextTick, onMounted } from 'vue'
import AppIcon from './AppIcon.vue'
import { avatarColor } from '../format.js'
import { t } from '../i18n/index.js'
import { emptyDetails, contactInitials } from '../contacts.js'

/**
 * Edit form for a contact, laid out like Google Contacts: photo, names, work, e-mail addresses, phone numbers,
 * addresses, birthday, websites, groups and notes. Emits save({ details, notes, favorite, photo }) where photo is
 * a Blob (new), null (remove) or undefined (unchanged).
 */
const props = defineProps({
  contact: { type: Object, default: null }, // null = new contact
  groups: { type: Array, default: () => [] }, // existing groups, for suggestions
  saving: { type: Boolean, default: false },
  initial: { type: Object, default: null }, // prefill for a new contact, e.g. { email }
})
const emit = defineEmits(['save', 'cancel'])

const clone = (x) => JSON.parse(JSON.stringify(x))
const d = ref({ ...emptyDetails(), ...clone(props.contact?.details ?? {}) })
if (!props.contact && props.initial?.email) d.value.emails = [{ value: props.initial.email, type: 'other' }]
if (!props.contact && props.initial?.name) [d.value.given, d.value.family] = splitName(props.initial.name)
const notes = ref(props.contact?.notes ?? '')
const favorite = ref(props.contact?.favorite ?? false)
// Always at least one empty e-mail and phone row to type in.
if (!d.value.emails.length) d.value.emails.push({ value: '', type: 'home' })
if (!d.value.phones.length) d.value.phones.push({ value: '', type: 'mobile' })
const showWork = ref(!!(d.value.company || d.value.title || d.value.department))
const showMore = ref(!!(d.value.nickname))

function splitName(name) {
  const parts = String(name).trim().split(/\s+/)
  return parts.length < 2 ? [parts[0] ?? '', ''] : [parts.slice(0, -1).join(' '), parts.at(-1)]
}

const EMAIL_TYPES = ['home', 'work', 'other']
const PHONE_TYPES = ['mobile', 'home', 'work', 'main', 'other']
const ADDRESS_TYPES = ['home', 'work', 'other']

function addAddress() {
  d.value.addresses.push({ street: '', postal_code: '', city: '', region: '', country: '', type: 'home' })
}

// ---- birthday: a date, or day and month only ("--MM-DD", as phones store it without a year)
const noYear = computed(() => d.value.birthday.startsWith('--'))
const birthdayInput = computed({
  get: () => (noYear.value ? `2000-${d.value.birthday.slice(2)}` : d.value.birthday),
  set: (v) => (d.value.birthday = v && noYear.value ? `--${v.slice(5)}` : v),
})
/** Without a year: keep day and month. With a year again: the date field shows 2000, to be corrected. */
function setNoYear(on) {
  if (!d.value.birthday) return
  d.value.birthday = on ? `--${birthdayInput.value.slice(5)}` : birthdayInput.value
}

// ---- groups (Google: labels)
const groupInput = ref('')
function addGroup() {
  const g = groupInput.value.trim()
  if (g && !d.value.groups.some((x) => x.toLowerCase() === g.toLowerCase())) d.value.groups.push(g)
  groupInput.value = ''
}
const groupSuggestions = computed(() => props.groups.filter((g) => !d.value.groups.includes(g)))

// ---- photo: made square and small in the browser (256 px JPEG)
const photoFile = ref(null)
const photoChoice = ref(undefined) // Blob | null | undefined
const photoPreview = ref(props.contact?.photo_url ?? null)
async function pickPhoto(e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  if (!file) return
  const blob = await squareJpeg(file, 256)
  photoChoice.value = blob
  photoPreview.value = URL.createObjectURL(blob)
}
function removePhoto() {
  photoChoice.value = props.contact?.photo_url ? null : undefined
  photoPreview.value = null
}
async function squareJpeg(file, size) {
  const img = await createImageBitmap(file)
  const side = Math.min(img.width, img.height)
  const canvas = document.createElement('canvas')
  canvas.width = canvas.height = size
  canvas.getContext('2d').drawImage(img, (img.width - side) / 2, (img.height - side) / 2, side, side, 0, 0, size, size)
  return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85))
}

const displayName = computed(() => `${d.value.given} ${d.value.family}`.trim() || d.value.company || d.value.emails[0]?.value || '')
const canSave = computed(() => !!(displayName.value || d.value.phones.some((p) => p.value.trim())))

function submit() {
  addGroup()
  const details = clone(d.value)
  details.emails = details.emails.filter((e) => e.value.trim())
  details.phones = details.phones.filter((p) => p.value.trim())
  emit('save', { details, notes: notes.value, favorite: favorite.value, photo: photoChoice.value })
}

const first = ref(null)
onMounted(() => nextTick(() => first.value?.focus()))
const field = 'w-full min-w-0 rounded-lg border border-line bg-surface px-3 py-2 outline-none focus:border-accent'
const select = 'shrink-0 rounded-lg border border-line bg-surface px-2 py-2 text-sm outline-none focus:border-accent'
</script>

<template>
  <form class="mx-auto w-full max-w-xl space-y-6 px-5 py-4" @submit.prevent="submit">
    <h2 class="text-xl font-semibold">{{ contact ? t('contacts.edit') : t('contacts.new') }}</h2>

    <!-- Photo -->
    <div class="flex items-center gap-4">
      <span class="relative flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-full text-3xl font-semibold text-white" :style="{ background: avatarColor(d.emails[0]?.value || displayName) }">
        <img v-if="photoPreview" :src="photoPreview" alt="" class="size-full object-cover" />
        <template v-else>{{ contactInitials({ name: displayName, email: d.emails[0]?.value }) }}</template>
      </span>
      <div class="flex flex-wrap gap-2">
        <label class="cursor-pointer rounded-lg px-3 py-1.5 text-sm font-medium text-accent ring-1 ring-line hover:bg-sunken">
          {{ photoPreview ? t('contacts.photoChange') : t('contacts.photoAdd') }}
          <input type="file" accept="image/*" class="hidden" @change="pickPhoto" />
        </label>
        <button v-if="photoPreview" type="button" class="rounded-lg px-3 py-1.5 text-sm text-muted ring-1 ring-line hover:bg-sunken hover:text-danger" @click="removePhoto">{{ t('contacts.photoRemove') }}</button>
      </div>
    </div>

    <!-- Name -->
    <fieldset class="space-y-3">
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block"><span class="mb-1 block text-sm text-muted">{{ t('contacts.given') }}</span><input ref="first" v-model="d.given" maxlength="100" :class="field" /></label>
        <label class="block"><span class="mb-1 block text-sm text-muted">{{ t('contacts.family') }}</span><input v-model="d.family" maxlength="100" :class="field" /></label>
      </div>
      <label v-if="showMore" class="block"><span class="mb-1 block text-sm text-muted">{{ t('contacts.nickname') }}</span><input v-model="d.nickname" maxlength="100" :class="field" /></label>
      <div class="flex flex-wrap gap-3 text-sm">
        <button v-if="!showWork" type="button" class="text-accent hover:underline" @click="showWork = true">+ {{ t('contacts.addWork') }}</button>
        <button v-if="!showMore" type="button" class="text-accent hover:underline" @click="showMore = true">+ {{ t('contacts.nickname') }}</button>
      </div>
    </fieldset>

    <!-- Work -->
    <fieldset v-if="showWork" class="space-y-3">
      <legend class="mb-2 flex items-center gap-2 font-medium"><AppIcon name="contacts" :size="18" class="text-muted" />{{ t('contacts.work') }}</legend>
      <label class="block"><span class="mb-1 block text-sm text-muted">{{ t('contacts.company') }}</span><input v-model="d.company" maxlength="150" :class="field" /></label>
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block"><span class="mb-1 block text-sm text-muted">{{ t('contacts.jobTitle') }}</span><input v-model="d.title" maxlength="150" :class="field" /></label>
        <label class="block"><span class="mb-1 block text-sm text-muted">{{ t('contacts.department') }}</span><input v-model="d.department" maxlength="150" :class="field" /></label>
      </div>
    </fieldset>

    <!-- E-mail -->
    <fieldset class="space-y-2">
      <legend class="mb-2 flex items-center gap-2 font-medium"><AppIcon name="mail" :size="18" class="text-muted" />{{ t('contacts.emails') }}</legend>
      <div v-for="(e, i) in d.emails" :key="'e' + i" class="flex gap-2">
        <input v-model="e.value" type="email" maxlength="255" :placeholder="t('contacts.email')" :aria-label="t('contacts.email')" :class="field" />
        <select v-model="e.type" :class="select" :aria-label="t('contacts.type')">
          <option v-for="ty in EMAIL_TYPES" :key="ty" :value="ty">{{ t(`contacts.types.${ty}`) }}</option>
        </select>
        <button type="button" class="shrink-0 rounded-lg p-2 text-muted hover:bg-sunken hover:text-danger" :aria-label="t('settings.remove')" @click="d.emails.splice(i, 1)"><AppIcon name="close" :size="17" /></button>
      </div>
      <button v-if="d.emails.length < 10" type="button" class="text-sm text-accent hover:underline" @click="d.emails.push({ value: '', type: 'work' })">+ {{ t('contacts.addEmail') }}</button>
    </fieldset>

    <!-- Phone -->
    <fieldset class="space-y-2">
      <legend class="mb-2 flex items-center gap-2 font-medium"><AppIcon name="phone" :size="18" class="text-muted" />{{ t('contacts.phones') }}</legend>
      <div v-for="(p, i) in d.phones" :key="'p' + i" class="flex gap-2">
        <input v-model="p.value" type="tel" maxlength="64" :placeholder="t('contacts.phone')" :aria-label="t('contacts.phone')" :class="field" />
        <select v-model="p.type" :class="select" :aria-label="t('contacts.type')">
          <option v-for="ty in PHONE_TYPES" :key="ty" :value="ty">{{ t(`contacts.types.${ty}`) }}</option>
        </select>
        <button type="button" class="shrink-0 rounded-lg p-2 text-muted hover:bg-sunken hover:text-danger" :aria-label="t('settings.remove')" @click="d.phones.splice(i, 1)"><AppIcon name="close" :size="17" /></button>
      </div>
      <button v-if="d.phones.length < 10" type="button" class="text-sm text-accent hover:underline" @click="d.phones.push({ value: '', type: 'mobile' })">+ {{ t('contacts.addPhone') }}</button>
    </fieldset>

    <!-- Addresses -->
    <fieldset class="space-y-3">
      <legend class="mb-2 flex items-center gap-2 font-medium"><AppIcon name="pin" :size="18" class="text-muted" />{{ t('contacts.addresses') }}</legend>
      <div v-for="(a, i) in d.addresses" :key="'a' + i" class="space-y-2 rounded-xl p-3 ring-1 ring-line">
        <div class="flex gap-2">
          <textarea v-model="a.street" rows="2" maxlength="300" :placeholder="t('contacts.street')" :aria-label="t('contacts.street')" :class="field" />
          <div class="flex flex-col gap-2">
            <select v-model="a.type" :class="select" :aria-label="t('contacts.type')">
              <option v-for="ty in ADDRESS_TYPES" :key="ty" :value="ty">{{ t(`contacts.types.${ty}`) }}</option>
            </select>
            <button type="button" class="self-end rounded-lg p-2 text-muted hover:bg-sunken hover:text-danger" :aria-label="t('settings.remove')" @click="d.addresses.splice(i, 1)"><AppIcon name="close" :size="17" /></button>
          </div>
        </div>
        <div class="grid grid-cols-[8rem_1fr] gap-2">
          <input v-model="a.postal_code" maxlength="20" :placeholder="t('contacts.postalCode')" :aria-label="t('contacts.postalCode')" :class="field" />
          <input v-model="a.city" maxlength="100" :placeholder="t('contacts.city')" :aria-label="t('contacts.city')" :class="field" />
        </div>
        <div class="grid gap-2 sm:grid-cols-2">
          <input v-model="a.region" maxlength="100" :placeholder="t('contacts.region')" :aria-label="t('contacts.region')" :class="field" />
          <input v-model="a.country" maxlength="100" :placeholder="t('contacts.country')" :aria-label="t('contacts.country')" :class="field" />
        </div>
      </div>
      <button v-if="d.addresses.length < 10" type="button" class="text-sm text-accent hover:underline" @click="addAddress">+ {{ t('contacts.addAddress') }}</button>
    </fieldset>

    <!-- Birthday -->
    <fieldset>
      <legend class="mb-2 flex items-center gap-2 font-medium"><AppIcon name="calendar" :size="18" class="text-muted" />{{ t('contacts.birthday') }}</legend>
      <div class="flex flex-wrap items-center gap-3">
        <input v-model="birthdayInput" type="date" :class="field" class="w-auto" :aria-label="t('contacts.birthday')" />
        <label class="flex items-center gap-2 text-sm text-muted">
          <input type="checkbox" class="size-4 accent-[var(--color-accent)]" :checked="noYear" :disabled="!d.birthday" @change="setNoYear($event.target.checked)" />
          {{ t('contacts.noYear') }}
        </label>
        <button v-if="d.birthday" type="button" class="text-sm text-muted hover:text-danger" @click="d.birthday = ''">{{ t('settings.remove') }}</button>
      </div>
    </fieldset>

    <!-- Websites -->
    <fieldset class="space-y-2">
      <legend class="mb-2 flex items-center gap-2 font-medium"><AppIcon name="globe" :size="18" class="text-muted" />{{ t('contacts.websites') }}</legend>
      <div v-for="(w, i) in d.websites" :key="'w' + i" class="flex gap-2">
        <input v-model="w.value" type="url" maxlength="300" placeholder="https://" :aria-label="t('contacts.website')" :class="field" />
        <button type="button" class="shrink-0 rounded-lg p-2 text-muted hover:bg-sunken hover:text-danger" :aria-label="t('settings.remove')" @click="d.websites.splice(i, 1)"><AppIcon name="close" :size="17" /></button>
      </div>
      <button v-if="d.websites.length < 10" type="button" class="text-sm text-accent hover:underline" @click="d.websites.push({ value: '' })">+ {{ t('contacts.addWebsite') }}</button>
    </fieldset>

    <!-- Groups -->
    <fieldset>
      <legend class="mb-2 flex items-center gap-2 font-medium"><AppIcon name="tag" :size="18" class="text-muted" />{{ t('contacts.groups') }}</legend>
      <div class="flex flex-wrap items-center gap-1.5">
        <span v-for="(g, i) in d.groups" :key="g" class="flex items-center gap-1 rounded-full bg-accent-soft py-1 pr-1 pl-3 text-sm text-accent">
          {{ g }}<button type="button" class="rounded-full px-1.5 hover:bg-accent/10" :aria-label="t('settings.remove')" @click="d.groups.splice(i, 1)">✕</button>
        </span>
        <input
          v-model="groupInput" list="contact-groups" maxlength="50" :placeholder="t('contacts.groupPlaceholder')" :aria-label="t('contacts.groups')"
          class="min-w-40 flex-1 rounded-lg border border-line bg-surface px-3 py-1.5 text-sm outline-none focus:border-accent"
          @keydown.enter.prevent="addGroup" @change="addGroup"
        />
        <datalist id="contact-groups"><option v-for="g in groupSuggestions" :key="g" :value="g" /></datalist>
      </div>
    </fieldset>

    <!-- Notes -->
    <label class="block">
      <span class="mb-2 flex items-center gap-2 font-medium"><AppIcon name="drafts" :size="18" class="text-muted" />{{ t('contacts.notes') }}</span>
      <textarea v-model="notes" rows="4" maxlength="5000" :class="field" />
    </label>

    <label class="flex items-center gap-2">
      <input v-model="favorite" type="checkbox" class="size-4 accent-[var(--color-accent)]" />
      {{ t('contacts.favorite') }}
    </label>

    <div class="sticky bottom-0 -mx-5 flex justify-end gap-2 border-t border-line bg-surface/95 px-5 py-3 backdrop-blur">
      <button type="button" class="rounded-lg px-4 py-2 text-muted hover:bg-sunken" @click="emit('cancel')">{{ t('contacts.cancel') }}</button>
      <button class="rounded-lg bg-accent px-5 py-2 font-medium text-white disabled:opacity-60" :disabled="saving || !canSave">{{ t('contacts.save') }}</button>
    </div>
  </form>
</template>
