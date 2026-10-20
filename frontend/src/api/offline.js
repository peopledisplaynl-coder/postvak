import { ref } from 'vue'

/**
 * Offline reading (opt-in per device). When on, successful GETs of the things you read (message lists, messages,
 * folders, the calendar, contacts) are kept in Cache Storage; without a connection the last copy is returned.
 * The app shell itself is already precached by the service worker, so the app opens without internet.
 * The cache is wiped on logout and when the server says the session is gone.
 */
const CACHE = 'pv-offline-v1'
const FLAG = 'pv-offline'
const PREFETCH = 20

const CACHEABLE = [
  /^\/auth\/me$/,
  /^\/settings$/,
  /^\/accounts$/,
  /^\/accounts\/\d+\/folders$/,
  /^\/accounts\/\d+\/messages$/,
  /^\/accounts\/\d+\/messages\/\d+$/,
  /^\/accounts\/\d+\/messages\/\d+\/conversation$/,
  /^\/unified\/messages$/,
  /^\/calendars$/,
  /^\/events$/,
  /^\/tasks$/,
  /^\/contacts\/list$/,
]

/** True while the last request failed for lack of a connection and a saved copy was shown. */
export const offlineNow = ref(false)

const supported = () => typeof caches !== 'undefined'

export function offlineEnabled() {
  try {
    return supported() && localStorage.getItem(FLAG) === '1'
  } catch {
    return false
  }
}

export async function setOfflineEnabled(on) {
  try {
    if (on) localStorage.setItem(FLAG, '1')
    else localStorage.removeItem(FLAG)
  } catch {
    // private mode: the setting just does not stick
  }
  if (!on) await clearOffline()
}

export async function clearOffline() {
  offlineNow.value = false
  if (!supported()) return
  try {
    await caches.delete(CACHE)
  } catch {
    // nothing to clear
  }
}

export const isCacheable = (method, path) => method === 'GET' && CACHEABLE.some((re) => re.test(path))

const keyOf = (url) => new URL(url, location.origin).toString()

export async function remember(url, text) {
  try {
    const cache = await caches.open(CACHE)
    await cache.put(keyOf(url), new Response(text, { headers: { 'Content-Type': 'application/json' } }))
  } catch {
    // storage full or not allowed: offline copies are a nicety
  }
}

/** The saved answer for this URL as text, or null. */
export async function recall(url) {
  try {
    const cache = await caches.open(CACHE)
    const res = await cache.match(keyOf(url))
    return res ? await res.text() : null
  } catch {
    return null
  }
}

export async function has(url) {
  try {
    return !!(await (await caches.open(CACHE)).match(keyOf(url)))
  } catch {
    return false
  }
}

/**
 * Save the first messages of a list in the background, so they can be read later without internet.
 * `fetchOne(msg)` loads one message through the api client (which stores it); `urlOf(msg)` gives its cache URL.
 */
let prefetching = false
export async function prefetchMessages(messages, fetchOne, urlOf) {
  if (!offlineEnabled() || prefetching || offlineNow.value || !navigator.onLine) return
  prefetching = true
  try {
    for (const m of messages.slice(0, PREFETCH)) {
      if (!(await has(urlOf(m)))) {
        try {
          await fetchOne(m)
        } catch {
          return // offline or server trouble: try again with the next list
        }
      }
    }
  } finally {
    prefetching = false
  }
}
