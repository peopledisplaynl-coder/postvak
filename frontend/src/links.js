/**
 * Where a link in a mail really goes, for the link bar under the mouse and the check before opening:
 * a link whose text shows one web address while it leads to another is a classic phishing trick.
 */

/** "mail.ing.nl" → "ing.nl" (good enough to compare; not a public-suffix list). */
export function baseDomain(host) {
  const parts = String(host).toLowerCase().replace(/\.$/, '').split('.')
  const twoLevel = /^(co|com|org|net|gov|ac|edu)$/.test(parts.at(-2) ?? '') && parts.length > 2
  return parts.slice(twoLevel ? -3 : -2).join('.')
}

/** The web address the visible text shows, if it looks like one ("www.ing.nl", "https://ing.nl/inloggen"). */
export function shownHost(text) {
  const m = /^\s*(?:https?:\/\/)?((?:[a-z0-9-]+\.)+[a-z]{2,})(?:[/:?#]\S*)?\s*$/i.exec(text ?? '')
  return m ? m[1].toLowerCase() : null
}

/**
 * @returns {{ href: string, host: string, display: string, kind: 'web'|'mail'|'other', mismatch: string|null }|null}
 *   mismatch: the address the text shows when the link leads elsewhere
 */
export function describeLink(href, text) {
  let url
  try {
    url = new URL(href)
  } catch {
    return null
  }
  if (url.protocol === 'mailto:') {
    return { href, host: '', display: decodeURIComponent(url.pathname), kind: 'mail', mismatch: null }
  }
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    return { href, host: '', display: href, kind: 'other', mismatch: null }
  }
  const host = url.hostname.toLowerCase()
  let display = href
  try {
    display = decodeURI(href)
  } catch {
    // keep it encoded
  }
  const shown = shownHost(text)
  const mismatch = shown && baseDomain(shown) !== baseDomain(host) ? shown : null
  return { href, host, display, kind: 'web', mismatch }
}
