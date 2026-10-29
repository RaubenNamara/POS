/**
 * Money on the client is only ever *displayed* or *previewed*; the server recomputes every
 * total. Previews use integer cents (never float sums) so the till shows the same figure
 * the server will confirm.
 */
let currency = 'UGX'
let decimals = 0

export function configureMoney(code: string, dec: number | string) {
  currency = code || 'UGX'
  decimals = Number(dec) || 0
}

/** "12500.50" -> 1250050 (exact, string based). */
export function toCents(v: string | number | null | undefined): number {
  if (v === null || v === undefined || v === '') return 0
  const s = typeof v === 'number' ? v.toFixed(2) : v.trim()
  const neg = s.startsWith('-')
  const [i, f = ''] = s.replace('-', '').split('.')
  const cents = Number(i || '0') * 100 + Number((f + '00').slice(0, 2))
  return neg ? -cents : cents
}

export function fromCents(c: number): string {
  const neg = c < 0
  const a = Math.abs(Math.round(c))
  return `${neg ? '-' : ''}${Math.floor(a / 100)}.${String(a % 100).padStart(2, '0')}`
}

const fmtCache = new Map<number, Intl.NumberFormat>()
function nf(d: number) {
  let f = fmtCache.get(d)
  if (!f) {
    f = new Intl.NumberFormat('en-UG', { minimumFractionDigits: d, maximumFractionDigits: d })
    fmtCache.set(d, f)
  }
  return f
}

/** Format a decimal string for display: "649000.00" -> "UGX 649,000". */
export function money(v: string | number | null | undefined, withCode = true): string {
  const c = toCents(v as any)
  const n = (decimals === 0 ? Math.round(c / 100) : c / 100) || 0 // || 0 turns -0 into 0
  return (withCode ? `${currency} ` : '') + nf(decimals).format(n)
}

export function pctOff(price: string, compare?: string | null): number {
  if (!compare) return 0
  const p = toCents(price)
  const c = toCents(compare)
  return c > p && c > 0 ? Math.round(((c - p) / c) * 100) : 0
}

/** An amount shown as a deduction: "-UGX 5,000", or plain "UGX 0" when there is nothing to take off. */
export function minus(v: string | number | null | undefined, withCode = true): string {
  return (toCents(v as any) ? '-' : '') + money(v, withCode)
}
