import { computed, reactive } from 'vue'
import { ApiError, get, post, uuid } from '@/lib/api'
import { kvGet, kvSet, queueAll, queueDelete, queuePut, requestPersistence } from '@/lib/idb'
import { configureMoney, fromCents, toCents } from '@/lib/money'

/** Compact product as delivered by /admin/pos/bootstrap. */
export interface PosProduct {
  id: number
  t: 's' | 'b'
  n: string
  sku: string
  bc: string | null
  p: string
  q: number
  img: string | null
  c: number | null
  bi: [number, number][] | null
  _h?: string // lowercase search haystack
}

export interface CartLine {
  id: number
  name: string
  sku: string
  price: string
  qty: number
  discount: string // whole-line amount
}

export interface QueuedSale {
  client_uuid: string
  payload: any
  receipt: any
  created_at: string
  status: 'pending' | 'conflict' | 'rejected'
  message?: string
}

export const pos = reactive({
  ready: false,
  products: new Map<number, PosProduct>(),
  byCode: new Map<string, number>(),
  settings: {} as Record<string, string>,
  methods: [] as { id: number; name: string; code: string; type: string; requires_reference: boolean }[],
  location: null as { id: number; name: string; code: string } | null,
  shift: null as any,
  canDiscountFreely: false,
  online: navigator.onLine,
  lastSync: '' as string,
  syncing: false,
  queue: [] as QueuedSale[],
  cart: [] as CartLine[],
  customer: null as { id: number; name: string; phone: string | null; credit_limit: string } | null,
  cartDiscount: '',
  error: '',
})

export const cartCents = computed(() => {
  let sub = 0
  let disc = 0
  for (const l of pos.cart) {
    sub += toCents(l.price) * l.qty
    disc += toCents(l.discount || '0')
  }
  disc += toCents(pos.cartDiscount || '0')
  const net = Math.max(0, sub - disc)
  const rate = Number(pos.settings.tax_rate || 0)
  const exclusive = pos.settings.tax_inclusive === '0'
  // Preview only (same rounding as the server, per line it may differ by a cent; the server total wins).
  const tax = rate ? (exclusive ? Math.round((net * rate) / 100) : net - Math.round((net * 100) / (100 + rate))) : 0
  return { sub, disc, tax, total: exclusive ? net + tax : net }
})

export const pendingCount = computed(() => pos.queue.filter((q) => q.status === 'pending').length)

// ------------------------------------------------------------------ catalogue

function index(list: PosProduct[]) {
  for (const p of list) {
    p._h = `${p.n} ${p.sku} ${p.bc ?? ''}`.toLowerCase()
    pos.products.set(p.id, p)
    pos.byCode.set(p.sku.toLowerCase(), p.id)
    if (p.bc) pos.byCode.set(p.bc.toLowerCase(), p.id)
  }
}

/** Units sellable now (bundles from components), from the local snapshot. */
export function availableOf(p: PosProduct): number {
  if (p.t === 'b') {
    if (!p.bi?.length) return 0
    return Math.min(...p.bi.map(([cid, q]) => Math.floor(Math.max(0, pos.products.get(cid)?.q ?? 0) / q)))
  }
  return p.q
}

export async function bootstrap() {
  requestPersistence()
  // 1. Instant start from the local snapshot (works offline).
  const snap = await kvGet<any>('snapshot')
  if (snap) {
    applyMeta(snap)
    index(snap.products)
    pos.lastSync = snap.server_time
    pos.ready = true
  }
  pos.queue = await queueAll<QueuedSale>()
  // 2. Refresh from the server (full first time, then only changed products).
  await refreshCatalog()
  pos.ready = true
}

function applyMeta(r: any) {
  pos.settings = r.settings
  pos.methods = r.payment_methods
  pos.location = r.location
  pos.shift = r.shift
  pos.canDiscountFreely = r.can_discount_freely
  configureMoney(r.settings.currency, r.settings.currency_decimals)
}

export async function refreshCatalog() {
  try {
    const r = await get('/admin/pos/bootstrap', pos.lastSync ? { since: pos.lastSync } : {})
    applyMeta(r)
    if (r.full) {
      pos.products.clear()
      pos.byCode.clear()
    }
    for (const id of r.removed ?? []) pos.products.delete(id)
    index(r.products)
    pos.lastSync = r.server_time
    pos.online = true
    // Persist without the derived haystack. JSON round-trip strips Vue's reactive proxies,
    // which IndexedDB's structured clone cannot copy.
    const products = [...pos.products.values()].map(({ _h, ...p }) => p)
    await kvSet('snapshot', JSON.parse(JSON.stringify({ ...r, products })))
  } catch (e) {
    if (e instanceof ApiError && e.isNetwork) pos.online = false
    else throw e
  }
}

export function search(term: string, limit = 40): PosProduct[] {
  const t = term.trim().toLowerCase()
  const all = [...pos.products.values()]
  if (!t) return all.slice(0, limit)
  const words = t.split(/\s+/)
  const out: PosProduct[] = []
  for (const p of all) {
    if (words.every((w) => p._h!.includes(w))) {
      out.push(p)
      if (out.length >= limit) break
    }
  }
  return out
}

