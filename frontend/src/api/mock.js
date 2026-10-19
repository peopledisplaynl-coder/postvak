import { createCalendarMock } from './mockCalendar.js'
// Fake backend for "npm run dev:mock". Lets you work on the UI without PHP or a mail server.
// Data lives in memory and resets on reload.

// Dutch demo data for a Dutch browser, English otherwise (the README screenshots use the English set).
const nl = typeof navigator !== 'undefined' && /^nl\b/i.test(navigator.language || '')
const D = nl
  ? {
      me: 'Ton', meEmail: 'ton@example.nl', second: 'info@bandnaam.example', domain: 'example.nl',
      senders: [
        ['Marieke de Vries', 'marieke@voorbeeld.nl'], ['Camping De Heide', 'reserveringen@deheide.example'],
        ['Hostingbedrijf', 'support@hosting.example'], ['Peter Janssen', 'peter.janssen@voorbeeld.nl'],
        ['Repetitieruimte 013', 'planning@ruimte013.example'], ['Webwinkel', 'noreply@winkel.example'],
        ['Gemeente', 'noreply@gemeente.example'], ['Sandra', 'sandra@voorbeeld.nl'],
      ],
      subjects: [
        'Bevestiging van je reservering', 'Setlist zaterdag', 'Factuur oktober', 'Even bijpraten?',
        'Je pakket is onderweg', 'Nieuwe repetitietijden', 'Foto’s van het optreden', 'Vraag over de hosting',
        'Herinnering: afspraak', 'Uitnodiging verjaardag',
      ],
      snippets: ['Hoi Ton, zoals besproken stuur ik je hierbij de details voor zaterdag.', 'Je bestelling is verzonden en wordt morgen tussen 10:00 en 14:00 bezorgd.', 'Bedankt voor je bericht. We nemen zo snel mogelijk contact met je op.', 'Hierbij de foto’s van het optreden, groetjes!'],
      body: (s) => `<p>Hoi Ton,</p><p>Dit is een voorbeeldbericht om de interface te testen. ${s.toLowerCase()}, zoals besproken.</p>`,
      bye: 'Groet', attachment: 'overzicht.pdf',
    }
  : {
      me: 'Sam', meEmail: 'sam@example.com', second: 'hello@theband.example', domain: 'example.com',
      senders: [
        ['Maria Lopez', 'maria@example.org'], ['Lakeside Campsite', 'bookings@lakeside.example'],
        ['Hosting Support', 'support@hosting.example'], ['Peter Jansen', 'peter@example.org'],
        ['Rehearsal Studio', 'planning@studio.example'], ['Web Shop', 'noreply@shop.example'],
        ['City Council', 'noreply@council.example'], ['Sandra', 'sandra@example.org'],
      ],
      subjects: [
        'Your booking is confirmed', 'Setlist for Saturday', 'Invoice October', 'Catch up soon?',
        'Your parcel is on its way', 'New rehearsal times', 'Photos from the gig', 'Question about hosting',
        'Reminder: appointment', 'Birthday invitation',
      ],
      snippets: ['Hi Sam, as discussed, here are the details for Saturday.', 'Your order has shipped and will arrive tomorrow between 10:00 and 14:00.', 'Thanks for your message. We will get back to you as soon as possible.', 'Here are the photos from the gig, enjoy!'],
      body: (s) => `<p>Hi Sam,</p><p>This is a sample message for trying out the interface: ${s.toLowerCase()}, as discussed.</p>`,
      bye: 'Best', attachment: 'overview.pdf',
    }

const accounts = [
  { id: 1, email: D.meEmail, display_name: D.me, color: '#3646D9', is_login: true, sort_order: 0,
    imap_host: `mail.${D.domain}`, imap_port: 993, imap_encryption: 'ssl',
    smtp_host: `mail.${D.domain}`, smtp_port: 465, smtp_encryption: 'ssl', username: D.meEmail, signature_html: '' },
  { id: 2, email: D.second, display_name: 'Info', color: '#0F8A5F', is_login: false, sort_order: 1,
    imap_host: 'mail.theband.example', imap_port: 993, imap_encryption: 'ssl',
    smtp_host: 'mail.theband.example', smtp_port: 465, smtp_encryption: 'ssl', username: D.second, signature_html: '' },
]

