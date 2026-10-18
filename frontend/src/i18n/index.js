import { ref } from 'vue'
import nl from './nl.js'
import en from './en.js'

/** Available interface languages (code → texts). The setting 'auto' picks one from the browser. */
export const languages = { nl, en }
export const languageNames = { nl: 'Nederlands', en: 'English' }

const current = ref('nl')

/** Language the browser prefers, if we have it (otherwise English). */
export function browserLanguage() {
  const nav = typeof navigator !== 'undefined' ? navigator : {}
  for (const tag of nav.languages ?? [nav.language ?? '']) {
    const code = String(tag).slice(0, 2).toLowerCase()
    if (languages[code]) return code
  }
  return 'en'
}

/** Apply a setting value: 'nl', 'en' or 'auto'. Reactive: templates update right away. */
export function setLanguage(setting) {
  const code = languages[setting] ? setting : browserLanguage()
  current.value = code
  if (typeof document !== 'undefined') document.documentElement.lang = code
}

export function language() {
  return current.value
}

/** Locale for dates and numbers, e.g. 'nl-NL'. */
export function locale() {
  return languages[current.value].locale
}

/** t('list.empty') or t('toast.failed', { msg: 'x' }). Falls back to Dutch, then to the key. */
export function t(key, vars = {}) {
  const find = (texts) => key.split('.').reduce((obj, part) => obj?.[part], texts)
  let value = find(languages[current.value])
  if (typeof value !== 'string') value = find(nl)
  if (typeof value !== 'string') return key
  return value.replace(/\{(\w+)\}/g, (_, name) => vars[name] ?? '')
}

// Before the user's settings are known (login page): the browser's language.
setLanguage('auto')
