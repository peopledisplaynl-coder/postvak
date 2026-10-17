import { createRouter, createWebHistory } from 'vue-router'
import { useSession } from './stores/session.js'
import LoginView from './views/LoginView.vue'
import MailView from './views/MailView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: LoginView, meta: { guest: true } },
    { path: '/', redirect: () => ({ name: 'mail', params: { accountId: useSession().loginAccount?.id ?? '' } }) },
    { path: '/mail/:accountId?', name: 'mail', component: MailView },
    { path: '/settings/:tab?', name: 'settings', component: () => import('./views/SettingsView.vue') },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  const session = useSession()
  if (!session.ready) await session.init()
  if (!to.meta.guest && !session.loggedIn) return { name: 'login' }
  if (to.meta.guest && session.loggedIn) return '/'
})

export default router
