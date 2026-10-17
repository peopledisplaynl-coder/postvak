import { defineStore } from 'pinia'
import { api } from '../api/client.js'
import { t } from '../i18n/index.js'
import { useSession } from './session.js'
import { useToasts } from './toasts.js'

const PER_PAGE = 50

/**
 * Mail state for the open account and folder.
 *
 * Archive/delete use "deferred commit": the message disappears from the list
 * right away, but the server call only happens after the undo window.
 * Undo simply puts it back. Pending actions are flushed when the page closes.
 */
export const useMail = defineStore('mail', {
  state: () => ({
    accountId: null,
    folderPath: 'INBOX',
    folders: {},          // accountId -> folder list
    messages: [],
    total: 0,
    page: 1,
    loading: false,
    loadingMore: false,
    search: '',
    openUid: null,
    selected: [],         // uids of selected messages (multi-select)
    pending: new Map(),   // key -> { timer, commit }
  }),

  getters: {
    currentFolders: (s) => s.folders[s.accountId] ?? [],
    currentFolder(s) {
      return this.currentFolders.find((f) => f.path === s.folderPath)
    },
    hasMore: (s) => s.messages.length < s.total,
    folderRole() {
      return this.currentFolder?.role ?? null
    },
    sort: () => useSession().settings.sort ?? 'date_desc',
    selecting: (s) => s.selected.length > 0,
    selectedMessages: (s) => s.messages.filter((m) => s.selected.includes(m.uid)),
  },

  actions: {
    async open(accountId, folderPath = 'INBOX') {
      const changed = accountId !== this.accountId || folderPath !== this.folderPath
      this.accountId = accountId
      this.folderPath = folderPath
      if (changed) {
        this.messages = []
        this.total = 0
        this.openUid = null
        this.selected = []
      }
      await Promise.all([this.loadFolders(), this.loadMessages()])
    },

    async loadFolders(accountId = this.accountId) {
      try {
        const folders = await api.get(`/accounts/${accountId}/folders`)
        if (Array.isArray(folders)) this.folders[accountId] = folders
      } catch (e) {
        useToasts().error(t('toast.failed', { msg: e.message }))
      }
    },

    async loadMessages({ silent = false } = {}) {
      if (!silent) this.loading = true
      const requested = `${this.accountId}:${this.folderPath}:${this.search}`
      try {
        const data = await api.get(`/accounts/${this.accountId}/messages`, {
          folder: this.folderPath, page: 1, per_page: PER_PAGE, q: this.search, sort: this.sort,
        })
        // Ignore the answer if the user switched folder in the meantime.
        if (requested !== `${this.accountId}:${this.folderPath}:${this.search}`) return
        // Do not resurrect messages that are waiting to be archived/deleted.
        const pendingUids = new Set([...this.pending.keys()].map((k) => k.split(':').pop()))
        this.messages = (data?.messages ?? []).filter((m) => !pendingUids.has(String(m.uid)))
        this.total = data?.total ?? this.messages.length
        this.page = 1
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
        const data = await api.get(`/accounts/${this.accountId}/messages`, {
          folder: this.folderPath, page: this.page + 1, per_page: PER_PAGE, q: this.search, sort: this.sort,
        })
        const known = new Set(this.messages.map((m) => m.uid))
        this.messages.push(...(data?.messages ?? []).filter((m) => !known.has(m.uid)))
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
      if (action === 'read') return this.setFlag(message, 'seen', !message.seen)
      if (action === 'flag') return this.setFlag(message, 'flagged', !message.flagged)
      return this.removeAction(action, [message])
    },

    /** Archive or delete (to trash, or permanently when already in trash) with undo. */
    removeAction(action, messages) {
      const session = useSession()
      const inTrash = this.folderRole === 'trash'
      if (action === 'archive' && this.folderRole === 'archive') return false
      if (action === 'trash' && (session.settings.confirm_delete || inTrash)) {
        const question = inTrash ? t('actions.deleteForever') : t('actions.trash')
        if (!confirm(messages.length > 1 ? `${question}: ${messages.length}?` : `${question}?`)) return false
      }
      const permanent = inTrash && action === 'trash'
      const n = messages.length
      const label = permanent
        ? t(n > 1 ? 'toast.deletedMany' : 'toast.deleted', { n })
        : t(action === 'archive' ? (n > 1 ? 'toast.archivedMany' : 'toast.archived') : (n > 1 ? 'toast.trashedMany' : 'toast.trashed'), { n })
      this.removeManyWithUndo(messages, action, label)
      return true
    },

    /** Move messages to any folder path, with undo. */
    moveTo(messages, targetPath, folderName) {
      if (targetPath === this.folderPath) return false
      const n = messages.length
      this.removeManyWithUndo(messages, targetPath, t(n > 1 ? 'toast.movedMany' : 'toast.moved', { n, folder: folderName }))
      return true
    },

    /**
     * Remove messages from the list right away; the server call happens when the undo
     * window ends (one request for all of them). Undo puts them back where they were.
     */
    removeManyWithUndo(messages, target, label) {
      const toasts = useToasts()
      const accountId = this.accountId
      const folder = this.folderPath

      const removed = messages
        .map((message) => ({ message, index: this.messages.findIndex((m) => m.uid === message.uid) }))
        .filter((r) => r.index !== -1)
        .sort((a, b) => a.index - b.index)
      if (!removed.length) return

      for (const r of [...removed].reverse()) this.messages.splice(r.index, 1)
      this.total = Math.max(0, this.total - removed.length)
      const uids = removed.map((r) => r.message.uid)
      if (uids.includes(this.openUid)) this.openUid = null
      this.selected = this.selected.filter((u) => !uids.includes(u))

      const keys = uids.map((uid) => `${accountId}:${folder}:${uid}`)
      const batch = { done: false, timer: null }
      const finish = () => {
        batch.done = true
        clearTimeout(batch.timer)
        keys.forEach((k) => this.pending.delete(k))
      }
      batch.commit = async (keepalive = false) => {
        if (batch.done) return
        finish()
        try {
          await api.post(`/accounts/${accountId}/move`, { folder, uids, target }, { keepalive })
          this.loadFolders(accountId)
        } catch (e) {
          toasts.error(t('toast.failed', { msg: e.message }))
          this.restore(removed, accountId, folder)
        }
      }

      const seconds = Number(useSession().settings.undo_seconds ?? 5)
      if (seconds === 0) {
        batch.commit()
      } else {
        batch.timer = setTimeout(() => batch.commit(), seconds * 1000)
        keys.forEach((k) => this.pending.set(k, batch))
      }

      toasts.show(label, {
        seconds: seconds || 3,
        action: seconds ? {
          label: t('actions.undo'),
          run: () => {
            if (batch.done) return
            finish()
            this.restore(removed, accountId, folder)
          },
        } : null,
      })
    },

    /** Put removed messages back at their old positions. */
    restore(removed, accountId, folder) {
      if (this.accountId !== accountId || this.folderPath !== folder) return
      for (const { message, index } of removed) {
        if (this.messages.some((m) => m.uid === message.uid)) continue
        this.messages.splice(Math.min(index, this.messages.length), 0, message)
        this.total++
      }
    },

    /** Called on page unload: send everything that is still waiting. */
    flushPending() {
      for (const batch of new Set(this.pending.values())) batch.commit(true)
    },

    // ---------------------------------------------------------------- selection

    toggleSelect(uid) {
      this.selected = this.selected.includes(uid) ? this.selected.filter((u) => u !== uid) : [...this.selected, uid]
    },

    selectAll() {
      this.selected = this.messages.map((m) => m.uid)
    },

    clearSelection() {
      this.selected = []
    },

    /** Toolbar action on all selected messages. */
    async bulk(action) {
      const messages = this.selectedMessages
      if (!messages.length) return
      if (action === 'archive' || action === 'trash') {
        this.removeAction(action, messages)
        return
      }
      const flag = action === 'read' ? 'seen' : 'flagged'
      // Mark all as read unless they already are; same for the star.
      const value = !messages.every((m) => m[flag])
      await this.setFlagMany(messages, flag, value)
      this.clearSelection()
    },

    async setFlagMany(messages, flag, value) {
      const before = messages.map((m) => m[flag])
      const changed = messages.filter((m) => m[flag] !== value)
      messages.forEach((m) => (m[flag] = value))
      try {
        await api.post(`/accounts/${this.accountId}/flags`, { folder: this.folderPath, uids: messages.map((m) => m.uid), [flag]: value })
        if (flag === 'seen') this.adjustUnseen(value ? -changed.length : changed.length)
      } catch (e) {
        messages.forEach((m, i) => (m[flag] = before[i]))
        useToasts().error(t('toast.failed', { msg: e.message }))
      }
    },

    // ------------------------------------------------------------------ folders

    async createFolder(name, parent = null) {
      const res = await api.post(`/accounts/${this.accountId}/folders`, { name, parent })
      this.folders[this.accountId] = res.folders
      return res.path
    },

    async renameFolder(path, name) {
      const res = await api.patch(`/accounts/${this.accountId}/folders`, { path, name })
      this.folders[this.accountId] = res.folders
      return res.path
    },

    async deleteFolder(path) {
      const res = await api.del(`/accounts/${this.accountId}/folders?path=${encodeURIComponent(path)}`)
      if (res?.folders) this.folders[this.accountId] = res.folders
    },

    async setFlag(message, flag, value) {
      const before = message[flag]
      message[flag] = value
      try {
        await api.post(`/accounts/${this.accountId}/flags`, { folder: this.folderPath, uids: [message.uid], [flag]: value })
        if (flag === 'seen') this.adjustUnseen(value ? -1 : 1)
      } catch (e) {
        message[flag] = before
        useToasts().error(t('toast.failed', { msg: e.message }))
      }
    },

    adjustUnseen(delta) {
      const f = this.currentFolder
      if (f && f.unseen !== null) f.unseen = Math.max(0, f.unseen + delta)
    },

    async setSort(sort) {
      await useSession().updateSettings({ sort })
      await this.loadMessages()
    },

    async fetchMessage(uid, { images = false } = {}) {
      return api.get(`/accounts/${this.accountId}/messages/${uid}`, { folder: this.folderPath, images: images ? 1 : undefined })
    },
  },
})
