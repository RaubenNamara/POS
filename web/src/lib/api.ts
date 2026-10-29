/**
 * Tiny fetch wrapper (no axios: ~0 KB). Same-origin Sanctum session for the web app:
 * the XSRF-TOKEN cookie is echoed back as a header on every write.
 */
export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public code?: string,
    public errors?: Record<string, string[]>,
    public details?: unknown,
  ) {
    super(message)
  }
  /** True when the request never reached the server (offline, DNS, timeout). */
  get isNetwork() {
    return this.status === 0
  }
}

type Query = Record<string, string | number | boolean | null | undefined>
interface Opts {
  method?: string
  body?: unknown
  query?: Query
  signal?: AbortSignal
  timeout?: number
}

let csrfPromise: Promise<void> | null = null
function ensureCsrf(force = false) {
  if (force || !csrfPromise) {
    csrfPromise = fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }).then(
      () => undefined,
      () => {
        csrfPromise = null // offline: don't cache the failure, retry on the next write
        throw new ApiError('No connection to the server', 0)
      },
    )
  }
  return csrfPromise
}

function xsrf(): string {
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return m ? decodeURIComponent(m[1]) : ''
}

export function qs(query?: Query): string {
  if (!query) return ''
  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(query)) {
    if (v === null || v === undefined || v === '') continue
    p.set(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v))
  }
  const s = p.toString()
  return s ? `?${s}` : ''
}

export const onUnauthorized: { handler: (() => void) | null } = { handler: null }

export async function api<T = any>(path: string, opts: Opts = {}, retried = false): Promise<T> {
  const method = (opts.method ?? 'GET').toUpperCase()
  const isForm = opts.body instanceof FormData
  if (method !== 'GET' && !xsrf()) await ensureCsrf()

  const ctrl = new AbortController()
  const timer = setTimeout(() => ctrl.abort(), opts.timeout ?? 20000)
  opts.signal?.addEventListener('abort', () => ctrl.abort())

  let res: Response
  try {
    res = await fetch(`/api${path}${qs(opts.query)}`, {
      method,
      credentials: 'same-origin',
      signal: ctrl.signal,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(isForm || opts.body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...(method !== 'GET' ? { 'X-XSRF-TOKEN': xsrf() } : {}),
      },
      body: opts.body === undefined ? undefined : isForm ? (opts.body as FormData) : JSON.stringify(opts.body),
    })
  } catch (e) {
    throw new ApiError(opts.signal?.aborted ? 'Cancelled' : 'No connection to the server', 0)
  } finally {
    clearTimeout(timer)
  }

  if (res.status === 419 && !retried) {
    await ensureCsrf(true) // session/CSRF expired: refresh once and retry
    return api<T>(path, opts, true)
  }
  if (res.status === 204) return undefined as T

  const data = res.headers.get('content-type')?.includes('json') ? await res.json() : await res.text()
  if (!res.ok) {
    if (res.status === 401) onUnauthorized.handler?.()
    const msg =
      (typeof data === 'object' && data?.message) ||
      (res.status === 429 ? 'Too many attempts. Please wait a minute.' : `Request failed (${res.status})`)
    throw new ApiError(msg, res.status, data?.code, data?.errors, data?.details)
  }
  return data as T
}

export const get = <T = any>(path: string, query?: Query, signal?: AbortSignal) => api<T>(path, { query, signal })
export const post = <T = any>(path: string, body?: unknown) => api<T>(path, { method: 'POST', body })
export const put = <T = any>(path: string, body?: unknown) => api<T>(path, { method: 'PUT', body })
export const del = <T = any>(path: string) => api<T>(path, { method: 'DELETE' })

/** First validation message for a field, or the general message. */
export function errorText(e: unknown): string {
  if (e instanceof ApiError) {
    const first = e.errors ? Object.values(e.errors)[0]?.[0] : undefined
    return first ?? e.message
  }
  return e instanceof Error ? e.message : String(e)
}

export function uuid(): string {
  if (crypto.randomUUID) return crypto.randomUUID()
  const b = crypto.getRandomValues(new Uint8Array(16))
  b[6] = (b[6] & 0x0f) | 0x40
  b[8] = (b[8] & 0x3f) | 0x80
  const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('')
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`
}

export const img = (path?: string | null) => (path ? (path.startsWith('http') ? path : `/storage/${path}`) : '')
