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
      const session = useSession()
      if (action === 'none') return
      if (action === 'read') return this.setFlag(message, 'seen', !message.seen)
      if (action === 'flag') return this.setFlag(message, 'flagged', !message.flagged)

      const inTrash = this.folderRole === 'trash'
      if (action === 'trash' && (session.settings.confirm_delete || inTrash)) {
        if (!confirm(inTrash ? t('actions.deleteForever') + '?' : t('actions.trash') + '?')) return false
      }
      if (action === 'archive' && this.folderRole === 'archive') return false

      this.removeWithUndo(message, action, inTrash && action === 'trash')
      return true
    },

    removeWithUndo(message, target, permanent) {
      const toasts = useToasts()
      const accountId = this.accountId
      const folder = this.folderPath
      const index = this.messages.findIndex((m) => m.uid === message.uid)
      if (index === -1) return

      this.messages.splice(index, 1)
      this.total = Math.max(0, this.total - 1)
      if (this.openUid === message.uid) this.openUid = null

      const key = `${accountId}:${folder}:${message.uid}`
      const commit = async (keepalive = false) => {
        this.pending.delete(key)
        try {
          await api.post(`/accounts/${accountId}/move`, { folder, uids: [message.uid], target }, { keepalive })
          this.loadFolders(accountId)
        } catch (e) {
          toasts.error(t('toast.failed', { msg: e.message }))
          this.restore(message, index, accountId, folder)
        }
      }

      const seconds = Number(useSession().settings.undo_seconds ?? 5)
      if (seconds === 0) {
        commit()
      } else {
        const timer = setTimeout(commit, seconds * 1000)
        this.pending.set(key, { timer, commit })
      }

      const label = permanent ? t('toast.deleted') : target === 'archive' ? t('toast.archived') : t('toast.trashed')
      toasts.show(label, {
        seconds: seconds || 3,
        action: seconds ? {
          label: t('actions.undo'),
          run: () => {
            const p = this.pending.get(key)
            if (!p) return
            clearTimeout(p.timer)
            this.pending.delete(key)
            this.restore(message, index, accountId, folder)
          },
        } : null,
      })
    },

    restore(message, index, accountId, folder) {
      if (this.accountId !== accountId || this.folderPath !== folder) return
      if (this.messages.some((m) => m.uid === message.uid)) return
      this.messages.splice(Math.min(index, this.messages.length), 0, message)
      this.total++
    },

    /** Called on page unload: send everything that is still waiting. */
    flushPending() {
      for (const { timer, commit } of this.pending.values()) {
        clearTimeout(timer)
        commit(true)
      }
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
