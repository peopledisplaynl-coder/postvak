import { reactive } from 'vue'
import { registerSW } from 'virtual:pwa-register'
import { useToasts } from './stores/toasts.js'
import { t } from './i18n/index.js'

/**
 * Updates of the installed app (iPhone/iPad home screen, Android, desktop).
 *
 * A home-screen app on iOS hardly ever restarts, so the browser rarely looks for a new version by itself.
 * Postvak asks the server whenever the app comes to the foreground and every 30 minutes. A new version is
 * applied right away when nothing is being written; otherwise a toast offers "Refresh".
 */
export const appState = reactive({ composing: 0, updateReady: false, checking: false })

let updateSW = null
let registration = null

export function startUpdates() {
  if (!('serviceWorker' in navigator)) return
  updateSW = registerSW({
    immediate: true,
    onNeedRefresh() {
      appState.updateReady = true
      if (appState.composing === 0 || document.visibilityState === 'hidden') {
        applyUpdate()
        return
      }
      useToasts().show(t('update.ready'), { seconds: 0, action: { label: t('update.refresh'), run: applyUpdate } })
    },
    onRegisteredSW(_url, reg) {
      registration = reg ?? null
      if (!reg) return
      setInterval(() => checkForUpdate(), 30 * 60 * 1000)
      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') checkForUpdate()
      })
    },
  })
}

/** Ask the server for a new version. @returns {Promise<boolean>} whether one is waiting */
export async function checkForUpdate() {
  if (!registration || appState.checking) return appState.updateReady
  appState.checking = true
  try {
    await registration.update()
  } catch {
    // offline: try again later
  } finally {
    appState.checking = false
  }
  return appState.updateReady || !!registration.waiting
}

export function applyUpdate() {
  // Activates the new service worker and reloads the page with the new version.
  if (updateSW) updateSW(true)
  else location.reload()
}