let settings = {
  swipe_left: 'trash', swipe_right: 'archive', confirm_delete: false, undo_seconds: 5,
  theme: 'system', density: 'comfortable', show_images: 'ask', reading_pane: 'right',
  poll_seconds: 60, unified_inbox: true, mark_read_on_open: true, sort: 'date_desc', accent: 'indigo', text_size: 'normal', page_size: 50, page_size_mobile: 25,
  labels: [['$label1', '#D23F3F'], ['$label2', '#E07B1A'], ['$label3', '#0F8A5F'], ['$label4', '#2C7DA0'], ['$label5', '#7A5AC8']].map(([key, color]) => ({ key, name: '', color })),
}

const senders = D.senders
const contactsDb = senders.map(([name, email], i) => ({
  id: i + 1, name, email, phone: i % 3 ? '' : '06 1234 56' + String(i).padStart(2, '0'), notes: '', favorite: i === 0,
  saved: i % 2 === 0, times_used: i + 1, last_used_at: new Date(Date.now() - i * 86400000).toISOString(),
  other_emails: i === 0 ? [email.replace('@', '.work@')] : [], synced: i === 0,
}))
// A card that came from a phone without an e-mail address.
contactsDb.push({ id: 99, name: nl ? 'Loodgieter Kees' : 'Plumber Joe', email: '', phone: '06 9876 5432', notes: '', favorite: false, saved: true, times_used: 0, last_used_at: null, other_emails: [], synced: true })

// Meeting invitations in the demo (one per message with the "rehearsal times" subject).
const invitations = {}
function invitationFor(msg) {
  if (msg.subject !== D.subjects[5]) return null
  const start = new Date(); start.setDate(start.getDate() + 3); start.setHours(20, 0, 0, 0)
  const end = new Date(start.getTime() + 90 * 60000)
  invitations[msg.uid] ??= {
    method: 'request', uid: `demo-${msg.uid}@example`, title: nl ? 'Repetitie nieuwe nummers' : 'Rehearsal new songs',
    location: nl ? 'Studio 013' : 'Studio B', description: '', all_day: false,
    start: start.toISOString().replace(/\.\d{3}Z$/, 'Z'), end: end.toISOString().replace(/\.\d{3}Z$/, 'Z'),
    recurring: false, organizer: { name: msg.from.name, email: msg.from.email }, attendees: 4, own: false,
    my_status: 'needs-action', in_calendar: false, calendar_status: null, part: 0,
  }
  return invitations[msg.uid]
}
const subjects = D.subjects

function makeMessages(accountId, folder, count) {
  const now = Date.now()
  return Array.from({ length: count }, (_, i) => {
    const [name, email] = senders[(i + accountId) % senders.length]
    return {
      uid: 1000 - i, folder, account_id: accountId,
      subject: subjects[(i * 3 + accountId) % subjects.length],
      from: { name, email }, to: [{ name: '', email: accounts[accountId - 1].email }],
      date: new Date(now - i * 1000 * 60 * 47).toISOString(),
      seen: i > 3 && i % 5 !== 0, flagged: i % 9 === 2, labels: i === 1 ? ['$label1'] : i === 4 ? ['$label2', '$label4'] : [], answered: i % 7 === 3, has_attachments: i % 4 === 1,
      snippet: D.snippets[i % 4],
    }
  })
}

