import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router.js'
import { setUnauthorizedHandler } from './api/client.js'
import { useSession } from './stores/session.js'
import { useMail } from './stores/mail.js'
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
window.addEventListener('pagehide', () => useMail().flushPending())

// Follow OS theme changes when the theme is "system".
matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => useSession().applyTheme())

app.mount('#app')
