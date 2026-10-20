import { initials } from './format.js'
import { t, locale } from './i18n/index.js'

/** Helpers for the contacts page (details as returned by the API: see Contacts\ContactDetails). */

export function emptyDetails() {
  return {
    given: '', family: '', nickname: '', company: '', department: '', title: '',
    emails: [], phones: [], addresses: [], birthday: '', websites: [], groups: [],
  }
}

export function contactInitials(c) {
  return initials({ name: c.name, email: c.email })
}

/** "Teamleider · PeopleDisplay" */
export function workLine(d) {
  if (!d) return ''
  const title = [d.title, d.department].filter(Boolean).join(', ')
  return [title, d.company].filter(Boolean).join(' · ')
}

/** Address on separate lines, Dutch order (street / postal code + city / region / country). */
export function addressLines(a) {
  return [a.street, [a.postal_code, a.city].filter(Boolean).join('  '), a.region, a.country].filter(Boolean)
}

export function mapsUrl(a) {
  return `https://www.openstreetmap.org/search?query=${encodeURIComponent(addressLines(a).join(', '))}`
}

/** "17 mei 1980 (46 jaar)" or "14 maart" (no year). */
export function birthdayText(value, now = new Date()) {
  if (!value) return ''
  const noYear = value.startsWith('--')
  const [y, m, d] = noYear ? [2000, ...value.slice(2).split('-').map(Number)] : value.split('-').map(Number)
  const date = new Date(y, m - 1, d)
  const text = date.toLocaleDateString(locale(), noYear ? { day: 'numeric', month: 'long' } : { day: 'numeric', month: 'long', year: 'numeric' })
  if (noYear) return text
  let age = now.getFullYear() - y
  if (now.getMonth() + 1 < m || (now.getMonth() + 1 === m && now.getDate() < d)) age--
  return age >= 0 && age < 130 ? `${text} (${t('contacts.age', { n: age })})` : text
}

/** Days until the next birthday (0 = today), or null. */
export function daysToBirthday(value, now = new Date()) {
  if (!value) return null
  const [m, d] = (value.startsWith('--') ? value.slice(2) : value.slice(5)).split('-').map(Number)
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  let next = new Date(now.getFullYear(), m - 1, d)
  if (next < today) next = new Date(now.getFullYear() + 1, m - 1, d)
  return Math.round((next - today) / 86400000)
}

/** Text that search looks in: names, company, all addresses and numbers, city, groups. */
export function searchText(c) {
  const d = c.details ?? {}
  return [
    c.name, c.email, c.phone, ...(c.other_emails ?? []), d.nickname, d.company, d.title, d.department,
    ...(d.emails ?? []).map((e) => e.value), ...(d.phones ?? []).map((p) => p.value),
    ...(d.addresses ?? []).flatMap((a) => [a.city, a.street]), ...(d.groups ?? []),
  ].filter(Boolean).join(' ')
}
