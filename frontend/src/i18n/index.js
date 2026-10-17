import nl from './nl.js'

const languages = { nl }
let current = languages.nl

/** t('list.empty') or t('toast.failed', { msg: 'x' }) */
export function t(key, vars = {}) {
  const value = key.split('.').reduce((obj, part) => obj?.[part], current)
  if (typeof value !== 'string') return key
  return value.replace(/\{(\w+)\}/g, (_, name) => vars[name] ?? '')
}

export function setLanguage(code) {
  if (languages[code]) current = languages[code]
}
