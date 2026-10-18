/*
 * Postvak — push notifications. Loaded into the generated service worker
 * (vite.config.js → workbox.importScripts). Plain JS: no build step, no imports.
 *
 * The server sends small JSON payloads:
 *   { type: 'test' }
 *   { type: 'mail', account_id, account (label or null), total, messages: [{ uid, from, subject, snippet }] }
 *   { type: 'notice', kind: 'send_failed', subject, error, url }   (a scheduled message could not be sent)
 * The texts below are the only UI strings outside src/i18n (a service worker cannot import them);
 * the server adds the device's language as data.lang.
 */
const TEXTS = {
  nl: {
    test: 'Meldingen werken! Je krijgt hier voortaan een bericht als er nieuwe mail is.',
    noSubject: '(geen onderwerp)',
    newMail: 'Nieuwe mail',
    many: (n) => `${n} nieuwe berichten`,
    sendFailed: 'Versturen mislukt',
    sendFailedBody: (subject) => `"${subject || '(geen onderwerp)'}" kon niet worden verstuurd en staat weer bij Concepten.`,
  },
  en: {
    test: 'Notifications work! You will get a message here when new mail arrives.',
    noSubject: '(no subject)',
    newMail: 'New mail',
    many: (n) => `${n} new messages`,
    sendFailed: 'Sending failed',
    sendFailedBody: (subject) => `"${subject || '(no subject)'}" could not be sent and is back in Drafts.`,
  },
}
const ICON = 'icon-192.png'
const BADGE = 'badge-96.png'
const MAX_SINGLE = 3

self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = {}
  }
  event.waitUntil(handlePush(data))
})

async function handlePush(data) {
  const TEXT = TEXTS[data.lang] || TEXTS.nl
  const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
  // Open app windows refresh their list.
  windows.forEach((w) => w.postMessage({ type: 'postvak:new-mail', data }))

  if (data.type === 'test') {
    return self.registration.showNotification('Postvak', { body: TEXT.test, icon: ICON, badge: BADGE, tag: 'postvak-test' })
  }
  if (data.type === 'notice' && data.kind === 'send_failed') {
    return self.registration.showNotification(TEXT.sendFailed, {
      body: TEXT.sendFailedBody(data.subject),
      icon: ICON,
      badge: BADGE,
      tag: 'postvak-send-failed',
      data: { url: data.url || '' },
    })
  }
  if (data.type !== 'mail' || !Array.isArray(data.messages)) return
  // Postvak is open in front of the user: the list updates itself, no popup needed.
  if (windows.some((w) => w.focused && w.visibilityState === 'visible')) return

  const account = data.account ? ` · ${data.account}` : ''
  const base = `mail/${data.account_id}`

  if (data.total > MAX_SINGLE || data.total > data.messages.length) {
    return self.registration.showNotification(TEXT.many(data.total) + account, {
      body: data.messages.map((m) => `${m.from}: ${m.subject || TEXT.noSubject}`).join('\n'),
      icon: ICON,
      badge: BADGE,
      tag: `mail-${data.account_id}-summary`,
      renotify: true,
      data: { url: base },
    })
  }

  // Oldest first, so the newest ends up on top.
  for (const m of [...data.messages].reverse()) {
    await self.registration.showNotification((m.from || TEXT.newMail) + account, {
      body: [m.subject || TEXT.noSubject, m.snippet].filter(Boolean).join('\n'),
      icon: ICON,
      badge: BADGE,
      tag: `mail-${data.account_id}-${m.uid}`,
      data: { url: `${base}?uid=${m.uid}&acc=${data.account_id}` },
    })
  }
}

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = new URL(event.notification.data?.url || '', self.registration.scope).href
  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    const win = windows.find((w) => w.url.startsWith(self.registration.scope))
    if (win) {
      await win.focus()
      win.postMessage({ type: 'postvak:open', url })
      return
    }
    await self.clients.openWindow(url)
  })())
})
