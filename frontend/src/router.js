import { createRouter, createWebHistory } from 'vue-router'
import { useSession } from './stores/session.js'
import LoginView from './views/LoginView.vue'
import MailView from './views/MailView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: LoginView, meta: { guest: true } },
    {
      path: '/',
      redirect: () => {
        const s = useSession()
        const unified = s.accounts.length > 1 && s.settings.unified_inbox !== false
        return { name: 'mail', params: { accountId: unified ? 'all' : s.loginAccount?.id ?? '' } }
      },
    },
    { path: '/mail/:accountId?', name: 'mail', component: MailView },
    { path: '/contacts', name: 'contacts', component: () => import('./views/ContactsView.vue') },
    { path: '/settings/:tab?', name: 'settings', component: () => import('./views/SettingsView.vue') },
    { path: '/admin/:tab?', name: 'admin', component: () => import('./views/AdminView.vue'), meta: { admin: true } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  const session = useSession()
  if (!session.ready) await session.init()
  if (!to.meta.guest && !session.loggedIn) return { name: 'login' }
  if (to.meta.guest && session.loggedIn) return '/'
  if (to.meta.admin && !session.user?.is_admin) return '/'
})

export default router
