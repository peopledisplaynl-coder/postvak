import { defineStore } from 'pinia'
import { api, setCsrf } from '../api/client.js'

export const useSession = defineStore('session', {
  state: () => ({
    ready: false,
    user: null,
    accounts: [],
    settings: {},
  }),

  getters: {
    loggedIn: (s) => !!s.user,
    loginAccount: (s) => s.accounts.find((a) => a.is_login) ?? s.accounts[0],
    accountById: (s) => (id) => s.accounts.find((a) => a.id === Number(id)),
  },

  actions: {
    apply(data) {
      this.user = data.user
      this.accounts = data.accounts
      this.settings = data.settings
      setCsrf(data.csrf)
      this.applyTheme()
    },

    async init() {
      try {
        this.apply(await api.get('/auth/me'))
      } catch {
        this.user = null
      } finally {
        this.ready = true
        this.applyTheme()
      }
    },

    async login(credentials) {
      this.apply(await api.post('/auth/login', credentials))
    },

    async logout() {
      try {
        await api.post('/auth/logout')
      } finally {
        this.$reset()
        this.ready = true
        setCsrf(null)
      }
    },

    async updateSettings(changes) {
      const previous = { ...this.settings }
      this.settings = { ...this.settings, ...changes } // optimistic
      this.applyTheme()
      try {
        this.settings = await api.patch('/settings', changes)
      } catch (e) {
        this.settings = previous
        this.applyTheme()
        throw e
      }
    },

    async addAccount(data) {
      const account = await api.post('/accounts', data)
      this.accounts.push(account)
      return account
    },

    async updateAccount(id, data) {
      const account = await api.patch(`/accounts/${id}`, data)
      this.accounts.splice(this.accounts.findIndex((a) => a.id === id), 1, account)
      return account
    },

    async removeAccount(id) {
      await api.del(`/accounts/${id}`)
      this.accounts = this.accounts.filter((a) => a.id !== id)
    },

    applyTheme() {
      const theme = this.settings.theme ?? 'system'
      const dark = theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches)
      document.documentElement.classList.toggle('dark', dark)
    },
  },
})