/** The search filters of the list request (filter=unseen,flagged,attachment&since=7&from=…). */
function applyFilters(list, query) {
  const flags = String(query.filter ?? '').split(',')
  if (flags.includes('unseen')) list = list.filter((x) => !x.seen)
  if (flags.includes('flagged')) list = list.filter((x) => x.flagged)
  if (flags.includes('attachment')) list = list.filter((x) => x.has_attachments)
  if (query.since) list = list.filter((x) => Date.parse(x.date) > Date.now() - Number(query.since) * 86400000)
  if (query.from) list = list.filter((x) => x.from.email.toLowerCase() === String(query.from).toLowerCase())
  return list
}

const store = {}
let sharedFiles = []
function box(accountId, folder) {
  const key = `${accountId}:${folder}`
  if (!store[key]) store[key] = makeMessages(accountId, folder, folder === 'INBOX' ? 42 : folder === 'Trash' ? 0 : 8)
  return store[key]
}

const folders = (id) => [
  { path: 'INBOX', name: 'INBOX', full_name: 'INBOX', delimiter: '.', selectable: true, role: 'inbox', unseen: box(id, 'INBOX').filter((m) => !m.seen).length, total: box(id, 'INBOX').length },
  { path: 'Drafts', name: 'Drafts', full_name: 'Drafts', delimiter: '.', selectable: true, role: 'drafts', unseen: 0, total: 0 },
  { path: 'Sent', name: 'Sent', full_name: 'Sent', delimiter: '.', selectable: true, role: 'sent', unseen: 0, total: 8 },
  { path: 'Archive', name: 'Archive', full_name: 'Archive', delimiter: '.', selectable: true, role: 'archive', unseen: 0, total: box(id, 'Archive').length },
  { path: 'Junk', name: 'Junk', full_name: 'Junk', delimiter: '.', selectable: true, role: 'junk', unseen: 0, total: 0 },
  { path: 'Trash', name: 'Trash', full_name: 'Trash', delimiter: '.', selectable: true, role: 'trash', unseen: 0, total: box(id, 'Trash').length },
  { path: 'Band', name: 'Band', full_name: 'Band', delimiter: '.', selectable: true, role: null, unseen: 2, total: 8 },
  ...extraFolders.map((n) => ({ path: n, name: n, full_name: n, delimiter: '.', selectable: true, role: null, unseen: 0, total: box(id, n).length })),
]
const extraFolders = []

const trusted = [{ id: 1, kind: 'domain', value: 'bol.com' }]

const calendarMock = createCalendarMock(nl)

// Rules, out-of-office replies and templates in the demo.
const demo = {
  rules: [{
    id: 1, name: nl ? 'Facturen' : 'Invoices', account_id: 1, enabled: true, match_all: true,
    conditions: [{ field: 'subject', op: 'contains', value: nl ? 'factuur' : 'invoice' }],
    actions: { move: 'Band', trash: false, read: false, flag: true }, times_applied: 12, last_applied_at: new Date().toISOString(),
  }],
  replies: accounts.map((a) => ({ account_id: a.id, email: a.email, active: false, enabled: false, starts_on: null, ends_on: null, subject: '', body: '', contacts_only: false })),
  templates: [{ id: 1, name: nl ? 'Bedankt' : 'Thanks', subject: '', html: nl ? '<p>Bedankt voor je bericht! Ik kom er snel op terug.</p>' : '<p>Thanks for your message! I will get back to you soon.</p>' }],
  nextId: 10,
}
// Request bodies can be Vue proxies, which structuredClone refuses.
const clone = (v) => JSON.parse(JSON.stringify(v))
function extrasMock(method, path, body) {
  const id = Number(path.split('/')[2])
  if (path === '/rules' && method === 'GET') return clone(demo.rules)
  if (path === '/rules' && method === 'POST') { const r = { id: demo.nextId++, times_applied: 0, last_applied_at: null, ...clone(body) }; demo.rules.push(r); return r }
  if (path === '/rules/order') { demo.rules = body.ids.map((i) => demo.rules.find((r) => r.id === i)); return clone(demo.rules) }
  if (path === '/rules/apply') return { messages: 42, matched: 3 }
  if (path.startsWith('/rules/') && (method === 'PUT' || method === 'PATCH')) { const r = demo.rules.find((x) => x.id === id); Object.assign(r, clone(body)); return clone(r) }
  if (path.startsWith('/rules/') && method === 'DELETE') { demo.rules = demo.rules.filter((x) => x.id !== id); return null }
  if (path === '/autoreplies') return clone(demo.replies)
  if (path.startsWith('/autoreplies/') && method === 'PUT') {
    const r = demo.replies.find((x) => x.account_id === id)
    Object.assign(r, body, { starts_on: body.starts_on || null, ends_on: body.ends_on || null })
    const today = new Date().toISOString().slice(0, 10)
    r.active = !!r.enabled && !!r.body && (!r.starts_on || today >= r.starts_on) && (!r.ends_on || today <= r.ends_on)
    return clone(r)
  }
  if (path === '/templates' && method === 'GET') return clone(demo.templates)
  if (path === '/templates' && method === 'POST') { const tpl = { id: demo.nextId++, ...body }; demo.templates.push(tpl); return tpl }
  if (path.startsWith('/templates/') && method === 'DELETE') { demo.templates = demo.templates.filter((x) => x.id !== id); return null }
  return undefined
}