export const findByCode = (code: string) => {
  const id = pos.byCode.get(code.trim().toLowerCase())
  return id ? pos.products.get(id) : undefined
}

// ------------------------------------------------------------------ cart

export function addProduct(p: PosProduct, qty = 1): string | null {
  const line = pos.cart.find((l) => l.id === p.id)
  const want = (line?.qty ?? 0) + qty
  if (want > availableOf(p)) return `Only ${availableOf(p)} of ${p.n} in stock`
  if (line) line.qty = want
  else pos.cart.unshift({ id: p.id, name: p.n, sku: p.sku, price: p.p, qty, discount: '' })
  return null
}

export function setLineQty(id: number, qty: number): string | null {
  const line = pos.cart.find((l) => l.id === id)
  const p = pos.products.get(id)
  if (!line || !p) return null
  if (qty <= 0) {
    pos.cart = pos.cart.filter((l) => l.id !== id)
    return null
  }
  if (qty > availableOf(p)) return `Only ${availableOf(p)} in stock`
  line.qty = qty
  return null
}

export function clearCart() {
  pos.cart = []
  pos.customer = null
  pos.cartDiscount = ''
}

export function salePayload(clientUuid: string, payments: { payment_method_id: number; amount: string; reference?: string }[]) {
  return {
    client_uuid: clientUuid,
    location_id: pos.location?.id,
    customer_id: pos.customer?.id,
    shift_id: pos.shift?.id,
    items: pos.cart.map((l) => ({ product_id: l.id, quantity: l.qty, discount: l.discount || undefined })),
    discount: pos.cartDiscount || undefined,
    payments,
  }
}

/** Apply a confirmed sale's stock effect locally so the till shows correct numbers at once. */
export function deductLocal(items: { product_id: number; quantity: number }[]) {
  for (const it of items) {
    const p = pos.products.get(it.product_id)
    if (!p) continue
    if (p.t === 'b') p.bi?.forEach(([cid, q]) => { const c = pos.products.get(cid); if (c) c.q -= q * it.quantity })
    else p.q -= it.quantity
  }
}

/** Re-read authoritative stock for given products (after a sale or a conflict). */
export async function refreshStock(ids: number[]) {
  if (!ids.length) return
  try {
    const r = await get<Record<string, number>>('/admin/pos/stock', { ids: ids.join(',') })
    for (const [id, q] of Object.entries(r)) {
      const p = pos.products.get(Number(id))
      if (p && p.t === 's') p.q = q
    }
  } catch { /* offline: keep local numbers */ }
}

// ------------------------------------------------------------------ offline queue

export function offlineCheck(cashOnly: boolean): string | null {
  const max = toCents(pos.settings.offline_max_sale_total || '0')
  if (!cashOnly) return 'Offline sales can only be paid in cash'
  if (max && cartCents.value.total > max) return `Offline sales are limited to ${fromCents(max)}`
  if (pendingCount.value >= Number(pos.settings.offline_max_queue || 50)) return 'Too many unsynced sales. Reconnect before selling more'
  return null
}

export async function queueOffline(payload: any, receipt: any) {
  const item: QueuedSale = { client_uuid: payload.client_uuid, payload: { ...payload, offline_created_at: new Date().toISOString().slice(0, 19).replace('T', ' ') }, receipt, created_at: new Date().toISOString(), status: 'pending' }
  await queuePut(JSON.parse(JSON.stringify(item)))
  pos.queue.push(item)
  deductLocal(payload.items)
}

let syncing: Promise<void> | null = null
export function syncQueue(): Promise<void> {
  syncing ??= (async () => {
    const pending = pos.queue.filter((q) => q.status === 'pending')
    if (!pending.length) return
    pos.syncing = true
    try {
      for (let i = 0; i < pending.length; i += 20) {
        const batch = pending.slice(i, i + 20)
        const r = await post<{ results: { client_uuid: string; status: string; message?: string }[] }>('/admin/pos/sync', { sales: batch.map((b) => b.payload) })
        for (const res of r.results) {
          const item = pos.queue.find((q) => q.client_uuid === res.client_uuid)
          if (!item) continue
          if (res.status === 'synced' || res.status === 'duplicate') {
            await queueDelete(item.client_uuid)
            pos.queue = pos.queue.filter((q) => q.client_uuid !== item.client_uuid)
          } else {
            // Conflicts are parked on the server for a manager; rejected ones stay visible here.
            item.status = res.status === 'conflict' ? 'conflict' : 'rejected'
            item.message = res.message
            await queuePut(JSON.parse(JSON.stringify(item)))
          }
        }
      }
      pos.online = true
      await refreshCatalog()
    } catch (e) {
      if (e instanceof ApiError && e.isNetwork) pos.online = false
    } finally {
      pos.syncing = false
    }
  })().finally(() => (syncing = null))
  return syncing
}

export async function dismissQueued(clientUuid: string) {
  await queueDelete(clientUuid)
  pos.queue = pos.queue.filter((q) => q.client_uuid !== clientUuid)
}

export const newSaleKey = uuid
