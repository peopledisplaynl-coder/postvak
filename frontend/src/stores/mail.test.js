import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('../api/client.js', () => ({
  api: { get: vi.fn(async () => []), post: vi.fn(async () => ({})), patch: vi.fn(), del: vi.fn(), upload: vi.fn() },
  request: vi.fn(),
}))

const { api } = await import('../api/client.js')
const { useMail, uidsOf, msgKey } = await import('./mail.js')
const { useSession } = await import('./session.js')
const { useToasts } = await import('./toasts.js')
const { setLanguage } = await import('../i18n/index.js')

const msg = (uid, extra = {}) => ({ uid, account_id: 1, folder: 'INBOX', subject: `m${uid}`, seen: false, flagged: false, ...extra })

describe('mail store: archive/delete with undo', () => {
  let mail, toasts
  beforeEach(() => {
    vi.useFakeTimers()
    setLanguage('nl')
    setActivePinia(createPinia())
    useSession().settings = { undo_seconds: 5, confirm_delete: false }
    mail = useMail()
    toasts = useToasts()
    mail.accountId = 1
    mail.folderPath = 'INBOX'
    mail.folders = { 1: [{ path: 'INBOX', role: 'inbox', unseen: 3, total: 3 }] }
    mail.messages = [msg(10), msg(11, { thread: { count: 3, uids: [11, 7, 5], unseen: 2 } }), msg(12)]
    mail.total = 3
    api.post.mockClear()
  })
  afterEach(() => vi.useRealTimers())

  it('removes rows at once and calls the server after the undo window, with all uids of a conversation', async () => {
    mail.act('archive', mail.messages[1])
    expect(mail.messages.map(msgKey)).toEqual(['1:10', '1:12'])
    expect(api.post).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(5000)
    expect(api.post).toHaveBeenCalledWith('/accounts/1/move', { folder: 'INBOX', uids: [11, 7, 5], target: 'archive' }, { keepalive: false })
  })

  it('undo puts the rows back in their place and nothing is sent', async () => {
    mail.removeAction('trash', [mail.messages[0], mail.messages[2]])
    expect(mail.messages.length).toBe(1)
    const toast = toasts.items.at(-1)
    expect(toast.text).toContain('2 berichten verwijderd')
    toast.action.run()
    expect(mail.messages.map((m) => m.uid)).toEqual([10, 11, 12])
    await vi.advanceTimersByTimeAsync(10000)
    expect(api.post).not.toHaveBeenCalled()
  })

  it('counts every message of a conversation in the toast', () => {
    mail.removeAction('archive', [mail.messages[1]])
    expect(toasts.items.at(-1).text).toContain('3 berichten gearchiveerd')
  })

  it('one request per mailbox in the unified inbox', async () => {
    mail.messages = [msg(1), msg(2, { account_id: 2 }), msg(3)]
    mail.removeAction('archive', [...mail.messages])
    await vi.advanceTimersByTimeAsync(5000)
    expect(api.post).toHaveBeenCalledTimes(2)
    expect(api.post.mock.calls.map((c) => [c[0], c[1].uids])).toEqual([['/accounts/1/move', [1, 3]], ['/accounts/2/move', [2]]])
  })

  it('marking a conversation read sets all its messages and the unread counter', async () => {
    await mail.setFlag(mail.messages[1], 'seen', true)
    expect(api.post).toHaveBeenCalledWith('/accounts/1/flags', { folder: 'INBOX', uids: [11, 7, 5], seen: true })
    expect(mail.messages[1].thread.unseen).toBe(0)
    expect(mail.folders[1][0].unseen).toBe(1)
  })

  it('uidsOf: message vs conversation', () => {
    expect(uidsOf(msg(4))).toEqual([4])
    expect(uidsOf(msg(4, { thread: { uids: [4, 2] } }))).toEqual([4, 2])
  })
})

describe('olderThan: a refresh keeps what was scrolled in', async () => {
  const { olderThan } = await import('./mail.js')
  const list = (...uids) => uids.map((u) => msg(u))
  it('keeps the older messages after the new first page', () => {
    const old = list(10, 9, 8, 7, 6, 5)
    const fresh = list(11, 10, 9) // a new message arrived
    expect(olderThan(old, fresh).map((m) => m.uid)).toEqual([8, 7, 6, 5])
  })
  it('drops nothing twice and starts over when the pages no longer overlap', () => {
    expect(olderThan(list(5, 4), list(9, 8))).toBeNull()
    expect(olderThan(list(5, 4), [])).toBeNull()
  })
})
