// Fake backend for "npm run dev:mock". Lets you work on the UI without PHP or a mail server.
// Data lives in memory and resets on reload.

const accounts = [
  { id: 1, email: 'ton@example.nl', display_name: 'Ton', color: '#3646D9', is_login: true, sort_order: 0,
    imap_host: 'mail.example.nl', imap_port: 993, imap_encryption: 'ssl',
    smtp_host: 'mail.example.nl', smtp_port: 465, smtp_encryption: 'ssl', username: 'ton@example.nl', signature_html: '' },
  { id: 2, email: 'info@bandnaam.nl', display_name: 'Info', color: '#0F8A5F', is_login: false, sort_order: 1,
    imap_host: 'mail.bandnaam.nl', imap_port: 993, imap_encryption: 'ssl',
    smtp_host: 'mail.bandnaam.nl', smtp_port: 465, smtp_encryption: 'ssl', username: 'info@bandnaam.nl', signature_html: '' },
]

let settings = {
  swipe_left: 'trash', swipe_right: 'archive', confirm_delete: false, undo_seconds: 5,
  theme: 'system', density: 'comfortable', show_images: 'ask', reading_pane: 'right',
  poll_seconds: 60, unified_inbox: true, mark_read_on_open: true, sort: 'date_desc',
}

const senders = [
  ['Marieke de Vries', 'marieke@voorbeeld.nl'], ['Camping De Heide', 'reserveringen@deheide.nl'],
  ['Cloud86', 'support@cloud86.nl'], ['Peter Janssen', 'peter.janssen@voorbeeld.nl'],
  ['Repetitieruimte 013', 'planning@ruimte013.nl'], ['Bol', 'noreply@bol.example'],
  ['Gemeente', 'noreply@gemeente.example'], ['Sandra', 'sandra@voorbeeld.nl'],
]
const subjects = [
  'Bevestiging van je reservering', 'Setlist zaterdag', 'Factuur oktober', 'Even bijpraten?',
  'Je pakket is onderweg', 'Nieuwe repetitietijden', 'Foto’s van het optreden', 'Vraag over de hosting',
  'Herinnering: afspraak', 'Uitnodiging verjaardag',
]

function makeMessages(accountId, folder, count) {
  const now = Date.now()
  return Array.from({ length: count }, (_, i) => {
    const [name, email] = senders[(i + accountId) % senders.length]
    return {
      uid: 1000 - i, folder, account_id: accountId,
      subject: subjects[(i * 3 + accountId) % subjects.length],
      from: { name, email }, to: [{ name: '', email: accounts[accountId - 1].email }],
      date: new Date(now - i * 1000 * 60 * 47).toISOString(),
      seen: i > 3 && i % 5 !== 0, flagged: i % 9 === 2, answered: i % 7 === 3, has_attachments: i % 4 === 1,
    }
  })
}

const store = {}
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
]

const trusted = [{ id: 1, kind: 'domain', value: 'bol.com' }]

const wait = (ms = 250) => new Promise((r) => setTimeout(r, ms))
const roleToPath = { archive: 'Archive', trash: 'Trash', junk: 'Junk', inbox: 'INBOX' }

