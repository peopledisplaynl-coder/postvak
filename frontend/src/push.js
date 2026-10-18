import { api } from './api/client.js'
import { language } from './i18n/index.js'

/**
 * Web Push on this device: subscribe/unsubscribe with the server's VAPID key.
 * The service worker (public/push-sw.js) shows the notifications.
 */

export function pushSupport() {
  const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
  // iPhone/iPad only support push for apps added to the home screen.
  const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
  const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true
  return { supported, ios, needsHomeScreen: ios && !standalone }
}

function keyToBytes(base64url) {
  const pad = '='.repeat((4 - (base64url.length % 4)) % 4)
  const raw = atob((base64url + pad).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from(raw, (c) => c.charCodeAt(0))
}

async function registration() {
  // The service worker is registered by the PWA plugin; wait at most a few seconds for it.
  const ready = navigator.serviceWorker.ready
  const timeout = new Promise((_, reject) => setTimeout(() => reject(new Error('service worker not ready')), 8000))
  return Promise.race([ready, timeout])
}

/** Current subscription of this browser, or null. */
export async function currentSubscription() {
  if (!pushSupport().supported) return null
  try {
    return await (await registration()).pushManager.getSubscription()
  } catch {
    return null
  }
}

/** Ask permission, subscribe and register with the server. Returns the subscription. */
export async function enablePush(publicKey) {
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') throw Object.assign(new Error('permission'), { code: permission })
  const reg = await registration()
  let sub = await reg.pushManager.getSubscription()
  // A subscription made with another server key cannot be reused.
  const current = sub?.options?.applicationServerKey
  if (sub && current && btoa(String.fromCharCode(...new Uint8Array(current))) !== btoa(String.fromCharCode(...keyToBytes(publicKey)))) {
    await sub.unsubscribe()
    sub = null
  }
  sub ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToBytes(publicKey) })
  await api.post('/push/subscriptions', { ...sub.toJSON(), lang: language() })
  return sub
}

export async function disablePush() {
  const sub = await currentSubscription()
  if (!sub) return
  await api.post('/push/unsubscribe', { endpoint: sub.endpoint }).catch(() => {})
  await sub.unsubscribe()
}

export async function sendTestPush() {
  const sub = await currentSubscription()
  if (!sub) throw new Error('not subscribed')
  return api.post('/push/test', { endpoint: sub.endpoint })
}

/** The interface language changed: notifications on this device follow it. */
export async function syncPushLanguage() {
  const sub = await currentSubscription()
  if (sub) await api.post('/push/subscriptions', { ...sub.toJSON(), lang: language() }).catch(() => {})
}
