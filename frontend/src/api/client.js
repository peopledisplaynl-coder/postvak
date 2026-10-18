import { mockRequest } from './mock.js'
import { t } from '../i18n/index.js'

const USE_MOCK = import.meta.env.VITE_MOCK === 'true'
// Absolute path: a relative "api" would resolve to /mail/api/... on deeper pages.
const BASE = `${import.meta.env.BASE_URL}api`.replace(/\/{2,}/g, '/')

let csrfToken = null
let onUnauthorized = () => {}

export class ApiError extends Error {
  constructor(status, message) {
    super(message)
    this.status = status
  }
}

export function setCsrf(token) {
  csrfToken = token
}

export function setUnauthorizedHandler(fn) {
  onUnauthorized = fn
}

/**
 * Small fetch wrapper: JSON in/out, CSRF header, consistent errors.
 * Pass `form` (FormData) instead of `body` for uploads.
 */
export async function request(method, path, { body, form, query, keepalive } = {}) {
  if (USE_MOCK) return mockRequest(method, path, { body, query, form })

  const qs = query ? '?' + new URLSearchParams(Object.entries(query).filter(([, v]) => v !== undefined && v !== null && v !== '')) : ''
  const headers = { Accept: 'application/json' }
  if (csrfToken) headers['X-CSRF-Token'] = csrfToken
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  let res
  try {
    res = await fetch(`${BASE}${path}${qs}`, {
      method,
      headers,
      body: form ?? (body !== undefined ? JSON.stringify(body) : undefined),
      credentials: 'same-origin',
      keepalive,
    })
  } catch {
    throw new ApiError(0, 'offline')
  }

  if (res.status === 204) return null
  const text = await res.text()
  let data
  try {
    data = text ? JSON.parse(text) : null
  } catch {
    // The server sent something that is not JSON (PHP error page, timeout, proxy message).
    console.error(`[postvak] ${method} ${path} → ${res.status}, invalid JSON:`, text.slice(0, 500))
    throw new ApiError(res.status, t('errors.invalidResponse', { status: res.status }))
  }
  if (!res.ok) {
    if (res.status === 401 && path !== '/auth/login') onUnauthorized()
    throw new ApiError(res.status, data?.error || res.statusText)
  }
  return data
}

export const api = {
  get: (p, query) => request('GET', p, { query }),
  post: (p, body, opts) => request('POST', p, { body, ...opts }),
  patch: (p, body) => request('PATCH', p, { body }),
  put: (p, body) => request('PUT', p, { body }),
  del: (p, query) => request('DELETE', p, { query }),
  upload: (p, form) => request('POST', p, { form }),
}

/** URL for attachments and inline images (relative to the app). */
export function attachmentUrl(path) {
  if (path.startsWith('/')) return path
  return path.startsWith('api/') ? `/${path}` : `${BASE}/${path}`
}