export async function mockRequest(method, path, { body = {}, query = {} } = {}) {
  await wait()
  const m = path.match(/^\/accounts\/(\d+)(\/.*)?$/)
  const id = m ? Number(m[1]) : null
  const sub = m?.[2] ?? ''
  const session = () => ({ user: { id: 1, login_email: accounts[0].email, is_admin: true }, accounts, settings, csrf: 'mock' })

  if (path === '/trusted-senders' && method === 'GET') return trusted
  if (path === '/trusted-senders' && method === 'POST') { const item = { id: trusted.length + 1, ...body }; trusted.push(item); return item }
  if (path.startsWith('/trusted-senders/') && method === 'DELETE') { trusted.splice(trusted.findIndex((x) => x.id === Number(path.split('/').pop())), 1); return null }
  if (path === '/admin/overview') return {
    version: '0.2.0-mock', php: '8.3', debug: false,
    extensions: { mbstring: true, openssl: true, sodium: true, pdo_mysql: true, iconv: true, fileinfo: true, zip: true, libxml: true },
    limits: { upload_max_filesize: '64M', post_max_size: '64M', memory_limit: '256M', max_execution_time: '60' },
    database: { server: 'MariaDB (mock)', migrations: { applied: { '001_initial': '' }, pending: [] } },
    mail_server: { imap: { host: 'mail.example.nl', port: 993, ok: true, ms: 24 }, smtp: { host: 'mail.example.nl', port: 465, ok: true, ms: 31 } },
    stats: { users: 3, accounts: 4, logins_24h: 6, failed_24h: 2 },
  }
  if (path === '/admin/users') return [
    { id: 1, login_email: 'ton@example.nl', last_login_at: '2026-10-17 17:00:00', blocked: false, is_admin: true, accounts: accounts.map((a) => ({ email: a.email })) },
    { id: 2, login_email: 'lisa@familie.nl', last_login_at: '2026-10-15 09:12:00', blocked: false, is_admin: false, accounts: [{ email: 'lisa@familie.nl' }] },
  ]
  if (path.startsWith('/admin/users/') && method === 'PATCH') return { blocked: body.blocked }
  if (path === '/admin/security') return {
    limits: { window_minutes: 15, max_per_ip: 20, max_per_email: 5 },
    blocked_emails: [{ key: 'a'.repeat(64), email: 'oom@familie.nl', fails: 5 }], blocked_ips: [],
    recent: [{ at: '2026-10-17 16:58:00', email: 'oom@familie.nl', ip: '84.12.3.4', success: false }, { at: '2026-10-17 16:30:00', email: 'ton@example.nl', ip: '84.12.3.9', success: true }],
  }
  if (path === '/admin/settings') return { allowed_login_domains: ['example.nl'], allowed_from_config: true, user_defaults: settings, admins: [] }

  if (path === '/auth/login') { sessionStorage.setItem('pv-mock-login', '1'); return session() }
  if (path === '/auth/me') {
    if (!sessionStorage.getItem('pv-mock-login')) throw Object.assign(new Error('Not logged in'), { status: 401 })
    return session()
  }
  if (path === '/auth/logout') { sessionStorage.removeItem('pv-mock-login'); return null }
  if (path === '/settings' && method === 'PATCH') return (settings = { ...settings, ...body })
  if (path === '/settings') return settings
  if (path === '/contacts') return senders.filter(([n, e]) => (n + e).toLowerCase().includes((query.q || '').toLowerCase())).map(([name, email]) => ({ name, email }))
  if (path === '/accounts' && method === 'POST') {
    const acc = { ...accounts[1], id: accounts.length + 1, email: body.email, display_name: body.display_name || '', color: '#C98A06', is_login: false }
    accounts.push(acc)
    return acc
  }

  if (sub === '' && method === 'PATCH') return Object.assign(accounts.find((a) => a.id === id), body)
  if (sub === '' && method === 'DELETE') { accounts.splice(accounts.findIndex((a) => a.id === id), 1); return null }
  if (sub === '/folders') return folders(id)

  if (sub === '/messages') {
    let list = box(id, query.folder || 'INBOX')
    if (query.q) list = list.filter((x) => (x.subject + x.from.name).toLowerCase().includes(query.q.toLowerCase()))
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

  const show = sub.match(/^\/messages\/(\d+)$/)
  if (show) {
    const msg = box(id, query.folder).find((x) => x.uid === Number(show[1]))
    const images = query.images
    return {
      ...msg, cc: [], reply_to: [], message_id: `<${msg.uid}@example>`, references: '',
      blocked_images: images ? 0 : 1,
      html: `<p>Hoi Ton,</p><p>Dit is een voorbeeldbericht om de interface te testen. ${msg.subject.toLowerCase()}, zoals besproken.</p>
             <p>${images ? '<img src="https://picsum.photos/480/200" alt="" />' : '<img alt="" />'}</p>
             <p>Groet,<br>${msg.from.name}</p>`,
      attachments: msg.has_attachments ? [{ index: 0, name: 'overzicht.pdf', mime: 'application/pdf', size: 184320, inline: false, url: '#' }] : [],
    }
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
