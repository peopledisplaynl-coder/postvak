const LOCALE = 'nl-NL'

/** Short date for the message list: time today, "gisteren", weekday this week, otherwise date. */
export function listDate(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  const now = new Date()
  const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const days = Math.floor((startOfToday - new Date(d.getFullYear(), d.getMonth(), d.getDate())) / 86400000)

  if (days <= 0) return d.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' })
  if (days === 1) return 'gisteren'
  if (days < 7) return d.toLocaleDateString(LOCALE, { weekday: 'short' })
  if (d.getFullYear() === now.getFullYear()) return d.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' })
  return d.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' })
}

export function fullDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString(LOCALE, { dateStyle: 'full', timeStyle: 'short' })
}

export function fileSize(bytes) {
  if (!bytes) return ''
  const units = ['B', 'kB', 'MB', 'GB']
  let i = 0
  let n = bytes
  while (n >= 1024 && i < units.length - 1) {
    n /= 1024
    i++
  }
  return `${n.toFixed(n < 10 && i > 0 ? 1 : 0)} ${units[i]}`
}

export function displayName(addr) {
  if (!addr) return ''
  return addr.name || addr.email
}

/** Stable, calm colour per sender for the initial avatar. */
const AVATAR_COLORS = ['#3646D9', '#0F8A5F', '#B54A8C', '#C27A0E', '#2C7DA0', '#7A5AC8', '#5F7A1E', '#C2463C']
export function avatarColor(seed = '') {
  let h = 0
  for (const ch of seed) h = (h * 31 + ch.charCodeAt(0)) >>> 0
  return AVATAR_COLORS[h % AVATAR_COLORS.length]
}

export function initials(addr) {
  const name = displayName(addr).replace(/["']/g, '').trim()
  if (!name) return '?'
  const parts = name.split(/[\s@.]+/).filter(Boolean)
  return (parts[0][0] + (parts.length > 1 && !name.includes('@') ? parts[parts.length - 1][0] : '')).toUpperCase()
}
