/**
 * Date helpers for the calendar and tasks. Everything works with local dates as 'YYYY-MM-DD'
 * strings and local Date objects; the API gets the browser's time zone (timeZone()).
 */

export const timeZone = () => Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'

const pad = (n) => String(n).padStart(2, '0')

/** 'YYYY-MM-DD' of a local Date. */
export function ymd(d) {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/** Local Date at midnight from 'YYYY-MM-DD'. */
export function parseYmd(s) {
  const [y, m, d] = s.split('-').map(Number)
  return new Date(y, m - 1, d)
}

/** 'YYYY-MM-DDTHH:MM' (local) of a Date. */
export function localStamp(d) {
  return `${ymd(d)}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

export function addDays(d, n) {
  const r = new Date(d)
  r.setDate(r.getDate() + n)
  return r
}

export function addMonths(d, n) {
  const r = new Date(d.getFullYear(), d.getMonth() + n, 1)
  // Keep the day where possible (31 Jan + 1 month = 28/29 Feb).
  const last = new Date(r.getFullYear(), r.getMonth() + 1, 0).getDate()
  r.setDate(Math.min(d.getDate(), last))
  return r
}

/** Monday of the week (ISO weeks start on Monday). */
export function startOfWeek(d) {
  const r = new Date(d.getFullYear(), d.getMonth(), d.getDate())
  r.setDate(r.getDate() - ((r.getDay() + 6) % 7))
  return r
}

/** 6 weeks × 7 days covering the month of d, starting on a Monday. */
export function monthGrid(d) {
  const first = startOfWeek(new Date(d.getFullYear(), d.getMonth(), 1))
  return Array.from({ length: 42 }, (_, i) => addDays(first, i))
}

/** Days (local 'YYYY-MM-DD') an occurrence covers. All-day ends are exclusive; timed ends at 00:00 too. */
export function daysOf(ev) {
  const days = []
  if (ev.all_day) {
    let d = parseYmd(ev.start)
    const end = parseYmd(ev.end)
    do {
      days.push(ymd(d))
      d = addDays(d, 1)
    } while (d < end)
    return days
  }
  const start = new Date(ev.start)
  const end = new Date(ev.end)
  let d = new Date(start.getFullYear(), start.getMonth(), start.getDate())
  do {
    days.push(ymd(d))
    d = addDays(d, 1)
  } while (d < end)
  return days
}

/** Map 'YYYY-MM-DD' → occurrences on that day (all-day first, then by start). */
export function byDay(events) {
  const map = new Map()
  for (const ev of events) {
    for (const day of daysOf(ev)) {
      if (!map.has(day)) map.set(day, [])
      map.get(day).push(ev)
    }
  }
  for (const list of map.values()) {
    list.sort((a, b) => (b.all_day - a.all_day) || (a.all_day ? 0 : new Date(a.start) - new Date(b.start)) || a.title.localeCompare(b.title))
  }
  return map
}

/**
 * Position of timed events within one day for the week view: top/height in minutes from
 * midnight, and side-by-side columns when they overlap.
 * @returns {Array<{ev, top:number, height:number, col:number, cols:number}>}
 */
export function layoutDay(events, day) {
  const dayStart = parseYmd(day)
  const dayEnd = addDays(dayStart, 1)
  const items = events
    .filter((ev) => !ev.all_day)
    .map((ev) => {
      const s = Math.max(new Date(ev.start), dayStart)
      const e = Math.min(new Date(ev.end), dayEnd)
      const top = (s - dayStart) / 60000
      return { ev, top, height: Math.max(20, (e - s) / 60000), col: 0, cols: 1 }
    })
    .sort((a, b) => a.top - b.top || b.height - a.height)

  // Group items that (transitively) overlap; within a group give each a free column.
  let group = []
  let groupEnd = -1
  const flush = () => {
    const cols = group.reduce((m, it) => Math.max(m, it.col + 1), 1)
    group.forEach((it) => (it.cols = cols))
    group = []
  }
  for (const it of items) {
    if (it.top >= groupEnd) {
      flush()
      groupEnd = -1
    }
    const used = new Set(group.filter((g) => g.top + g.height > it.top).map((g) => g.col))
    let col = 0
    while (used.has(col)) col++
    it.col = col
    group.push(it)
    groupEnd = Math.max(groupEnd, it.top + it.height)
  }
  flush()
  return items
}

/** Round a Date up to the next half hour (for new events). */
export function nextHalfHour(d = new Date()) {
  const r = new Date(d)
  r.setSeconds(0, 0)
  r.setMinutes(r.getMinutes() < 30 ? 30 : 60)
  return r
}

/** Task groups: overdue, today, upcoming, no date. due is 'YYYY-MM-DD' or 'YYYY-MM-DDTHH:MM' (local). */
export function taskGroup(task, now = new Date()) {
  if (!task.due) return 'none'
  const today = ymd(now)
  const day = task.due.slice(0, 10)
  if (day < today) return 'overdue'
  if (day === today) {
    if (task.due.length > 10 && new Date(task.due) < now) return 'overdue'
    return 'today'
  }
  return 'later'
}
