import { useMail } from './stores/mail.js'
import { useSession } from './stores/session.js'
import { useToasts } from './stores/toasts.js'
import { api, attachmentUrl } from './api/client.js'
import { labelName } from './labels.js'
import { displayName } from './format.js'
import { t } from './i18n/index.js'

/**
 * The actions for one or more messages, as menu items (for the ⋮ menu of the reader on small screens and the
 * right-click menu of the list). An item: { id, icon, label, run, danger?, checked?, keepOpen?, children? };
 * null is a divider.
 *
 * ctx = {
 *   messages,          list rows the actions apply to
 *   extra,             the reader's message object (labels kept in step), optional
 *   onReply(mode),     reply / replyAll / forward (single message)
 *   onMove(),          open the folder picker
 *   onOpen(),          open the message (list only)
 *   onRemoved(),       after archive / delete / spam
 *   onMoreFromSender() optional; default: search the list for the sender
 *   muted,             the conversation is ignored (known in the reader)
 *   router,            for "make a rule"
 * }
 */
export function messageActions(ctx) {
  const mail = useMail()
  const session = useSession()
  const messages = ctx.messages
  const single = messages.length === 1 ? messages[0] : null
  const role = mail.folderRole
  const own = (m) => session.accounts.some((a) => a.email.toLowerCase() === m?.from?.email?.toLowerCase())
  const allSeen = messages.every((m) => m.seen)
  const allFlagged = messages.every((m) => m.flagged)
  const items = []
  const add = (item) => items.push(item)

  if (ctx.onOpen && single) add({ id: 'open', icon: 'inbox', label: t('menu.open'), run: ctx.onOpen })
  if (single && ctx.onReply && role !== 'drafts') {
    add({ id: 'reply', icon: 'reply', label: t('actions.reply'), run: () => ctx.onReply('reply') })
    add({ id: 'replyAll', icon: 'replyAll', label: t('actions.replyAll'), run: () => ctx.onReply('replyAll') })
    add({ id: 'forward', icon: 'forward', label: t('actions.forward'), run: () => ctx.onReply('forward') })
    add(null)
  }

  const remove = (action) => () => {
    if (mail.removeAction(action, messages)) ctx.onRemoved?.()
  }
  if (role !== 'archive') add({ id: 'archive', icon: 'archive', label: t('actions.archive'), run: remove('archive') })
  add({ id: 'trash', icon: 'trash', label: role === 'trash' ? t('actions.deleteForever') : t('actions.trash'), run: remove('trash'), danger: true })
  if (!['sent', 'drafts', 'scheduled'].includes(role)) {
    add({ id: 'junk', icon: role === 'junk' ? 'inbox' : 'junk', label: role === 'junk' ? t('actions.notJunk') : t('actions.junk'), run: remove('junk') })
  }
  if (role === 'snoozed') add({ id: 'unsnooze', icon: 'inbox', label: t('actions.unsnooze'), run: () => { mail.unsnooze(messages); ctx.onRemoved?.() } })
  add(null)

  add({
    id: 'read', icon: allSeen ? 'unread' : 'read', label: allSeen ? t('actions.markUnread') : t('menu.markRead'),
    run: () => mail.setFlagMany(messages, 'seen', !allSeen, { undo: messages.length > 1 }),
  })
  add({
    id: 'flag', icon: 'flag', label: allFlagged ? t('menu.unflag') : t('menu.flag'),
    run: () => mail.setFlagMany(messages, 'flagged', !allFlagged, { undo: messages.length > 1 }),
  })
  if (!['drafts', 'sent', 'scheduled'].includes(role)) {
    add({ id: 'snooze', icon: 'clock', label: role === 'snoozed' ? t('later.otherTime') : t('actions.snooze'), run: () => (mail.snoozeRequest = messages) })
  }
  if (ctx.onMove) add({ id: 'move', icon: 'move', label: t('actions.move'), run: ctx.onMove })

  const labels = session.settings.labels ?? []
  if (labels.length) {
    const extra = ctx.extra ?? []
    const targets = messages.length ? messages : extra
    const has = (m, key) => (m.labels ?? []).some((k) => k.toLowerCase() === key.toLowerCase())
    add({
      id: 'labels', icon: 'tag', label: t('labels.title'),
      // `checked` is read live, so the menu stays open and shows the change.
      children: labels.map((l) => ({
        id: `label:${l.key}`, label: labelName(l), color: l.color, keepOpen: true,
        get checked() { return targets.every((m) => has(m, l.key)) },
        run() { mail.setLabel(messages, l.key, !this.checked, extra) },
      })),
    })
  }
  add(null)

  if (role !== 'sent' && role !== 'drafts') {
    add({
      id: 'mute', icon: 'bell', label: ctx.muted ? t('mute.off') : t('mute.on'),
      run: () => setMuted(messages, !ctx.muted, ctx.onRemoved),
    })
  }
  add(null)

  if (single) {
    add({ id: 'task', icon: 'task', label: t('tasks.fromMail'), run: () => makeTask(single, ctx.router) })
    if (single.from?.email && !own(single)) {
      add({
        id: 'rule', icon: 'filter', label: t('rules.fromMessage'),
        run: () => ctx.router.push({ path: '/settings/rules', query: { from: single.from.email, name: displayName(single.from), account: single.account_id } }),
      })
      add({
        id: 'sender', icon: 'search', label: t('filters.moreFrom'),
        run: () => (ctx.onMoreFromSender ? ctx.onMoreFromSender(single.from.email) : mail.searchFrom(single.from.email)),
      })
      add({ id: 'block', icon: 'shield', label: t('block.menu'), danger: true, run: () => blockSender(single, ctx.onRemoved) })
    }
    add(null)
    const source = `accounts/${single.account_id}/messages/${single.uid}/source?folder=${encodeURIComponent(single.folder)}`
    add({ id: 'source', icon: 'eye', label: t('source.show'), run: () => window.open(attachmentUrl(source), '_blank', 'noopener') })
    add({ id: 'eml', icon: 'download', label: t('source.download'), run: () => { location.href = attachmentUrl(`${source}&download=1`) } })
  }

  return tidy(items)
}

