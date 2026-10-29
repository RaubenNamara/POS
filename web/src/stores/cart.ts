import { computed, reactive, watch } from 'vue'
import { toCents, fromCents } from '@/lib/money'

/** Shopper's cart, kept in localStorage. Prices here are for display; the server re-prices. */
export interface CartLine {
  product_id: number
  slug: string
  name: string
  price: string
  image: string | null
  quantity: number
  available: number
}

const KEY = 'shop.cart.v1'
function load(): CartLine[] {
  try {
    return JSON.parse(localStorage.getItem(KEY) || '[]')
  } catch {
    return []
  }
}

export const cart = reactive({ lines: load() as CartLine[] })

watch(
  () => cart.lines,
  (l) => {
    try {
      localStorage.setItem(KEY, JSON.stringify(l))
    } catch {
      /* storage full or blocked: cart still works for this visit */
    }
  },
  { deep: true },
)

export const cartCount = computed(() => cart.lines.reduce((n, l) => n + l.quantity, 0))
export const cartSubtotal = computed(() => fromCents(cart.lines.reduce((s, l) => s + toCents(l.price) * l.quantity, 0)))

export function addToCart(p: { id: number; slug: string; name: string; price: string; image: string | null; available: number }, qty = 1) {
  const line = cart.lines.find((l) => l.product_id === p.id)
  if (line) {
    line.quantity = Math.min(line.quantity + qty, Math.max(p.available, 1))
    line.price = p.price
    line.available = p.available
  } else {
    cart.lines.push({ product_id: p.id, slug: p.slug, name: p.name, price: p.price, image: p.image, quantity: Math.min(qty, Math.max(p.available, 1)), available: p.available })
  }
}

export function setQty(id: number, qty: number) {
  const line = cart.lines.find((l) => l.product_id === id)
  if (!line) return
  if (qty <= 0) removeFromCart(id)
  else line.quantity = qty
}

export function removeFromCart(id: number) {
  cart.lines = cart.lines.filter((l) => l.product_id !== id)
}

export function clearCart() {
  cart.lines = []
}
