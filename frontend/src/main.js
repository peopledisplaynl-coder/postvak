import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router.js'
import { setUnauthorizedHandler } from './api/client.js'
import { useSession } from './stores/session.js'
import { useMail } from './stores/mail.js'
import { useOutbox } from './stores/outbox.js'
import { useToasts } from './stores/toasts.js'
import { t } from './i18n/index.js'
import './style.css'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
app.use(router)

// Session expired on the server: back to the login screen.
setUnauthorizedHandler(() => {
  const session = useSession()
  if (!session.user) return
  session.$reset()
  session.ready = true
  useToasts().error(t('errors.session'))
  router.push({ name: 'login' })
})

// Send archive/delete actions that are still in their undo window before the page closes.
window.addEventListener('pagehide', () => {
  useMail().flushPending()
  useOutbox().flushAll()
})

// Messages from the service worker: new mail arrived (refresh), or a notification was clicked (open it).
navigator.serviceWorker?.addEventListener('message', (event) => {
  const msg = event.data ?? {}
  if (msg.type === 'postvak:new-mail' && useSession().loggedIn) {
    const mail = useMail()
    if (mail.accountId !== null) Promise.all([mail.loadMessages({ silent: true }), mail.loadFolders()])
  }
  if (msg.type === 'postvak:open' && msg.url) {
    const url = new URL(msg.url)
    if (url.origin === location.origin) router.push(url.pathname + url.search)
  }
})

// Follow OS theme changes when the theme is "system".
matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => useSession().applyTheme())

app.mount('#app')