const wait = (ms = 250) => new Promise((r) => setTimeout(r, ms))
const roleToPath = { archive: 'Archive', trash: 'Trash', junk: 'Junk', inbox: 'INBOX' }

export async function mockRequest(method, path, { body = {}, query = {}, form = null, raw = null } = {}) {
  await wait()
  const m = path.match(/^\/accounts\/(\d+)(\/.*)?$/)
  const id = m ? Number(m[1]) : null
  const sub = m?.[2] ?? ''
  // Signature images: the demo keeps them in the browser only.
  if (path === '/signature-images' && method === 'POST') return { url: URL.createObjectURL(form.get('file')) }
  const extra = extrasMock(method, path, body)
  if (extra !== undefined) return extra
  const cal = calendarMock(method, path, { body, query })
  if (cal !== undefined) return cal
  const session = () => ({ user: { id: 1, login_email: accounts[0].email, is_admin: true }, accounts, settings, csrf: 'mock' })

  if (path === '/trusted-senders' && method === 'GET') return trusted
  if (path === '/trusted-senders' && method === 'POST') { const item = { id: trusted.length + 1, ...body }; trusted.push(item); return item }
  if (path.startsWith('/trusted-senders/') && method === 'DELETE') { trusted.splice(trusted.findIndex((x) => x.id === Number(path.split('/').pop())), 1); return null }
  if (path === '/admin/overview') return {
    version: 'mock', php: '8.3', debug: false,
    extensions: { mbstring: true, openssl: true, sodium: true, pdo_mysql: true, iconv: true, fileinfo: true, zip: true, libxml: true },
    libraries: { 'webklex/php-imap': true, 'phpmailer/phpmailer': true, 'sabre/dav': true },
    limits: { upload_max_filesize: '64M', post_max_size: '64M', memory_limit: '256M', max_execution_time: '60' },
    database: { server: 'MariaDB (mock)', migrations: { applied: { '001_initial': '' }, pending: [], error: null } },
    mail_server: { imap: { host: `mail.${D.domain}`, port: 993, ok: true, ms: 24 }, smtp: { host: `mail.${D.domain}`, port: 465, ok: true, ms: 31 } },
    stats: { users: 3, accounts: 4, logins_24h: 6, failed_24h: 2 },
  }
  if (path === '/admin/users') return [
    { id: 1, login_email: D.meEmail, last_login_at: '2026-10-17 17:00:00', blocked: false, is_admin: true, accounts: accounts.map((a) => ({ email: a.email })) },
    { id: 2, login_email: 'lisa@familie.nl', last_login_at: '2026-10-15 09:12:00', blocked: false, is_admin: false, accounts: [{ email: 'lisa@familie.nl' }] },
  ]
  if (path.startsWith('/admin/users/') && method === 'PATCH') return { blocked: body.blocked }
  if (path === '/admin/security') return {
    limits: { window_minutes: 15, max_per_ip: 20, max_per_email: 5 },
    blocked_emails: [{ key: 'a'.repeat(64), email: 'oom@familie.nl', fails: 5 }], blocked_ips: [],
    recent: [{ at: '2026-10-17 16:58:00', email: 'oom@familie.nl', ip: '84.12.3.4', success: false }, { at: '2026-10-17 16:30:00', email: D.meEmail, ip: '84.12.3.9', success: true }],
  }
  if (path === '/admin/settings') return { allowed_login_domains: [D.domain], allowed_from_config: true, user_defaults: settings, admins: [], login_custom_servers: false }

  if (path === '/auth/options') return { custom_servers: false }
  if (path === '/auth/login') { sessionStorage.setItem('pv-mock-login', '1'); return session() }
  if (path === '/auth/me') {
    if (!sessionStorage.getItem('pv-mock-login')) throw Object.assign(new Error('Not logged in'), { status: 401 })
    return session()
  }
  if (path === '/auth/logout') { sessionStorage.removeItem('pv-mock-login'); return null }
  // Large files as a download link (pieces of 1 MB in the demo).
  if (path === '/files' && method === 'GET') return { files: sharedFiles.filter((f) => f.complete), chunk_bytes: 1024 * 1024, max_bytes: 2 * 1024 ** 3, days: 30, attach_max_bytes: 20 * 1024 * 1024 }
  if (path === '/files' && method === 'POST') {
    const token = Math.random().toString(16).slice(2).padEnd(32, '0').slice(0, 32)
    const f = { token, name: body.name, size: body.size, mime: body.mime, received: 0, complete: false, downloads: 0, url: `/api/files/${token}`, created_at: new Date().toISOString(), expires_at: new Date(Date.now() + 30 * 86400000).toISOString() }
    sharedFiles.push(f)
    return { ...f }
  }
  const chunkMatch = path.match(/^\/files\/(\w+)\/chunk$/)
  if (chunkMatch) {
    const f = sharedFiles.find((x) => x.token === chunkMatch[1])
    if (Number(query.offset) === f.received) f.received += raw.size
    f.complete = f.received >= f.size
    return { ...f }
  }
  if (path.startsWith('/files/') && method === 'DELETE') { sharedFiles = sharedFiles.filter((x) => `/files/${x.token}` !== path); return { ok: true } }

  if (path === '/settings' && method === 'PATCH') return (settings = { ...settings, ...body })
  if (path === '/settings') return settings
  if (sub === '/scheduled' && method === 'POST') return { id: 1, send_at: new Date(Date.now() + 86400000).toISOString() }
  if (path.startsWith('/scheduled/')) return path.endsWith('/cancel') ? { account_id: 1, folder: 'Drafts', uid: null } : { sent: true }
  if ((sub === '/snooze' || sub === '/unsnooze') && method === 'POST') return { snoozed: (body.uids || []).length, returned: (body.uids || []).length }
  if (path === '/security') return { enabled: false, enabled_at: null, recovery_left: 0, devices: [] }
  if (path === '/security/2fa/setup') return { secret: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', uri: `otpauth://totp/Postvak:${encodeURIComponent(D.meEmail)}?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=Postvak` }
  if (path === '/security/2fa/enable' || path === '/security/2fa/recovery') return { recovery_codes: ['abcd-efgh', 'jkmn-pqrs', 'tuvw-xyz2', '3456-789a', 'bcde-fghj', 'kmnp-qrst', 'uvwx-yz23', '4567-89ab', 'cdef-ghjk', 'mnpq-rstu'], status: { enabled: true, enabled_at: new Date().toISOString(), recovery_left: 10, devices: [] } }
  if (path.startsWith('/security/')) return { enabled: false, enabled_at: null, recovery_left: 0, devices: [] }
  if (path === '/push') return { public_key: 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U', devices: 0, cron: { last_run: null, ok: false } }
  if (path.startsWith('/push/')) return { devices: 1, sent: true }
  if (path === '/contacts/list') return contactsDb.filter((c) => !query.saved || c.saved)
  if (path === '/contacts/import') return { cards: 0, added: 0, updated: 0, skipped: 0 }
  if (path === '/contacts' && method === 'POST') {
    if (contactsDb.some((c) => c.email === body.email && c.saved)) throw Object.assign(new Error('This address is already in your contacts'), { status: 409 })
    const c = { id: contactsDb.length + 1, name: '', phone: '', notes: '', favorite: false, times_used: 0, last_used_at: null, ...body, saved: true }
    contactsDb.push(c)
    return c
  }
  if (path.startsWith('/contacts/') && method === 'PATCH') return Object.assign(contactsDb.find((c) => c.id === Number(path.split('/')[2])), body, { saved: true })
  if (path.startsWith('/contacts/') && method === 'DELETE') { contactsDb.splice(contactsDb.findIndex((c) => c.id === Number(path.split('/')[2])), 1); return null }
  if (path === '/contacts') return senders.filter(([n, e]) => (n + e).toLowerCase().includes((query.q || '').toLowerCase())).map(([name, email]) => ({ name, email }))
  if (path === '/accounts' && method === 'POST') {
    const acc = { ...accounts[1], id: accounts.length + 1, email: body.email, display_name: body.display_name || '', color: '#C98A06', is_login: false }
    accounts.push(acc)
    return acc
  }

  if (sub === '' && method === 'PATCH') return Object.assign(accounts.find((a) => a.id === id), body)
  if (sub === '' && method === 'DELETE') { accounts.splice(accounts.findIndex((a) => a.id === id), 1); return null }
  if (sub === '/folders' && method === 'POST') { extraFolders.push(body.name); return { path: body.name, folders: folders(id) } }
  if (sub === '/folders' && method === 'PATCH') { const i = extraFolders.indexOf(body.path); if (i >= 0) extraFolders[i] = body.name; return { path: body.name, folders: folders(id) } }
  if (sub === '/folders' && method === 'DELETE') { const p = new URLSearchParams(path.split('?')[1]).get('path'); extraFolders.splice(extraFolders.indexOf(p), 1); return { folders: folders(id) } }
  if (sub === '/folders/empty' && method === 'POST') { const b = box(id, body.path); const n = b.length; b.splice(0); return { deleted: n } }
  if (sub.startsWith('/folders')) return folders(id)

  if (path === '/unified/messages') {
    let all = accounts.flatMap((a) => box(a.id, 'INBOX')).sort((a, b) => b.date.localeCompare(a.date))
    if (query.q) all = all.filter((x) => (x.subject + x.from.name).toLowerCase().includes(query.q.toLowerCase()))
    if (query.label) all = all.filter((x) => (x.labels ?? []).includes(query.label.toLowerCase()))
    all = applyFilters(all, query)
    const page = Number(query.page || 1), per = Number(query.per_page || 50)
    return { total: all.length, page, per_page: per, messages: all.slice((page - 1) * per, page * per), errors: [] }
  }
  if (sub === '/drafts' && method === 'POST') {
    const d = box(id, 'Drafts')
    const uid = 5000 + d.length + Math.floor(Math.random() * 1000)
    d.unshift({ uid, folder: 'Drafts', account_id: id, subject: '(concept)', from: { name: '', email: accounts[0].email }, to: [], date: new Date().toISOString(), seen: true, flagged: false, answered: false, has_attachments: false, snippet: '' })
    return { uid, folder: 'Drafts', saved_at: new Date().toISOString(), attachments: [] }
  }
  if (sub.startsWith('/drafts/') && method === 'DELETE') { const d = box(id, 'Drafts'); d.splice(d.findIndex((x) => x.uid === Number(sub.split('/').pop())), 1); return null }

  if (sub === '/messages') {
    let list = box(id, query.folder || 'INBOX')
    if (query.q) list = list.filter((x) => (x.subject + x.from.name).toLowerCase().includes(query.q.toLowerCase()))
    if (query.label) list = list.filter((x) => (x.labels ?? []).includes(query.label.toLowerCase()))
    list = applyFilters(list, query)
    const by = {
      date_desc: (a, b) => b.date.localeCompare(a.date),
      date_asc: (a, b) => a.date.localeCompare(b.date),
      from: (a, b) => a.from.name.localeCompare(b.from.name),
      subject: (a, b) => a.subject.localeCompare(b.subject),
      size_desc: (a, b) => b.uid - a.uid,
    }
    list = [...list].sort(by[query.sort] ?? by.date_desc)
    const page = Number(query.page || 1), per = Number(query.per_page || 50)
    return { total: list.length, page, per_page: per, messages: list.slice((page - 1) * per, page * per) }
  }

  const conv = sub.match(/^\/messages\/(\d+)\/conversation$/)
  if (conv) return { messages: box(id, query.folder || 'INBOX').filter((x) => x.uid === Number(conv[1])) }
  const show = sub.match(/^\/messages\/(\d+)$/)
  if (show) {
    const msg = box(id, query.folder).find((x) => x.uid === Number(show[1]))
    const images = query.images
    return {
      ...msg, cc: [], reply_to: [], message_id: `<${msg.uid}@example>`, references: '',
      blocked_images: images ? 0 : 1,
      html: `${D.body(msg.subject)}
             <p>${images ? '<img src="https://picsum.photos/480/200" alt="" />' : '<img alt="" />'}</p>
             <p>${D.bye},<br>${msg.from.name}</p>`,
      attachments: msg.has_attachments ? [{ index: 0, name: D.attachment, mime: 'application/pdf', size: 184320, inline: false, url: '#' }] : [],
      invitation: invitationFor(msg),
    }
  }
  const invite = sub.match(/^\/messages\/(\d+)\/invitation$/)
  if (invite && method === 'POST') {
    const inv = invitations[Number(invite[1])]
    const inCal = ['accepted', 'tentative'].includes(body.response)
    Object.assign(inv, { in_calendar: inCal, calendar_status: body.response === 'remove' ? null : body.response })
    return { in_calendar: inCal, calendar_status: inv.calendar_status, replied: !!body.reply, reply_error: null }
  }

  if (sub === '/move') {
    const from = box(id, body.folder)
    const target = roleToPath[body.target] ?? body.target
    for (const uid of body.uids) {
      const i = from.findIndex((x) => x.uid === uid)
      if (i >= 0) {
        const [msg] = from.splice(i, 1)
        if (target !== body.folder) box(id, target).unshift({ ...msg, folder: target })
      }
    }
    return { moved: body.uids.length, target }
  }

  if (sub === '/labels') {
    for (const uid of body.uids) {
      const msg = box(id, body.folder).find((x) => x.uid === uid)
      if (!msg) continue
      const set = new Set(msg.labels ?? [])
      for (const k of body.add ?? []) set.add(k.toLowerCase())
      for (const k of body.remove ?? []) set.delete(k.toLowerCase())
      msg.labels = [...set]
    }
    return { ok: true }
  }
  if (sub === '/flags') {
    for (const uid of body.uids) {
      const msg = box(id, body.folder).find((x) => x.uid === uid)
      if (msg) Object.assign(msg, Object.fromEntries(Object.entries(body).filter(([k]) => ['seen', 'flagged', 'answered'].includes(k))))
    }
    return { ok: true }
  }

  if (sub === '/send') return { sent: true }

  throw new Error(`mock: no handler for ${method} ${path}`)
}
