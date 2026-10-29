import { reactive } from 'vue'
import { get } from '@/lib/api'
import { configureMoney } from '@/lib/money'

type Opt = { id: number; name: string; [k: string]: any }

/** Small staff lookup lists (categories, suppliers, methods...) fetched once per session. */
export const lookups = reactive({
  loaded: false,
  categories: [] as Opt[],
  brands: [] as Opt[],
  suppliers: [] as Opt[],
  locations: [] as Opt[],
  payment_methods: [] as Opt[],
  expense_categories: [] as Opt[],
  settings: {} as Record<string, string>,
})

let p: Promise<void> | null = null
export function loadLookups(force = false) {
  if (lookups.loaded && !force) return Promise.resolve()
  p ??= Promise.all([get('/admin/lookups'), get('/shop/home')])
    .then(([l, home]) => {
      Object.assign(lookups, l, { loaded: true, settings: home.settings })
      configureMoney(home.settings.currency, home.settings.currency_decimals)
    })
    .finally(() => (p = null))
  return p
}
