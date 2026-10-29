<template>
  <div class="shell py-4">
    <div v-if="error" class="card p-10 text-center">
      <p class="text-muted">{{ error }}</p>
      <RouterLink to="/" class="btn-primary mt-3">Back to shop</RouterLink>
    </div>
    <template v-else-if="p">
      <nav class="text-xs text-muted mb-2">
        <RouterLink to="/" class="hover:underline">Home</RouterLink> /
        <RouterLink v-if="p.category" :to="`/c/${p.category.slug}`" class="hover:underline">{{ p.category.name }}</RouterLink>
      </nav>
      <div class="grid md:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:grid-cols-[minmax(0,4fr)_minmax(0,5fr)_minmax(0,3fr)] gap-4">
        <section class="card p-3">
          <ProductImage :src="activeImage" :alt="p.name" eager />
          <div v-if="p.images.length > 1" class="flex gap-2 mt-3 overflow-x-auto">
            <button v-for="im in p.images" :key="im.id" class="w-16 shrink-0 rounded border-2" :class="activeImage === im.path ? 'border-brand-600' : 'border-transparent'" @click="activeImage = im.path">
              <ProductImage :src="im.thumb_path" :alt="''" />
            </button>
          </div>
        </section>

        <section class="card p-4">
          <span v-if="p.type === 'bundle'" class="badge bg-ink text-white mb-2">Package</span>
          <h1 class="text-xl sm:text-2xl font-bold leading-snug">{{ p.name }}</h1>
          <p class="text-sm text-muted mt-1">
            <span v-if="p.brand">Brand: <RouterLink :to="`/search?brand=${p.brand.slug}`" class="text-brand-700 hover:underline">{{ p.brand.name }}</RouterLink> · </span>
            <span v-if="p.model">Model: {{ p.model }} · </span>SKU: {{ p.sku }}
          </p>
          <hr class="my-3 border-line" />
          <div class="flex items-baseline gap-3 flex-wrap">
            <span class="text-3xl font-black">{{ money(p.price) }}</span>
            <template v-if="off">
              <span class="text-muted line-through">{{ money(p.compare_at_price) }}</span>
              <span class="badge bg-brand-100 text-brand-700">-{{ off }}%</span>
            </template>
          </div>
          <p v-if="p.type === 'bundle' && p.components_value" class="text-sm text-ok mt-1">Bought separately: {{ money(p.components_value) }}</p>
          <p class="mt-2 text-sm font-medium" :class="p.in_stock ? (p.available <= 5 ? 'text-warn' : 'text-ok') : 'text-bad'">
            {{ p.in_stock ? (p.available <= 5 ? `Only ${p.available} left, order soon` : 'In stock') : 'Currently out of stock' }}
          </p>

          <div class="flex items-center gap-3 mt-4">
            <div class="flex items-center border border-line rounded-md">
              <button class="h-10 w-10 grid place-items-center" aria-label="Fewer" @click="qty = Math.max(1, qty - 1)"><Icon name="minus" :size="16" /></button>
              <span class="w-10 text-center font-semibold">{{ qty }}</span>
              <button class="h-10 w-10 grid place-items-center" aria-label="More" @click="qty = Math.min(p.available, qty + 1)"><Icon name="plus" :size="16" /></button>
            </div>
            <button class="btn-primary flex-1 h-11" :disabled="!p.in_stock" @click="add"><Icon name="cart" :size="18" /> Add to cart</button>
            <button class="h-11 w-11 shrink-0 rounded-md border grid place-items-center" :class="saved ? 'border-brand-500 bg-brand-50 text-brand-600' : 'border-line text-muted hover:text-brand-600'"
              :aria-pressed="saved" :aria-label="saved ? 'Remove from wishlist' : 'Save to wishlist'" @click="save">
              <svg width="20" height="20" viewBox="0 0 24 24" :fill="saved ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21.2l8.8-8.8a5.5 5.5 0 0 0 0-7.8z" /></svg>
            </button>
          </div>

          <div v-if="p.components.length" class="mt-5">
            <h2 class="font-semibold mb-2">This package contains</h2>
            <ul class="divide-y divide-line border border-line rounded-md">
              <li v-for="c in p.components" :key="c.slug" class="flex items-center gap-3 p-2 text-sm">
                <span class="badge bg-gray-100">{{ c.quantity }}×</span>
                <RouterLink :to="`/p/${c.slug}`" class="flex-1 hover:text-brand-700">{{ c.name }}</RouterLink>
                <span class="text-muted">{{ money(c.unit_price) }}</span>
              </li>
            </ul>
          </div>
        </section>

        <aside class="card p-4 space-y-3 text-sm h-max md:col-span-2 lg:col-span-1">
          <div class="flex gap-3"><Icon name="truck" class="text-brand-600 shrink-0" /><div><b>Delivery</b><br /><span class="text-muted">Pay when your order arrives{{ fee ? ` · delivery fee ${money(fee)}` : '' }}.</span></div></div>
          <div class="flex gap-3"><Icon name="home" class="text-brand-600 shrink-0" /><div><b>Pick up in store</b><br /><span class="text-muted">{{ shop.settings.shop_address || 'At our shop' }}</span></div></div>
          <div class="flex gap-3"><Icon name="shield" class="text-brand-600 shrink-0" /><div><b>Returns</b><br /><span class="text-muted">{{ shop.settings.return_policy }}</span></div></div>
        </aside>
      </div>

      <section class="grid lg:grid-cols-2 gap-4 mt-4">
        <div v-if="p.description" class="card p-4">
          <h2 class="font-bold mb-2">Description</h2>
          <p class="text-sm whitespace-pre-line leading-relaxed">{{ p.description }}</p>
        </div>
        <div v-if="p.specifications?.length" class="card p-4">
          <h2 class="font-bold mb-2">Specifications</h2>
          <table class="w-full text-sm">
            <tr v-for="s in p.specifications" :key="s.label" class="border-b border-line last:border-0">
              <th class="text-left py-1.5 pr-3 text-muted font-medium w-2/5">{{ s.label }}</th><td class="py-1.5">{{ s.value }}</td>
            </tr>
          </table>
        </div>
      </section>

      <div class="mt-4 space-y-4">
        <ProductRow v-if="inBundles.length" title="Save with a package" :items="inBundles" link="/search?type=bundle" />
        <ProductRow v-if="related.length" title="You may also like" :items="related" :link="p.category ? `/c/${p.category.slug}` : '/search'" />
      </div>
    </template>
    <div v-else class="grid md:grid-cols-2 gap-4">
      <div class="card p-3"><div class="skeleton aspect-square"></div></div>
      <div class="card p-4 space-y-3"><div class="skeleton h-7"></div><div class="skeleton h-4 w-1/2"></div><div class="skeleton h-10 w-1/3"></div></div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Icon from '@/components/Icon.vue'
