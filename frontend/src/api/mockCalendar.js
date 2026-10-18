// Calendar and tasks for the demo mode (see mock.js). Same shapes as the real API; data in memory.
import { ymd, parseYmd, addDays, localStamp } from '../calendar.js'

export function createCalendarMock(nl) {
  const L = (a, b) => (nl ? a : b)
  const calendars = [
    { id: 1, name: L('Agenda', 'Calendar'), color: '#3646D9', type: 'events', order: 0, writable: true },
    { id: 3, name: L('Band', 'Band'), color: '#C2410C', type: 'events', order: 1, writable: true },
    { id: 2, name: L('Taken', 'Tasks'), color: '#0F8A5F', type: 'tasks', order: 2, writable: true },
    { id: 4, name: L('Feestdagen', 'Public holidays'), color: '#0E7490', type: 'events', order: 3, writable: false },
  ].map((c) => ({ feed: null, share_url: null, ...c }))
  calendars[3].feed = { id: 1, calendar_id: 4, url: 'https://calendar.google.com/calendar/ical/…', host: 'calendar.google.com', last_fetch_at: new Date().toISOString(), last_success_at: new Date().toISOString(), last_error: null }
  let nextFeed = 2
  const today = new Date()
  const at = (days, h, m = 0) => localStamp(new Date(today.getFullYear(), today.getMonth(), today.getDate() + days, h, m))
  const day = (days) => ymd(addDays(today, days))

  let nextId = 100
  // Master events: start/end local ('Y-m-d' for all-day, 'Y-m-dTH:i' otherwise), like the editor fields.
  const events = [
    { id: 1, calendar_id: 1, title: L('Overleg website', 'Website meeting'), location: L('Online', 'Online'), all_day: false, start: at(0, 10), end: at(0, 11), reminder: 15, recurrence: null },
    { id: 2, calendar_id: 3, title: L('Repetitie', 'Band rehearsal'), location: L('Studio 013', 'Studio B'), all_day: false, start: at(-7, 19, 30), end: at(-7, 22), reminder: 30, recurrence: { rrule: 'FREQ=WEEKLY', freq: 'WEEKLY', interval: 1, until: null, count: null, custom: false } },
    { id: 3, calendar_id: 1, title: L('Lunch met Marieke', 'Lunch with Maria'), location: L('Café de Hoek', 'Corner Café'), all_day: false, start: at(1, 12, 30), end: at(1, 13, 30), reminder: null, recurrence: null },
    { id: 4, calendar_id: 3, title: L('Optreden', 'Gig'), location: L('Poppodium', 'Music hall'), all_day: true, start: day(3), end: day(3), reminder: -540, recurrence: null },
    { id: 5, calendar_id: 1, title: L('Tandarts', 'Dentist'), location: '', all_day: false, start: at(-1, 14), end: at(-1, 14, 30), reminder: 60, recurrence: null },
    { id: 6, calendar_id: 1, title: L('Klant bellen', 'Call client'), location: '', all_day: false, start: at(2, 10), end: at(2, 11), reminder: null, recurrence: null },
    { id: 7, calendar_id: 1, title: L('Offerte bespreken', 'Discuss quote'), location: L('Kantoor', 'Office'), all_day: false, start: at(2, 10, 30), end: at(2, 12), reminder: null, recurrence: null },
    { id: 9, calendar_id: 4, title: L('Herfstvakantie regio zuid', 'Autumn half-term'), location: '', all_day: true, start: day(6), end: day(10), reminder: null, recurrence: null },
    { id: 8, calendar_id: 1, title: L('Weekend weg', 'Weekend away'), location: L('Zeeland', 'The coast'), all_day: true, start: day(9), end: day(11), reminder: null, recurrence: null },
  ].map((e) => ({ description: '', ...e }))

  const tasks = [
    { id: 50, calendar_id: 2, title: L('Setlist doorsturen', 'Send the setlist'), description: '', due: day(-1), due_all_day: true, priority: 1, completed: false, completed_at: null, reminder: true, mail: { account_id: 1, folder: 'INBOX', uid: 1000, subject: L('Setlist zaterdag', 'Setlist for Saturday'), from: L('Peter Janssen', 'Peter Jansen') }, created: '2026-10-10T10:00:00Z' },
    { id: 51, calendar_id: 2, title: L('Snaren kopen', 'Buy strings'), description: '', due: day(0), due_all_day: true, priority: 0, completed: false, completed_at: null, reminder: false, mail: null, created: '2026-10-11T10:00:00Z' },
    { id: 52, calendar_id: 2, title: L('Factuur betalen', 'Pay the invoice'), description: L('Hosting, voor het eind van de maand', 'Hosting, before the end of the month'), due: `${day(4)}T09:00`, due_all_day: false, priority: 5, completed: false, completed_at: null, reminder: true, mail: null, created: '2026-10-12T10:00:00Z' },
    { id: 53, calendar_id: 2, title: L('Foto\'s uitzoeken', 'Pick the photos'), description: '', due: null, due_all_day: false, priority: 9, completed: false, completed_at: null, reminder: false, mail: null, created: '2026-10-13T10:00:00Z' },
    { id: 54, calendar_id: 2, title: L('Zaal reserveren', 'Book the venue'), description: '', due: day(-3), due_all_day: true, priority: 0, completed: true, completed_at: new Date(Date.now() - 86400000).toISOString(), reminder: false, mail: null, created: '2026-10-09T10:00:00Z' },
  ]
  const passwords = []

  const colorOf = (id) => calendars.find((c) => c.id === id)?.color ?? '#3646D9'
  const toInstant = (local) => new Date(local).toISOString().replace(/\.\d{3}Z$/, 'Z')

  /** Occurrences of a master event in [from, to). */
  function expand(ev, from, to) {
    const out = []
    const step = { DAILY: 1, WEEKLY: 7 }[ev.recurrence?.freq] ?? null
    const limit = ev.recurrence ? 400 : 1
    for (let i = 0; i < limit; i++) {
      const shift = (d) => (step ? addDays(d, step * i * (ev.recurrence.interval || 1)) : d)
      let s, e
      if (ev.all_day) {
        s = shift(parseYmd(ev.start))
        e = addDays(shift(parseYmd(ev.end)), 1)
      } else {
        s = shift(new Date(ev.start))
        e = shift(new Date(ev.end))
      }
      if (ev.recurrence?.until && s > addDays(parseYmd(ev.recurrence.until), 1)) break
      if (s >= to) break
      if (e > from) {
        out.push({
          key: `${ev.id}:${ev.recurrence ? Math.floor(s / 1000) : 0}`,
          id: ev.id, calendar_id: ev.calendar_id, color: colorOf(ev.calendar_id),
          title: ev.title, location: ev.location, description: ev.description, all_day: ev.all_day,
          start: ev.all_day ? ymd(s) : toInstant(localStamp(s)),
          end: ev.all_day ? ymd(e) : toInstant(localStamp(e)),
          recurring: !!ev.recurrence, occurrence: ev.recurrence ? Math.floor(s / 1000) : null, reminder: ev.reminder,
        })
      }
      if (!step) break
    }
    return out
  }

  function fromInput(body, old = {}) {
    const rec = body.recurrence
    let recurrence = null
    if (rec?.rrule && old.recurrence) recurrence = old.recurrence
    else if (rec?.freq) recurrence = { rrule: `FREQ=${rec.freq}`, freq: rec.freq, interval: rec.interval || 1, until: rec.until || null, count: null, custom: false }
    return {
      ...old,
      calendar_id: body.calendar_id || old.calendar_id || 1,
      title: body.title || '(…)', location: body.location || '', description: body.description || '',
      all_day: !!body.all_day, start: body.start, end: body.end || body.start,
      reminder: body.reminder ?? null, recurrence,
    }
  }

  const withColor = (task) => ({ ...task, color: colorOf(task.calendar_id) })

  return function handle(method, path, { body = {}, query = {} }) {
    const idOf = () => Number(path.split('/')[2])
    if (path === '/calendars' && method === 'GET') return JSON.parse(JSON.stringify(calendars))
    if (path === '/calendars' && method === 'POST') {
      const cal = { id: nextId++, name: body.name, color: body.color, type: body.type, order: calendars.length, writable: true }
      calendars.push(cal)
      return cal
    }
    if (path.startsWith('/calendars/') && method === 'PATCH') return Object.assign(calendars.find((c) => c.id === idOf()), body)
    if (/^\/calendars\/\d+\/share$/.test(path)) {
      const cal = calendars.find((c) => c.id === idOf())
      cal.share_url = method === 'POST' ? `${location.origin}/api/ical/demo${Math.random().toString(36).slice(2, 14)}.ics` : null
      return method === 'POST' ? { share_url: cal.share_url } : null
    }
    if (path === '/calendar-feeds' && method === 'POST') {
      if (!/^(https?|webcals?):\/\//i.test(body.url ?? '')) throw Object.assign(new Error('Only http and https addresses are supported'), { status: 400 })
      const host = body.url.replace(/^\w+:\/\//, '').split('/')[0]
      const id = Math.max(...calendars.map((c) => c.id)) + 1
      const feed = { id: nextFeed++, calendar_id: id, url: body.url.slice(0, 40) + '…', host, last_fetch_at: new Date().toISOString(), last_success_at: new Date().toISOString(), last_error: null }
      calendars.push({ id, name: body.name || host, color: body.color || '#0E7490', type: 'events', order: calendars.length, writable: false, feed, share_url: null })
      return feed
    }
    if (/^\/calendar-feeds\/\d+\/refresh$/.test(path)) {
      const cal = calendars.find((c) => c.feed?.id === idOf())
      cal.feed = { ...cal.feed, last_fetch_at: new Date().toISOString(), last_success_at: new Date().toISOString() }
      return cal.feed
    }
    if (path.startsWith('/calendar-feeds/') && method === 'DELETE') { calendars.splice(calendars.findIndex((c) => c.feed?.id === idOf()), 1); return null }
    if (path.startsWith('/calendars/') && method === 'DELETE') { calendars.splice(calendars.findIndex((c) => c.id === idOf()), 1); return null }

    if (path === '/events' && method === 'GET') {
      const from = parseYmd(query.start)
      const to = parseYmd(query.end)
      return events.flatMap((ev) => expand(ev, from, to)).sort((a, b) => a.start.localeCompare(b.start))
    }
    if (path.startsWith('/events/') && method === 'GET') {
      const ev = events.find((e) => e.id === idOf())
      return { ...ev, timezone: query.tz }
    }
    if (path === '/events' && method === 'POST') { const ev = { id: nextId++, ...fromInput(body) }; events.push(ev); return { id: ev.id } }
    if (path.startsWith('/events/') && method === 'PUT') {
      const i = events.findIndex((e) => e.id === idOf())
      events[i] = fromInput(body, events[i])
      return { id: events[i].id }
    }
    if (path.startsWith('/events/') && method === 'DELETE') {
      const i = events.findIndex((e) => e.id === idOf())
      if (!query.occurrence) events.splice(i, 1)
      return null
    }

    if (path === '/tasks' && method === 'GET') return tasks.map(withColor)
    if (path === '/tasks' && method === 'POST') {
      const task = { id: nextId++, calendar_id: body.calendar_id || 2, title: body.title, description: '', due: null, due_all_day: false, priority: 0, completed: false, completed_at: null, reminder: false, mail: body.mail ?? null, created: new Date().toISOString() }
      tasks.push(task)
      return withColor(task)
    }
    if (path.startsWith('/tasks/') && method === 'PATCH') {
      const task = tasks.find((x) => x.id === idOf())
      const { timezone, ...changes } = body
      Object.assign(task, changes)
      if ('due' in changes) task.due_all_day = !!changes.due && changes.due.length === 10
      if ('completed' in changes) task.completed_at = changes.completed ? new Date().toISOString() : null
      return withColor(task)
    }
    if (path.startsWith('/tasks/') && method === 'DELETE') { tasks.splice(tasks.findIndex((x) => x.id === idOf()), 1); return null }

    if (path === '/dav' && method === 'GET') return { url: `${location.origin}/dav/`, server: location.host, username: nl ? 'ton@example.nl' : 'sam@example.com', base_path: '/dav/', passwords: [...passwords] }
    if (path === '/dav/passwords' && method === 'POST') {
      const p = { id: nextId++, name: body.name, created_at: new Date().toISOString(), last_used_at: null }
      passwords.push(p)
      return { id: p.id, password: 'k7mq-x2pd-9fhr-c4nw' }
    }
    if (path.startsWith('/dav/passwords/') && method === 'DELETE') { passwords.splice(passwords.findIndex((p) => p.id === Number(path.split('/')[3])), 1); return null }
    return undefined
  }
}
