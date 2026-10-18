// Checks that every language file has exactly the same keys as nl.js (and the same {placeholders}).
// Run: node scripts/check-i18n.mjs
import nl from '../frontend/src/i18n/nl.js'
import en from '../frontend/src/i18n/en.js'

const languages = { en }
const flatten = (obj, prefix = '') => Object.entries(obj).flatMap(([k, v]) =>
  typeof v === 'object' ? flatten(v, `${prefix}${k}.`) : [[`${prefix}${k}`, v]])
const placeholders = (s) => [...String(s).matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort().join(',')

const base = new Map(flatten(nl))
let problems = 0
for (const [code, texts] of Object.entries(languages)) {
  const other = new Map(flatten(texts))
  for (const [key, value] of base) {
    if (!other.has(key)) { console.log(`${code}: missing ${key}`); problems++ }
    else if (placeholders(value) !== placeholders(other.get(key))) { console.log(`${code}: placeholders differ in ${key}`); problems++ }
  }
  for (const key of other.keys()) if (!base.has(key)) { console.log(`${code}: extra ${key}`); problems++ }
}
console.log(problems ? `${problems} problem(s)` : 'i18n ok')
process.exit(problems ? 1 : 0)
