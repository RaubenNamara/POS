<template>
  <div class="shell py-4">
    <nav class="text-xs text-muted mb-2"><RouterLink to="/" class="hover:underline">Home</RouterLink> / {{ heading }}</nav>
    <div class="grid lg:grid-cols-[15rem_1fr] gap-4">
      <!-- Filters -->
      <aside class="card p-3 h-max space-y-4" :class="filtersOpen ? 'block' : 'hidden lg:block'">
        <div v-if="category?.children.length">
          <div class="label">Subcategories</div>
          <RouterLink v-for="c in category.children" :key="c.id" :to="`/c/${c.slug}`" class="block py-1 text-sm hover:text-brand-700">{{ c.name }}</RouterLink>
        </div>
        <div v-if="brands.length">
          <div class="label">Brand</div>
          <select class="input" :value="route.query.brand ?? ''" @change="setQuery({ brand: ($event.target as HTMLSelectElement).value })">
            <option value="">All brands</option>
            <option v-for="b in brands" :key="b.id" :value="b.slug">{{ b.name }}</option>
          </select>
        </div>
        <div>
          <div class="label">Price</div>
          <form class="flex gap-2" @submit.prevent="setQuery({ min_price: minP, max_price: maxP })">
            <input v-model="minP" class="input" inputmode="numeric" placeholder="Min" aria-label="Minimum price" />
            <input v-model="maxP" class="input" inputmode="numeric" placeholder="Max" aria-label="Maximum price" />
            <button class="btn-outline px-3 shrink-0">Go</button>
          </form>
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input type="checkbox" class="h-4 w-4 accent-brand-600" :checked="route.query.in_stock === '1'" @change="setQuery({ in_stock: ($event.target as HTMLInputElement).checked ? '1' : '' })" />
          In stock only
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input type="checkbox" class="h-4 w-4 accent-brand-600" :checked="route.query.type === 'bundle'" @change="setQuery({ type: ($event.target as HTMLInputElement).checked ? 'bundle' : '' })" />
          Packages only
        </label>
      </aside>

      <section class="min-w-0">
        <div class="card px-3 py-2 mb-3 flex flex-wrap items-center gap-2 justify-between">
          <h1 class="font-bold">{{ heading }} <span v-if="meta" class="text-muted font-normal text-sm">({{ meta.total }} products)</span></h1>
          <div class="flex items-center gap-2">
            <button class="btn-outline btn-sm lg:hidden" @click="filtersOpen = !filtersOpen">Filters</button>
            <select class="input h-8 w-auto text-xs" :value="route.query.sort ?? ''" aria-label="Sort" @change="setQuery({ sort: ($event.target as HTMLSelectElement).value })">
              <option value="">{{ route.query.q ? 'Best match' : 'Newest' }}</option>
              <option value="price_asc">Price: low to high</option>
              <option value="price_desc">Price: high to low</option>
              <option value="name">Name</option>
            </select>
          </div>
        </div>

        <div v-if="error" class="card p-8 text-center"><p class="text-muted">{{ error }}</p><button class="btn-primary mt-3" @click="load">Try again</button></div>
        <div v-else-if="items" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 min-[2300px]:grid-cols-7 gap-3" :class="{ 'opacity-60': loading }">
          <ProductCard v-for="(p, i) in items" :key="p.id" :p="p" :eager="i < 2" />
          <div v-if="!items.length" class="col-span-full card p-10 text-center text-muted">No products match. Try a different search or filter.</div>
        </div>
        <div v-else class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 min-[2300px]:grid-cols-7 gap-3">
          <div v-for="i in 10" :key="i" class="card p-2 space-y-2"><div class="skeleton aspect-square"></div><div class="skeleton h-3"></div><div class="skeleton h-3 w-1/2"></div></div>
        </div>
        <Pager v-if="meta" :page="meta.current_page" :last="meta.last_page" @go="(n) => setQuery({ page: String(n) }, false)" />
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Pager from '@/components/Pager.vue'
import ProductCard from '@/components/ProductCard.vue'
import { errorText, get } from '@/lib/api'
import { loadHome, shop, type Category, type ProductCard as Card } from '@/stores/shop'

const route = useRoute()
const router = useRouter()
const items = ref<Card[] | null>(null)
const meta = ref<{ current_page: number; last_page: number; total: number } | null>(null)
const brands = ref<{ id: number; name: string; slug: string }[]>([])
const loading = ref(false)
const error = ref('')
const filtersOpen = ref(false)
const minP = ref(String(route.query.min_price ?? ''))
const maxP = ref(String(route.query.max_price ?? ''))

function find(list: Category[], slug: string): Category | undefined {
  for (const c of list) {
    if (c.slug === slug) return c
    const f = find(c.children, slug)
    if (f) return f
  }
}
const category = computed(() => (route.params.slug ? find(shop.categories, String(route.params.slug)) : undefined))
const heading = computed(() =>
  category.value?.name ?? (route.query.q ? `Results for "${route.query.q}"` : route.query.type === 'bundle' ? 'Packages' : route.query.on_sale ? 'Top Deals' : 'All products'),
)

let ctrl: AbortController | null = null
async function load() {
  ctrl?.abort() // typing fast or clicking filters: only the latest request matters
  ctrl = new AbortController()
  loading.value = true
  error.value = ''
  const q = route.query
  try {
    const r = await get('/shop/products', {
      q: q.q as string, category: route.params.slug as string, brand: q.brand as string,
      min_price: q.min_price as string, max_price: q.max_price as string, in_stock: q.in_stock as string,
      type: q.type as string, on_sale: q.on_sale as string, sort: q.sort as string, page: q.page as string, per_page: 20,
    }, ctrl.signal)
    items.value = r.data
    meta.value = r.meta
    brands.value = r.brands
  } catch (e: any) {
    if (e?.message !== 'Cancelled') error.value = errorText(e)
  } finally {
    loading.value = false
  }
}

function setQuery(patch: Record<string, string>, resetPage = true) {
  const next: Record<string, any> = { ...route.query, ...patch }
  if (resetPage) delete next.page
  for (const k of Object.keys(next)) if (next[k] === '' || next[k] == null) delete next[k]
  router.push({ query: next })
}

loadHome().catch(() => {})
watch(() => route.fullPath, () => {
  if (route.name === 'search' || route.name === 'category') load()
}, { immediate: true })
watch(heading, (h) => (document.title = `${h} · ${shop.settings.shop_name ?? ''}`), { immediate: true })
</script>
