import { reactive, ref, watch } from 'vue'
import { errorText, get } from './api'

/** Paginated list bound to a filter object; refetches (and cancels stale requests) on change. */
export function usePaged<T = any>(path: string | (() => string), initial: Record<string, any> = {}, opts: { remember?: string; override?: boolean } = {}) {
  // `remember`: keep the chosen filters (and page) for this tab, so leaving the list and coming
  // back restores it. `override`: values passed in `initial` (e.g. from a link) win over memory.
  let stored: Record<string, any> = {}
  if (opts.remember && !opts.override) {
    try {
      stored = JSON.parse(sessionStorage.getItem(opts.remember) || '{}')
    } catch { /* ignore */ }
  }
  const filters = reactive<Record<string, any>>({ page: 1, ...initial, ...stored })
  if (opts.remember) {
    watch(() => ({ ...filters }), (v) => {
      try {
        sessionStorage.setItem(opts.remember!, JSON.stringify(v))
      } catch { /* storage blocked: filters just aren't remembered */ }
    }, { deep: true })
  }
  const rows = ref<T[]>([])
  const meta = ref({ current_page: 1, last_page: 1, total: 0 })
  const raw = ref<any>(null) // full last response (for extra fields some lists return)
  const loading = ref(false)
  const error = ref('')
  let ctrl: AbortController | null = null
  let t: number | undefined

  async function load() {
    ctrl?.abort()
    ctrl = new AbortController()
    loading.value = true
    error.value = ''
    try {
      const r = await get(typeof path === 'string' ? path : path(), { ...filters }, ctrl.signal)
      raw.value = r
      rows.value = r.data
      meta.value = { current_page: r.current_page, last_page: r.last_page, total: r.total }
    } catch (e: any) {
      if (e?.message !== 'Cancelled') error.value = errorText(e)
    } finally {
      loading.value = false
    }
  }

  // Text filters are debounced; page changes load at once.
  watch(
    () => ({ ...filters }),
    (n, o) => {
      clearTimeout(t)
      if (o && n.page === o.page && n.page !== 1) filters.page = 1
      t = window.setTimeout(load, n.page !== o?.page ? 0 : 250)
    },
    { deep: true },
  )
  load()
  return { filters, rows, meta, raw, loading, error, load, go: (n: number) => (filters.page = n) }
}