import ProductImage from '@/components/ProductImage.vue'
import ProductRow from '@/components/ProductRow.vue'
import { errorText, get } from '@/lib/api'
import { money, pctOff } from '@/lib/money'
import { toast } from '@/lib/toast'
import { addToCart } from '@/stores/cart'
import { isSaved, toggleSaved } from '@/stores/wishlist'
import { loadHome, shop, type ProductCard as Card } from '@/stores/shop'

const route = useRoute()
const p = ref<any>(null)
const related = ref<Card[]>([])
const inBundles = ref<Card[]>([])
const error = ref('')
const qty = ref(1)
const activeImage = ref<string | null>(null)
const off = computed(() => (p.value ? pctOff(p.value.price, p.value.compare_at_price) : 0))
const fee = computed(() => (shop.settings.delivery_fee && shop.settings.delivery_fee !== '0' ? shop.settings.delivery_fee : ''))

async function load() {
  p.value = null
  error.value = ''
  qty.value = 1
  try {
    const r = await get(`/shop/products/${route.params.slug}`)
    p.value = r.product
    related.value = r.related
    inBundles.value = r.in_bundles
    activeImage.value = r.product.images[0]?.path ?? r.product.image
    document.title = `${r.product.name} · ${shop.settings.shop_name ?? ''}`
  } catch (e: any) {
    error.value = e?.status === 404 ? 'This product is no longer available.' : errorText(e)
  }
}

const saved = computed(() => !!p.value && isSaved(p.value.id))
function save() {
  toast(toggleSaved(p.value) ? 'Saved to your wishlist' : 'Removed from your wishlist', 'info')
}

function add() {
  addToCart(p.value, qty.value)
  toast(`Added ${qty.value} × ${p.value.name}`)
}

loadHome().catch(() => {})
watch(() => route.params.slug, (s) => s && load(), { immediate: true })
</script>
