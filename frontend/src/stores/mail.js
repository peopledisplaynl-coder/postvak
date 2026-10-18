import { defineStore } from 'pinia'
import { timeZone } from '../calendar.js'
import { api } from '../api/client.js'
import { t, locale } from '../i18n/index.js'
import { useSession } from './session.js'
import { useToasts } from './toasts.js'

const PER_PAGE = 50

/** The id "all" stands for the unified inbox (the inboxes of every account). */
export const ALL = 'all'

/** Unique key of a message in a list: the same uid can exist in two accounts. */
export const msgKey = (m) => `${m.account_id}:${m.uid}`

/** A list row is a message, or a conversation (row.thread.uids = all its messages in this folder). */
export const uidsOf = (m) => m.thread?.uids ?? [m.uid]

/** Key for pending (undo window) actions: also includes the folder. */
const pendingKey = (m) => `${m.account_id}|${m.folder}|${m.uid}`

/** Group messages per account + folder, because every server call is per mailbox. */
function groupByMailbox(messages) {
  const groups = new Map()
  for (const m of messages) {
    const k = `${m.account_id}|${m.folder}`
    if (!groups.has(k)) groups.set(k, { accountId: m.account_id, folder: m.folder, messages: [] })
    groups.get(k).messages.push(m)
  }
  return [...groups.values()]
}

/**
 * Mail state for the open account (or the unified inbox) and folder.
 *
 * Archive/delete/move use "deferred commit": messages disappear from the list
 * right away, but the server call only happens after the undo window.
 * Undo simply puts them back. Pending actions are flushed when the page closes.
 */
