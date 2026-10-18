import { defineStore } from 'pinia'
import { request } from '../api/client.js'
import { t } from '../i18n/index.js'
import { useToasts } from './toasts.js'
import { useMail } from './mail.js'

/**
 * "Undo send": a message waits a few seconds before it is really sent.
 * It is already saved as a draft (with its attachments), so undo simply
 * reopens that draft in the composer.
 */
export const useOutbox = defineStore('outbox', {
  state: () => ({
    waiting: [],   // { id, accountId, payload, draft: {account_id, folder, uid}, timer }
    reopen: null,  // draft to open in the composer after an undo (MailView watches this)
  }),

  actions: {
    queue({ accountId, payload, draft, seconds }) {
      const id = Date.now() + Math.random()
      const entry = { id, accountId, payload, draft, timer: setTimeout(() => this.send(id), seconds * 1000) }
      this.waiting.push(entry)
      useToasts().show(t('compose.sendingSoon'), {
        seconds,
        action: { label: t('actions.undo'), run: () => this.undo(id) },
      })
    },

    take(id) {
      const entry = this.waiting.find((e) => e.id === id)
      if (!entry) return null
      clearTimeout(entry.timer)
      this.waiting = this.waiting.filter((e) => e.id !== id)
      return entry
    },

    undo(id) {
      const entry = this.take(id)
      if (entry) this.reopen = entry.draft
    },

    async send(id, keepalive = false) {
      const entry = this.take(id)
      if (!entry) return
      const form = new FormData()
      form.append('payload', JSON.stringify(entry.payload))
      try {
        await request('POST', `/accounts/${entry.accountId}/send`, { form, keepalive })
        if (keepalive) return
        useToasts().show(t('toast.sent'))
        const mail = useMail()
        if (['drafts', 'sent'].includes(mail.folderRole)) mail.loadMessages({ silent: true })
        mail.loadFolders(entry.accountId)
      } catch (e) {
        // The draft is still in Drafts: nothing is lost.
        useToasts().error(t('compose.sendFailedDraft', { msg: e.message }))
      }
    },

    /** Page closes: send what is waiting (the browser finishes these requests). */
    flushAll() {
      for (const entry of [...this.waiting]) this.send(entry.id, true)
    },
  },
})
