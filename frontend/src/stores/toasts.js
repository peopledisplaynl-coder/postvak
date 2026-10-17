import { defineStore } from 'pinia'

let nextId = 1

/** Short messages at the bottom of the screen, optionally with an action such as "Undo". */
export const useToasts = defineStore('toasts', {
  state: () => ({ items: [] }),

  actions: {
    show(text, { action = null, seconds = 4, tone = 'neutral' } = {}) {
      const id = nextId++
      this.items.push({ id, text, action, tone })
      // Keep at most three on screen.
      if (this.items.length > 3) this.items.shift()
      if (seconds > 0) setTimeout(() => this.dismiss(id), seconds * 1000)
      return id
    },

    error(text) {
      return this.show(text, { tone: 'error', seconds: 6 })
    },

    dismiss(id) {
      this.items = this.items.filter((t) => t.id !== id)
    },
  },
})