export const useMail = defineStore('mail', {
  state: () => ({
    accountId: null,      // number, or ALL
    folderPath: 'INBOX',
    folders: {},          // accountId -> folder list
    messages: [],
    total: 0,
    page: 1,
    loading: false,
    loadingMore: false,
    search: '',
    searchBody: false,    // also search the message text (slower)
    openKey: null,        // msgKey of the message in the reader
    selected: [],         // msgKeys of selected messages (multi-select)
    pending: new Map(),   // pendingKey -> batch
    drag: null,           // { messages, accountId } while messages are dragged (accountId null = mixed)
    snoozeRequest: null,  // messages waiting for a snooze time (the picker is shown by MailView)
    labelFilter: null,    // only messages with this label (IMAP keyword) in the current folder
  }),

  getters: {
    unified: (s) => s.accountId === ALL,
    currentFolders: (s) => (s.accountId === ALL ? [] : s.folders[s.accountId] ?? []),
    currentFolder(s) {
      return this.currentFolders.find((f) => f.path === s.folderPath)
    },
    hasMore: (s) => s.messages.length < s.total,
    folderRole() {
      return this.unified ? 'inbox' : this.currentFolder?.role ?? null
    },
    sort: () => useSession().settings.sort ?? 'date_desc',
    /** Ask for conversations? Only in date order (like Gmail), never for drafts. */
    wantThreads() {
      return useSession().settings.conversations !== false && this.sort === 'date_desc' && this.folderRole !== 'drafts' && !this.labelFilter
    },
    selecting: (s) => s.selected.length > 0,
    selectedMessages: (s) => s.messages.filter((m) => s.selected.includes(msgKey(m))),
    byKey: (s) => (key) => s.messages.find((m) => msgKey(m) === key),
    /** Unread messages in all inboxes (for the unified inbox entry). */
    unifiedUnseen: (s) => Object.values(s.folders).reduce((n, list) => n + (list.find((f) => f.role === 'inbox')?.unseen ?? 0), 0),
  },

  actions: {
    async open(accountId, folderPath = 'INBOX') {
      const changed = accountId !== this.accountId || folderPath !== this.folderPath
      this.accountId = accountId
      this.folderPath = accountId === ALL ? 'INBOX' : folderPath
      if (changed) {
        this.messages = []
        this.total = 0
        this.openKey = null
        this.selected = []
        this.labelFilter = null
      }
      await Promise.all([this.loadFolders(), this.loadMessages()])
    },

    async loadFolders(accountId = this.accountId) {
      const ids = accountId === ALL ? useSession().accounts.map((a) => a.id) : [accountId]
      await Promise.all(ids.map(async (id) => {
        try {
          const folders = await api.get(`/accounts/${id}/folders`)
          if (Array.isArray(folders)) this.folders[id] = folders
        } catch (e) {
          useToasts().error(t('toast.failed', { msg: e.message }))
        }
      }))
    },

    listRequest(page) {
      const query = {
        page, per_page: PER_PAGE, q: this.search, sort: this.sort,
        threads: this.wantThreads ? 1 : undefined,
        body: this.search && this.searchBody ? 1 : undefined,
        label: this.labelFilter || undefined,
      }
      return this.unified
        ? api.get('/unified/messages', query)
        : api.get(`/accounts/${this.accountId}/messages`, { folder: this.folderPath, ...query })
    },

    async loadMessages({ silent = false } = {}) {
      if (!silent) this.loading = true
      const requested = `${this.accountId}:${this.folderPath}:${this.search}:${this.labelFilter}`
      try {
        const data = await this.listRequest(1)
        // Ignore the answer if the user switched folder in the meantime.
        if (requested !== `${this.accountId}:${this.folderPath}:${this.search}:${this.labelFilter}`) return
        // Do not resurrect messages that are waiting to be archived/deleted/moved.
        this.messages = (data?.messages ?? []).filter((m) => !this.pending.has(pendingKey(m)))
        this.total = data?.total ?? this.messages.length
        this.page = 1
        for (const err of data?.errors ?? []) {
          if (!silent) useToasts().error(t('toast.accountFailed', { email: err.email }))
        }
      } catch (e) {
        if (!silent) useToasts().error(t('toast.failed', { msg: e.message }))
      } finally {
        this.loading = false
      }
    },

    async loadMore() {
      if (this.loadingMore || !this.hasMore) return
      this.loadingMore = true
      try {
        const data = await this.listRequest(this.page + 1)
        const known = new Set(this.messages.map(msgKey))
        this.messages.push(...(data?.messages ?? []).filter((m) => !known.has(msgKey(m)) && !this.pending.has(pendingKey(m))))
        this.page = data.page
        this.total = data.total
      } catch (e) {
        useToasts().error(t('toast.failed', { msg: e.message }))
      } finally {
        this.loadingMore = false
      }
    },

    /** Run a swipe/toolbar action on a message: archive, trash, read, flag. */
    async act(action, message) {
      if (action === 'none') return
      if (action === 'snooze') {
        this.snoozeRequest = [message]
        return false // a swiped row springs back; the picker decides
      }
      if (action === 'unsnooze') return this.unsnooze([message])
      if (action === 'read') return this.setFlag(message, 'seen', !message.seen)
      if (action === 'flag') return this.setFlag(message, 'flagged', !message.flagged)
      return this.removeAction(action, [message])
    },

    /**
     * Archive, delete (to trash, or permanently when already in trash) or mark as
     * spam ("junk"; in the spam folder itself: "notjunk" = back to the inbox), with undo.
     */
    removeAction(action, messages) {
      const session = useSession()
      const inTrash = this.folderRole === 'trash'
      if (action === 'archive' && this.folderRole === 'archive') return false
      if (action === 'junk' && this.folderRole === 'junk') action = 'notjunk'
      if (action === 'junk' || action === 'notjunk') {
        const n = messages.flatMap(uidsOf).length
        const label = action === 'junk'
          ? t(n > 1 ? 'toast.junkMany' : 'toast.junk', { n })
          : t(n > 1 ? 'toast.notJunkMany' : 'toast.notJunk', { n })
        this.removeManyWithUndo(messages, action === 'junk' ? 'junk' : 'inbox', label)
        return true
      }
      if (action === 'trash' && (session.settings.confirm_delete || inTrash)) {
        const question = inTrash ? t('actions.deleteForever') : t('actions.trash')
        const count = messages.flatMap(uidsOf).length
        if (!confirm(count > 1 ? `${question}: ${count}?` : `${question}?`)) return false
      }
      const permanent = inTrash && action === 'trash'
      const n = messages.flatMap(uidsOf).length
      const label = permanent
        ? t(n > 1 ? 'toast.deletedMany' : 'toast.deleted', { n })
        : t(action === 'archive' ? (n > 1 ? 'toast.archivedMany' : 'toast.archived') : (n > 1 ? 'toast.trashedMany' : 'toast.trashed'), { n })
      this.removeManyWithUndo(messages, action, label)
      return true
    },

    /** Move messages to a folder path (all messages must be in the same account), with undo. */
    moveTo(messages, targetPath, folderName) {
      messages = messages.filter((m) => m.folder !== targetPath)
      if (!messages.length) return false
      const n = messages.flatMap(uidsOf).length
      this.removeManyWithUndo(messages, targetPath, t(n > 1 ? 'toast.movedMany' : 'toast.moved', { n, folder: folderName }))
      return true
    },

    /**
     * Remove messages from the list right away; the server call happens when the undo
     * window ends (one request per mailbox). Undo puts them back where they were.
     * `target` is a move target (folder path, 'archive', …) or { commit(group, keepalive) }.
     */
    removeManyWithUndo(messages, target, label) {
      const toasts = useToasts()
      const listId = `${this.accountId}:${this.folderPath}`

      const removed = messages
        .map((message) => ({ message, index: this.messages.findIndex((m) => msgKey(m) === msgKey(message)) }))
        .filter((r) => r.index !== -1)
        .sort((a, b) => a.index - b.index)
      if (!removed.length) return

      for (const r of [...removed].reverse()) this.messages.splice(r.index, 1)
      this.total = Math.max(0, this.total - removed.length)
      const keys = removed.map((r) => msgKey(r.message))
      if (keys.includes(this.openKey)) this.openKey = null
      this.selected = this.selected.filter((k) => !keys.includes(k))

      const pKeys = removed.map((r) => pendingKey(r.message))
      const batch = { done: false, timer: null }
      const finish = () => {
        batch.done = true
        clearTimeout(batch.timer)
        pKeys.forEach((k) => this.pending.delete(k))
      }
      batch.commit = async (keepalive = false) => {
        if (batch.done) return
        finish()
        const failed = []
        for (const group of groupByMailbox(removed.map((r) => r.message))) {
          try {
            if (typeof target === 'object') await target.commit(group, keepalive)
            else await api.post(`/accounts/${group.accountId}/move`, { folder: group.folder, uids: group.messages.flatMap(uidsOf), target }, { keepalive })
            this.loadFolders(group.accountId)
          } catch (e) {
            toasts.error(t('toast.failed', { msg: e.message }))
            failed.push(...removed.filter((r) => group.messages.includes(r.message)))
          }
        }
        if (failed.length) this.restore(failed, listId)
      }

      const seconds = Number(useSession().settings.undo_seconds ?? 5)
      if (seconds === 0) {
        batch.commit()
      } else {
        batch.timer = setTimeout(() => batch.commit(), seconds * 1000)
        pKeys.forEach((k) => this.pending.set(k, batch))
      }

      toasts.show(label, {
        seconds: seconds || 3,
        action: seconds ? {
          label: t('actions.undo'),
          run: () => {
            if (batch.done) return
            finish()
            this.restore(removed, listId)
          },
        } : null,
      })
    },

    /** Put removed messages back at their old positions (if that list is still open). */
    restore(removed, listId) {
      if (`${this.accountId}:${this.folderPath}` !== listId) return
      for (const { message, index } of removed) {
        if (this.messages.some((m) => msgKey(m) === msgKey(message))) continue
        this.messages.splice(Math.min(index, this.messages.length), 0, message)
        this.total++
      }
    },

    /** Called on page unload: send everything that is still waiting. */
    flushPending() {
      return Promise.all([...new Set(this.pending.values())].map((batch) => batch.commit(true)))
    },

    // ---------------------------------------------------------------- selection

    toggleSelect(key) {
      this.selected = this.selected.includes(key) ? this.selected.filter((k) => k !== key) : [...this.selected, key]
    },

    selectAll() {
      this.selected = this.messages.map(msgKey)
    },

    clearSelection() {
      this.selected = []
    },

    /** Toolbar action on all selected messages. */
    async bulk(action) {
      const messages = this.selectedMessages
      if (!messages.length) return
      if (action === 'snooze') {
        this.snoozeRequest = messages
        return
      }
      if (action === 'unsnooze') {
        this.unsnooze(messages)
        this.clearSelection()
        return
      }
      if (action === 'archive' || action === 'trash' || action === 'junk') {
        this.removeAction(action, messages)
        return
      }
      const flag = action === 'read' ? 'seen' : 'flagged'
      // Mark all as read unless they already are; same for the star.
      const value = !messages.every((m) => m[flag])
      await this.setFlagMany(messages, flag, value)
      this.clearSelection()
    },

    async setFlag(message, flag, value) {
      return this.setFlagMany([message], flag, value)
    },

    /** Set a flag on messages; on a conversation row it applies to every message in it. */
    async setFlagMany(messages, flag, value) {
      const before = messages.map((m) => ({ value: m[flag], unseen: m.thread?.unseen }))
      // Number of unread messages a row stands for (for the folder counter).
      const unreadIn = (m, b) => (m.thread ? b.unseen : b.value ? 0 : 1)
      messages.forEach((m) => {
        m[flag] = value
        if (flag === 'seen' && m.thread) m.thread.unseen = value ? 0 : m.thread.count
      })
      for (const group of groupByMailbox(messages)) {
        const delta = group.messages.reduce((n, m) => {
          const b = before[messages.indexOf(m)]
          return n + (value ? -unreadIn(m, b) : uidsOf(m).length - unreadIn(m, b))
        }, 0)
        try {
          await api.post(`/accounts/${group.accountId}/flags`, { folder: group.folder, uids: group.messages.flatMap(uidsOf), [flag]: value })
          if (flag === 'seen') this.adjustUnseen(group.accountId, group.folder, delta)
        } catch (e) {
          group.messages.forEach((m) => {
            const b = before[messages.indexOf(m)]
            m[flag] = b.value
            if (m.thread && b.unseen !== undefined) m.thread.unseen = b.unseen
          })
          useToasts().error(t('toast.failed', { msg: e.message }))
        }
      }
    },

    /**
     * Turn a label on or off for messages (on a conversation row: every message in it).
     * The open message in the reader is passed separately so its label list updates too.
     */
    /** Show only messages with this label (null = all). */
    async filterLabel(key) {
      if (this.labelFilter === key) return
      this.labelFilter = key
      this.messages = []
      this.total = 0
      this.selected = []
      await this.loadMessages()
    },

    async setLabel(messages, key, on, extra = []) {
      const lower = key.toLowerCase()
      const all = [...messages, ...extra]
      const before = all.map((m) => [...(m.labels ?? [])])
      all.forEach((m) => {
        const set = new Set((m.labels ?? []).map((k) => k.toLowerCase()))
        on ? set.add(lower) : set.delete(lower)
        m.labels = [...set]
      })
      for (const group of groupByMailbox(messages.length ? messages : extra)) {
        try {
          await api.post(`/accounts/${group.accountId}/labels`, { folder: group.folder, uids: group.messages.flatMap(uidsOf), [on ? 'add' : 'remove']: [key] })
        } catch (e) {
          all.forEach((m, i) => (m.labels = before[i]))
          useToasts().error(t('toast.failed', { msg: e.message }))
          return
        }
      }
    },

    adjustUnseen(accountId, folderPath, delta) {
      const f = (this.folders[accountId] ?? []).find((x) => x.path === folderPath)
      if (f && f.unseen !== null) f.unseen = Math.max(0, f.unseen + delta)
    },

    // ------------------------------------------------------------------ folders

    async createFolder(name, parent = null, accountId = this.accountId) {
      const res = await api.post(`/accounts/${accountId}/folders`, { name, parent })
      this.folders[accountId] = res.folders
      return res.path
    },

    // ----------------------------------------------------------------- snooze

    /** Snooze until `until` (Date). In the Snoozed folder itself only the time changes. */
    async snooze(messages, until) {
      this.snoozeRequest = null
      const iso = until.toISOString()
      const n = messages.flatMap(uidsOf).length
      const date = until.toLocaleString(locale(), { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
      if (this.folderRole === 'snoozed') {
        for (const group of groupByMailbox(messages)) {
          try {
            await api.post(`/accounts/${group.accountId}/snooze`, { folder: group.folder, uids: group.messages.flatMap(uidsOf), until: iso })
            group.messages.forEach((m) => (m.snoozed_until = iso))
          } catch (e) {
            useToasts().error(t('toast.failed', { msg: e.message }))
          }
        }
        useToasts().show(t(n > 1 ? 'toast.snoozedMany' : 'toast.snoozed', { n, date }))
        this.clearSelection()
        return true
      }
      this.removeManyWithUndo(messages, {
        commit: (group, keepalive) => api.post(`/accounts/${group.accountId}/snooze`, { folder: group.folder, uids: group.messages.flatMap(uidsOf), until: iso }, { keepalive }),
      }, t(n > 1 ? 'toast.snoozedMany' : 'toast.snoozed', { n, date }))
      this.clearSelection()
      return true
    },

    /** Back to the inbox now (from the Snoozed folder). */
    unsnooze(messages) {
      const n = messages.flatMap(uidsOf).length
      this.removeManyWithUndo(messages, {
        commit: (group, keepalive) => api.post(`/accounts/${group.accountId}/unsnooze`, { uids: group.messages.flatMap(uidsOf) }, { keepalive }),
      }, t(n > 1 ? 'toast.unsnoozedMany' : 'toast.unsnoozed', { n }))
      return true
    },

    /** Drop a row from the list without undo (e.g. a scheduled message that was sent or cancelled). */
    dropRow(message) {
      const key = msgKey(message)
      const before = this.messages.length
      this.messages = this.messages.filter((m) => msgKey(m) !== key)
      if (this.messages.length < before) this.total = Math.max(0, this.total - 1)
      if (this.openKey === key) this.openKey = null
      this.loadFolders(message.account_id)
    },

    // ------------------------------------------------------------- drag & drop

    /** Start dragging a row: the whole selection if the row is part of it. */
    startDrag(message) {
      const key = msgKey(message)
      const messages = this.selected.includes(key) ? this.selectedMessages : [message]
      const ids = new Set(messages.map((m) => m.account_id))
      this.drag = { messages, accountId: ids.size === 1 ? messages[0].account_id : null }
      return this.drag
    },

    endDrag() {
      this.drag = null
    },

    /** Can the dragged messages be dropped on this folder? */
    canDrop(folder, accountId) {
      const d = this.drag
      if (!d || d.accountId === null || d.accountId !== accountId) return false
      if (!folder.selectable || folder.role === 'drafts') return false
      return d.messages.some((m) => m.folder !== folder.path)
    },

    /** Drop the dragged messages on a folder. Archive/trash behave like their buttons. */
    dropOn(folder, accountId, label) {
      if (!this.canDrop(folder, accountId)) return false
      const messages = this.drag.messages.filter((m) => m.folder !== folder.path)
      this.drag = null
      let done
      if ((folder.role === 'archive' || folder.role === 'trash') && this.folderRole !== folder.role) {
        done = this.removeAction(folder.role, messages)
      } else {
        done = this.moveTo(messages, folder.path, label)
      }
      if (done) this.clearSelection()
      return done
    },

    /** Mark everything in the open folder (or in every inbox, in the unified view) as read. */
    async markAllRead() {
      const targets = this.unified
        ? useSession().accounts.map((a) => ({ accountId: a.id, path: (this.folders[a.id] ?? []).find((f) => f.role === 'inbox')?.path ?? 'INBOX' }))
        : [{ accountId: this.accountId, path: this.folderPath }]
      try {
        await Promise.all(targets.map((tg) => api.post(`/accounts/${tg.accountId}/folders/read`, { path: tg.path })))
      } catch (e) {
        useToasts().error(t('toast.failed', { msg: e.message }))
        return
      }
      this.messages.forEach((m) => {
        m.seen = true
        if (m.thread) m.thread.unseen = 0
      })
      for (const tg of targets) {
        const f = (this.folders[tg.accountId] ?? []).find((x) => x.path === tg.path)
        if (f) f.unseen = 0
      }
      useToasts().show(t('toast.allRead'))
    },

    /** Permanently delete everything in the trash or spam folder that is open. */
    async emptyCurrentFolder() {
      const role = this.folderRole
      if (this.unified || (role !== 'trash' && role !== 'junk')) return false
      const question = t(role === 'trash' ? 'list.emptyTrashConfirm' : 'list.emptyJunkConfirm', { n: this.total })
      if (!confirm(question)) return false
      // Let actions still in their undo window land first, so nothing is left behind.
      await this.flushPending()
      const accountId = this.accountId
      try {
        await api.post(`/accounts/${accountId}/folders/empty`, { path: this.folderPath })
        useToasts().show(t(role === 'trash' ? 'toast.emptiedTrash' : 'toast.emptiedJunk'))
      } catch (e) {
        useToasts().error(t('toast.failed', { msg: e.message }))
        return false
      }
      this.messages = []
      this.total = 0
      this.openKey = null
      this.selected = []
      await Promise.all([this.loadFolders(accountId), this.loadMessages({ silent: true })])
      return true
    },

    async setSort(sort) {
      await useSession().updateSettings({ sort })
      await this.loadMessages()
    },

    /** All messages of the conversation a message belongs to (oldest first, incl. own replies in Sent). */
    async fetchConversation(ref) {
      const data = await api.get(`/accounts/${ref.account_id}/messages/${ref.uid}/conversation`, { folder: ref.folder })
      return data?.messages ?? []
    },

    /** Full message. `ref` is a message summary or {account_id, folder, uid}. */
    async fetchMessage(ref, { images = false } = {}) {
      // tz: an all-day invitation is shown on the right day.
      return api.get(`/accounts/${ref.account_id}/messages/${ref.uid}`, { folder: ref.folder, images: images ? 1 : undefined, tz: timeZone() })
    },
  },
})
