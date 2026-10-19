import { api } from './api/client.js'
import { fileSize } from './format.js'
import { t, locale } from './i18n/index.js'

/**
 * Large attachments as a download link. Files above LINK_FROM, or that would make the attachments too big for
 * the server, are uploaded in pieces to the user's own Postvak server; the message gets a link instead.
 */
export const LINK_FROM = 10 * 1024 * 1024

let limits = null
/** {chunk_bytes, max_bytes, days, attach_max_bytes} from the server (asked once). */
export async function fileLimits() {
  if (!limits) {
    try {
      limits = await api.get('/files')
    } catch {
      limits = { chunk_bytes: 4 * 1024 * 1024, max_bytes: 2 * 1024 ** 3, days: 30, attach_max_bytes: 20 * 1024 * 1024 }
    }
  }
  return limits
}

/** Should this file go as a link (given the size of the normal attachments so far)? */
export function needsLink(file, attachedBytes, lim) {
  return file.size > LINK_FROM || attachedBytes + file.size > (lim?.attach_max_bytes ?? 20 * 1024 * 1024) * 0.95
}

/**
 * Upload a file in pieces. onProgress(0..1). A piece that fails is tried again (3 times).
 * @returns the stored file {token, url, name, size, expires_at}
 */
export async function uploadAsLink(file, { onProgress, onCreated, signal } = {}) {
  const lim = await fileLimits()
  if (file.size > lim.max_bytes) throw new Error(t('bigFiles.tooLarge', { max: fileSize(lim.max_bytes) }))
  const created = await api.post('/files', { name: file.name, size: file.size, mime: file.type || 'application/octet-stream' })
  onCreated?.(created)
  let offset = 0
  let info = created
  while (offset < file.size) {
    const piece = file.slice(offset, offset + lim.chunk_bytes)
    for (let attempt = 1; ; attempt++) {
      try {
        info = await api.chunk(`/files/${created.token}/chunk`, piece, { offset }, signal)
        break
      } catch (e) {
        if (e?.name === 'AbortError' || attempt >= 3) throw e
        await new Promise((r) => setTimeout(r, 1000 * attempt))
      }
    }
    offset = info.received
    onProgress?.(offset / file.size)
  }
  return info
}

/** Cancel an unfinished upload (also removes what the server already has). */
export function discardUpload(token) {
  return api.del(`/files/${token}`).catch(() => {})
}

/** The paragraph that goes into the message: name (link), size and until when it can be downloaded. */
export function linkHtml(info) {
  const url = new URL(info.url, location.origin).href
  const until = new Date(info.expires_at).toLocaleDateString(locale(), { day: 'numeric', month: 'long', year: 'numeric' })
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c])
  return `<p>📎 <a href="${esc(url)}">${esc(info.name)}</a> (${esc(fileSize(info.size))}, ${esc(t('bigFiles.until', { date: until }))})</p>`
}
