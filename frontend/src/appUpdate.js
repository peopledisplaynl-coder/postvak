import { reactive } from 'vue'
import { registerSW } from 'virtual:pwa-register'
import { api } from './api/client.js'

/**
 * Updates of the installed app (iPhone/iPad home screen, Android, desktop) and of open browser tabs.
 *
 * Two independent checks, whenever the app comes to the foreground, at start and every 30 minutes:
 * 1. the service worker looks for a new version of itself (the normal PWA way);
 * 2. Postvak asks the server which version it runs (/api/health). This also works when a phone keeps an old
 *    service worker waiting (Android keeps installed apps alive in the background, so the new one never takes
 *    over). Then "Update" empties the app cache and loads the page fresh from the server.
 *
 * A new version is applied by itself when you return to the app and are not writing a message; otherwise a
 * bar at the bottom says there is a new version, with an "Update" button.
 */
export const APP_VERSION = typeof __APP_VERSION__ === 'string' ? __APP_VERSION__ : '0.0.0'

export const appState = reactive({
  composing: 0,          // open composers (an update waits while you write)
  updateReady: false,    // a new version is known (service worker waiting or newer server)
  serverVersion: null,   // version the server reports
  checking: false,
})

const CHECK_EVERY_MS = 30 * 60 * 1000
let updateSW = null
let registration = null
let started = false

/** "1.10.0" > "1.9.2" */
export function isNewer(a, b) {
  const pa = String(a).split(/[.-]/).map((x) => parseInt(x, 10) || 0)
  const pb = String(b).split(/[.-]/).map((x) => parseInt(x, 10) || 0)
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    if ((pa[i] ?? 0) !== (pb[i] ?? 0)) return (pa[i] ?? 0) > (pb[i] ?? 0)
  }
  return false
}

export function startUpdates() {
  if (started) return
  started = true
  if ('serviceWorker' in navigator) {
    updateSW = registerSW({
      immediate: true,
      onNeedRefresh() {
        appState.updateReady = true
        maybeApplyNow()
      },
      onRegisteredSW(_url, reg) {
        registration = reg ?? null
        // A worker already waiting from an earlier visit: use it.
        if (reg?.waiting) {
          appState.updateReady = true
          maybeApplyNow()
        }
      },
    })
  }
  setTimeout(() => checkForUpdate({ auto: true }), 3000)
  setInterval(() => checkForUpdate(), CHECK_EVERY_MS)
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') checkForUpdate({ auto: true })
  })
}

/**
 * Look for a new version (service worker and server). `auto`: apply it right away when nothing is being written
 * (used when the app starts or comes back to the foreground).
 * @returns {Promise<boolean>} whether a new version is available
 */
export async function checkForUpdate({ auto = false } = {}) {
  if (appState.checking) return appState.updateReady
  appState.checking = true
  try {
    await registration?.update().catch(() => {})
    const health = await api.get('/health', { t: Date.now() }).catch(() => null)
    if (health?.version) {
      appState.serverVersion = health.version
      if (isNewer(health.version, APP_VERSION)) appState.updateReady = true
    }
  } finally {
    appState.checking = false
  }
  if (appState.updateReady && auto) maybeApplyNow()
  return appState.updateReady
}

/** Apply at once unless a message is being written. Only once per new version per session (no reload loops). */
function maybeApplyNow() {
  if (appState.composing > 0) return
  const key = 'pv-update-tried'
  const target = appState.serverVersion ?? 'sw'
  try {
    if (sessionStorage.getItem(key) === target) return
    sessionStorage.setItem(key, target)
  } catch {
    // no session storage: still fine, the bar stays as fallback
  }
  applyUpdate()
}

/** Switch to the new version. */
export async function applyUpdate() {
  if (registration?.waiting && updateSW) {
    updateSW(true) // activates the waiting worker and reloads
    // If the old worker does not let go (Android), start over after a moment.
    setTimeout(hardReload, 4000)
    return
  }
  await hardReload()
}

/**
 * Load everything fresh from the server: wake a waiting service worker, empty the app cache, reload.
 * The worker itself stays registered, so push notifications keep working on this device.
 */
export async function hardReload() {
  try {
    registration?.waiting?.postMessage({ type: 'SKIP_WAITING' })
  } catch {
    // ignore
  }
  try {
    const keys = (await globalThis.caches?.keys()) ?? []
    await Promise.all(keys.map((k) => caches.delete(k)))
  } catch {
    // ignore
  }
  location.reload()
}
