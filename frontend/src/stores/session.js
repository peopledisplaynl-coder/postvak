import { defineStore } from 'pinia'
import { api, setCsrf } from '../api/client.js'
import { setLanguage } from '../i18n/index.js'

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
      setLanguage(this.settings.language ?? 'auto')
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

    /** Returns { twoFactor: true } when a code from the authenticator app is needed next. */
    async login(credentials) {
      const data = await api.post('/auth/login', credentials)
      if (data?.two_factor) return { twoFactor: true }
      this.apply(data)
      return { twoFactor: false }
    },

    /** Second login step: code from the app (or a recovery code). */
    async verifyTwoFactor(code, remember) {
      this.apply(await api.post('/auth/2fa', { code, remember }))
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
      if ('language' in changes) {
        setLanguage(changes.language)
        import('../push.js').then((m) => m.syncPushLanguage())
      }
      try {
        this.settings = await api.patch('/settings', changes)
      } catch (e) {
        this.settings = previous
        this.applyTheme()
        setLanguage(previous.language ?? 'auto')
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
