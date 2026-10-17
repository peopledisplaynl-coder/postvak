import { mockRequest } from './mock.js'

const USE_MOCK = import.meta.env.VITE_MOCK === 'true'
const BASE = 'api'

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
  if (USE_MOCK) return mockRequest(method, path, { body, query })

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
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    if (res.status === 401 && path !== '/auth/login') onUnauthorized()
    throw new ApiError(res.status, data.error || res.statusText)
  }
  return data
}

export const api = {
  get: (p, query) => request('GET', p, { query }),
  post: (p, body, opts) => request('POST', p, { body, ...opts }),
  patch: (p, body) => request('PATCH', p, { body }),
  del: (p) => request('DELETE', p),
  upload: (p, form) => request('POST', p, { form }),
}

/** URL for attachments and inline images (relative to the app). */
export function attachmentUrl(path) {
  return path.startsWith('api/') ? path : `${BASE}/${path}`
}
