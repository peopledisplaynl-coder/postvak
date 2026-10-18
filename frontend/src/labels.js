import { t } from './i18n/index.js'

/**
 * Labels are IMAP keywords on a message. The defaults use Thunderbird's keywords ($label1 … $label5),
 * so labels set in Postvak show in Thunderbird and the other way round.
 */
const DEFAULT_NAMES = { $label1: 'important', $label2: 'work', $label3: 'personal', $label4: 'todo', $label5: 'later' }

export function labelName(label) {
  if (!label) return ''
  if (label.name) return label.name
  const key = DEFAULT_NAMES[label.key.toLowerCase()]
  return key ? t(`labels.defaults.${key}`) : label.key
}

/** The label definitions of the user, by lower-case key. */
export function labelMap(settings) {
  return Object.fromEntries((settings?.labels ?? []).map((l) => [l.key.toLowerCase(), l]))
}

/** Labels of a message that the user has defined, in the user's order. */
export function labelsOfMessage(settings, message) {
  const keys = new Set((message?.labels ?? []).map((k) => k.toLowerCase()))
  return (settings?.labels ?? []).filter((l) => keys.has(l.key.toLowerCase()))
}

/** A keyword for a new label: "pv_" + the name in plain letters (IMAP keywords are atoms). */
export function newLabelKey(name, existing) {
  const base = 'pv_' + (name.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^\w]+/g, '_').replace(/^_+|_+$/g, '').toLowerCase().slice(0, 30) || 'label')
  let key = base
  for (let i = 2; existing.some((l) => l.key.toLowerCase() === key); i++) key = `${base}_${i}`
  return key
}