/** No divider at the start or end, nor two in a row (also after leaving items out). */
export function tidy(items) {
  const out = []
  for (const item of items) {
    if (item === null && (out.length === 0 || out.at(-1) === null)) continue
    out.push(item)
  }
  while (out.at(-1) === null) out.pop()
  return out
}

/** Ignore a conversation (new replies are archived by the cron job) and archive it now, like Gmail. */
export async function setMuted(messages, on, onRemoved) {
  const mail = useMail()
  const toasts = useToasts()
  try {
    for (const m of messages) await api.post(`/accounts/${m.account_id}/messages/${m.uid}/mute`, { folder: m.folder, muted: on })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
    return
  }
  if (on && mail.folderRole !== 'archive' && mail.removeAction('archive', messages)) onRemoved?.()
  if (!on) toasts.show(t('mute.offDone'))
}

/** Block a sender: a rule moves their mail to the trash from now on; this message goes there too. Undo removes the rule. */
export async function blockSender(message, onRemoved) {
  const mail = useMail()
  const toasts = useToasts()
  const email = message.from.email.toLowerCase()
  let rule
  try {
    rule = await api.post('/rules', {
      name: t('block.ruleName', { email }), account_id: null, enabled: true, match_all: true,
      conditions: [{ field: 'from', op: 'is', value: email }], actions: { trash: true },
    })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
    return
  }
  const unblock = () => api.del(`/rules/${rule.id}`).catch(() => {})
  if (mail.folderRole !== 'trash') {
    // One "Undo" for both: the message comes back and the rule goes.
    mail.removeManyWithUndo([message], 'trash', t('block.done', { email }), unblock)
    onRemoved?.()
  } else {
    toasts.show(t('block.done', { email }), { seconds: 8, action: { label: t('actions.undo'), run: unblock } })
  }
}

/** Mail → task: a task with a link back to this message. */
export async function makeTask(s, router) {
  const toasts = useToasts()
  try {
    await api.post('/tasks', {
      title: s.subject || t('list.noSubject'),
      mail: { account_id: s.account_id, folder: s.folder, uid: s.uid, subject: s.subject, from: displayName(s.from) },
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    })
    toasts.show(t('tasks.createdFromMail'), { action: { label: t('tasks.show'), run: () => router?.push('/tasks') } })
  } catch (e) {
    toasts.error(t('toast.failed', { msg: e.message }))
  }
}
