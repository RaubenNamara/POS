import { computed, reactive, watch } from 'vue'
import type { ProductCard } from './shop'

/** Saved-for-later products, kept on this device (works signed in or not). */
const KEY = 'shop.wishlist.v1'
type Saved = Pick<ProductCard, 'id' | 'slug' | 'name' | 'price' | 'compare_at_price' | 'image' | 'type'>

function load(): Saved[] {
  try {
    return JSON.parse(localStorage.getItem(KEY) || '[]')
  } catch {
    return []
  }
}

export const wishlist = reactive({ items: load() })
export const wishlistCount = computed(() => wishlist.items.length)

watch(() => wishlist.items, (v) => {
  try {
    localStorage.setItem(KEY, JSON.stringify(v))
  } catch { /* storage blocked: list lasts for this visit */ }
}, { deep: true })

export const isSaved = (id: number) => wishlist.items.some((i) => i.id === id)

export function toggleSaved(p: Saved): boolean {
  if (isSaved(p.id)) {
    wishlist.items = wishlist.items.filter((i) => i.id !== p.id)
    return false
  }
  wishlist.items.unshift({ id: p.id, slug: p.slug, name: p.name, price: p.price, compare_at_price: p.compare_at_price, image: p.image, type: p.type })
  return true
}
