import { reactive } from 'vue'
import { get } from '@/lib/api'
import { configureMoney } from '@/lib/money'

export interface Category {
  id: number
  name: string
  slug: string
  image: string | null
  children: Category[]
}

export interface ProductCard {
  id: number
  type: 'standard' | 'bundle'
  name: string
  slug: string
  sku: string
  price: string
  compare_at_price: string | null
  image: string | null
  available: number
  in_stock: boolean
  category_id: number | null
}

export interface HomeData {
  settings: Record<string, string>
  categories: Category[]
  featured: ProductCard[]
  deals: ProductCard[]
  new_arrivals: ProductCard[]
  bundles: ProductCard[]
  category_sections: { category: { name: string; slug: string }; items: ProductCard[] }[]
}

/** Public shop data shared by header, menus and home page. One request on first load. */
export const shop = reactive({
  home: null as HomeData | null,
  settings: {} as Record<string, string>,
  categories: [] as Category[],
})

let pending: Promise<HomeData> | null = null
export function loadHome(): Promise<HomeData> {
  if (shop.home) return Promise.resolve(shop.home)
  pending ??= get<HomeData>('/shop/home').then((h) => {
    shop.home = h
    shop.settings = h.settings
    shop.categories = h.categories
    configureMoney(h.settings.currency, h.settings.currency_decimals)
    return h
  }).finally(() => (pending = null))
  return pending
}
