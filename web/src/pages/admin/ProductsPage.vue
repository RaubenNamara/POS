<template>
  <div class="space-y-3">
    <div class="card p-3 flex flex-wrap gap-2 items-end">
      <div class="flex-1 min-w-48"><label class="label">Search</label><input v-model="filters.q" class="input" placeholder="Name, SKU, barcode, model" /></div>
      <div><label class="label">Home page section</label>
        <select v-model="filters.section" class="input">
          <option value="">Any (all products)</option>
          <option value="deals">Top Deals</option>
          <option v-for="c in mainCategories" :key="c.id" :value="`cat:${c.id}`">{{ c.name }}</option>
          <option value="packages">Save More with Packages</option>
          <option value="new">New Arrivals</option>
        </select></div>
      <div><label class="label">Category</label>
        <select v-model="filters.category_id" class="input"><option value="">All</option><option v-for="c in lookups.categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
      <div><label class="label">Stock</label>
        <select v-model="filters.stock" class="input"><option value="">Any</option><option value="low">Low</option><option value="out">Out</option><option value="in">In stock</option></select></div>
      <div><label class="label">Type</label>
        <select v-model="filters.type" class="input"><option value="">All</option><option value="standard">Products</option><option value="bundle">Packages</option></select></div>
      <label class="flex items-center gap-2 text-sm h-10"><input v-model="filters.archived" type="checkbox" class="accent-brand-600" /> Archived</label>
      <RouterLink v-if="can('products.manage')" to="/admin/products/new" class="btn-primary"><Icon name="plus" :size="16" /> New product</RouterLink>
    </div>
    <!-- What this section is and how products get into it -->
    <div v-if="filters.section && sectionInfo" class="card p-3 sm:p-4 border-brand-200 bg-brand-50 text-sm space-y-1">
      <p><b>{{ sectionInfo.title }}</b> on the home page shows the first <b>{{ raw?.home_limit }}</b> of these, in this order.
        <a :href="sectionInfo.link" target="_blank" rel="noopener" class="text-brand-700 font-semibold underline ml-1">View on the shop</a></p>
      <p class="text-muted">{{ sectionInfo.how }}</p>
      <p v-if="raw && raw.home_visible === false" class="text-warn font-semibold">This section is hidden on the home page: it needs at least 2 products that are active and shown online.</p>
    </div>
    <div class="card overflow-x-auto">
      <table class="tbl" :class="{ 'opacity-60': loading }">
        <thead><tr><th v-if="filters.section" class="num">#</th><th></th><th>Product</th><th>SKU / barcode</th><th>Category</th><th class="num">Stock</th><th v-if="can('reports.financial')" class="num">Avg cost</th><th class="num">Price</th><th>Status</th></tr></thead>
        <tbody>
          <tr v-for="(p, i) in rows" :key="p.id" class="hover:bg-gray-50 cursor-pointer" @click="router.push(`/admin/products/${p.id}`)">
            <td v-if="filters.section" class="num text-muted">{{ position(i) }}</td>
            <td class="w-12"><div class="w-10"><ProductImage :src="p.image_path" :alt="''" /></div></td>
            <td class="font-medium">{{ p.name }} <span v-if="p.type === 'bundle'" class="badge bg-ink text-white ml-1">Package</span>
              <span v-if="filters.section && raw?.home_visible !== false && position(i) <= (raw?.home_limit ?? 0)" class="badge bg-green-50 text-ok ml-1">On home page</span>
              <span v-if="!p.image_path" class="badge bg-amber-50 text-warn ml-1">No photo</span></td>
            <td class="text-xs text-muted">{{ p.sku }}<br />{{ p.barcode }}</td>
            <td class="text-sm">{{ p.category?.name }}</td>
            <td class="num font-semibold" :class="p.available <= 0 ? 'text-bad' : p.type === 'standard' && p.available <= p.min_stock ? 'text-warn' : ''">{{ p.available }}</td>
            <td v-if="can('reports.financial')" class="num">{{ p.type === 'bundle' ? '-' : money(p.cost_price, false) }}</td>
            <td class="num">{{ money(p.selling_price, false) }}</td>
            <td><span class="badge" :class="p.archived_at ? 'bg-gray-200' : p.is_active ? 'bg-green-50 text-ok' : 'bg-gray-100 text-muted'">{{ p.archived_at ? 'Archived' : p.is_active ? (p.show_online ? 'Active' : 'Store only') : 'Inactive' }}</span></td>
          </tr>
          <tr v-if="!rows.length && !loading"><td colspan="8" class="text-center text-muted py-8">No products found.</td></tr>
        </tbody>
      </table>
    </div>
    <p v-if="error" class="text-bad">{{ error }}</p>
    <div class="flex items-center justify-between text-sm text-muted"><span>{{ meta.total }} products</span><Pager :page="meta.current_page" :last="meta.last_page" @go="go" /></div>
  </div>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '@/components/Icon.vue'
import Pager from '@/components/Pager.vue'
import ProductImage from '@/components/ProductImage.vue'
import { money } from '@/lib/money'
import { usePaged } from '@/lib/usePaged'
import { lookups, loadLookups } from '@/stores/lookups'
import { can } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
loadLookups()
// Links that carry their own filter (header search, dashboard "low stock", ...) start fresh;
// otherwise the last chosen filters for this tab come back.
const fromLink = ['q', 'stock', 'section'].some((k) => route.query[k])
const { filters, rows, meta, raw, loading, error, go } = usePaged<any>('/admin/products', {
  q: String(route.query.q ?? ''), category_id: '', stock: String(route.query.stock ?? ''), type: '', archived: false,
  section: String(route.query.section ?? ''),
}, { remember: 'admin.products.filters', override: fromLink })

// Let the product page offer Previous / Next through exactly this list.
watch(rows, (r) => {
  const label = sectionInfo.value?.title ?? (filters.category_id ? lookups.categories.find((c) => c.id == filters.category_id)?.name : '') ?? ''
  try {
    sessionStorage.setItem('admin.products.list', JSON.stringify({ label: label || 'this list', ids: r.map((p: any) => p.id), page: meta.value.current_page, offset: (meta.value.current_page - 1) * 25, total: meta.value.total }))
  } catch { /* ignore */ }
})

const mainCategories = computed(() => lookups.categories.filter((c) => !c.parent_id))
const position = (i: number) => (meta.value.current_page - 1) * 25 + i + 1
const sectionInfo = computed(() => {
  const s = String(filters.section)
  if (s === 'deals') return { title: 'Top Deals', link: '/search?on_sale=1', how: 'A product is here when its "Was" price is higher than its selling price. Most recently edited first. To add one: open it and fill in "Was" price.' }
  if (s === 'packages') return { title: 'Save More with Packages', link: '/search?type=bundle', how: 'Every package (bundle) shown online, newest first. Create one with New product → Package / bundle.' }
  if (s === 'new') return { title: 'New Arrivals', link: '/search?sort=newest', how: 'The newest products shown online, newest first.' }
  const c = mainCategories.value.find((x) => `cat:${x.id}` === s)
  return c ? { title: c.name, link: `/c/${c.slug}`, how: `Products in ${c.name} and its subcategories. Featured products come first, then in-stock, then newest. Tick "Featured on home page" on a product to move it to the front.` } : null
})
</script>
