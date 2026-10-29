<template>
  <div class="max-w-5xl 2xl:max-w-[max(80rem,70vw)] space-y-4">
    <div v-if="loadError" class="card p-4 text-bad">{{ loadError }}</div>
    <form v-else-if="ready" class="space-y-4" @submit.prevent="save">
      <div class="flex flex-wrap items-center gap-2">
        <RouterLink to="/admin/products" class="btn-ghost btn-sm"><Icon name="left" :size="16" /> Products{{ nav ? ` (${nav.label})` : '' }}</RouterLink>
        <div v-if="nav && isEdit" class="flex items-center gap-1 text-sm">
          <RouterLink v-if="nav.prev" :to="`/admin/products/${nav.prev}`" class="btn-outline btn-sm"><Icon name="left" :size="14" /> Previous</RouterLink>
          <span class="px-2 text-muted whitespace-nowrap">{{ nav.pos }} of {{ nav.total }}</span>
          <RouterLink v-if="nav.next" :to="`/admin/products/${nav.next}`" class="btn-outline btn-sm">Next <Icon name="chevron" :size="14" /></RouterLink>
        </div>
        <div class="flex-1"></div>
        <template v-if="isEdit && editable">
          <button v-if="!product.archived_at" type="button" class="btn-outline btn-sm" @click="archive">Archive</button>
          <button v-else type="button" class="btn-outline btn-sm" @click="restore">Restore</button>
        </template>
        <button v-if="editable" class="btn-primary" :disabled="busy">{{ busy ? 'Saving…' : 'Save product' }}</button>
      </div>
      <fieldset :disabled="!editable" class="grid lg:grid-cols-[2fr_1fr] gap-4">
        <section class="card p-4 space-y-3">
          <div v-if="!isEdit" class="flex gap-2">
            <label v-for="t in ['standard', 'bundle']" :key="t" class="flex-1 border rounded-md p-2 text-sm cursor-pointer" :class="f.type === t ? 'border-brand-600 bg-brand-50' : 'border-line'">
              <input v-model="f.type" type="radio" :value="t" class="sr-only" /><b>{{ t === 'standard' ? 'Product' : 'Package / bundle' }}</b>
              <div class="text-xs text-muted">{{ t === 'standard' ? 'Has its own stock' : 'Made of other products; uses their stock' }}</div>
            </label>
          </div>
          <div><label class="label">Name *</label><input v-model="f.name" class="input" required maxlength="200" /></div>
          <div class="grid sm:grid-cols-3 gap-3">
            <div><label class="label">SKU</label><input v-model="f.sku" class="input" placeholder="Auto if empty" /></div>
            <div><label class="label">Barcode</label><input v-model="f.barcode" class="input" placeholder="Scan here" /></div>
            <div><label class="label">Model</label><input v-model="f.model" class="input" /></div>
          </div>
          <div class="grid sm:grid-cols-3 gap-3">
            <div><label class="label">Category</label><select v-model="f.category_id" class="input"><option :value="null">-</option><option v-for="c in lookups.categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
            <div><label class="label">Brand</label><select v-model="f.brand_id" class="input"><option :value="null">-</option><option v-for="b in lookups.brands" :key="b.id" :value="b.id">{{ b.name }}</option></select></div>
            <div><label class="label">Main supplier</label><select v-model="f.supplier_id" class="input"><option :value="null">-</option><option v-for="s in lookups.suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
          </div>
          <div><label class="label">Description</label><textarea v-model="f.description" class="input h-28 py-2"></textarea></div>
          <div>
            <div class="flex justify-between items-center"><span class="label">Specifications</span><button type="button" class="text-xs text-brand-700 font-semibold" @click="f.specifications.push({ label: '', value: '' })">+ Add</button></div>
            <div v-for="(s, i) in f.specifications" :key="i" class="flex gap-2 mb-2">
              <input v-model="s.label" class="input w-1/3" placeholder="e.g. RAM" required /><input v-model="s.value" class="input" placeholder="e.g. 8GB" required />
              <button type="button" class="btn-ghost px-2" aria-label="Remove" @click="f.specifications.splice(i, 1)"><Icon name="x" :size="16" /></button>
            </div>
          </div>
        </section>

        <section class="space-y-4">
          <div class="card p-4 space-y-3">
            <div class="grid grid-cols-2 gap-3">
              <div><label class="label">Selling price *</label><input v-model="f.selling_price" class="input" inputmode="decimal" required /></div>
              <div><label class="label">"Was" price (puts it in Top Deals)</label><input v-model="f.compare_at_price" class="input" inputmode="decimal" placeholder="optional" /></div>
            </div>
            <div v-if="f.type === 'standard'"><label class="label">Minimum stock (alert level)</label><input v-model.number="f.min_stock" type="number" min="0" class="input" /></div>
            <div v-if="isEdit && f.type === 'standard' && can('reports.financial')" class="text-sm rounded bg-gray-50 p-2">
              Average cost: <b>{{ money(product.cost_price) }}</b>
              <span v-if="toCents(product.cost_price) > 0" class="text-muted"> · margin {{ margin }}%</span>
              <p class="text-xs text-muted mt-1">Cost is set by goods received (weighted average), not edited here.</p>
            </div>
            <label class="flex items-center gap-2 text-sm"><input v-model="f.is_active" type="checkbox" class="accent-brand-600" /> Active (can be sold)</label>
            <label class="flex items-center gap-2 text-sm"><input v-model="f.show_online" type="checkbox" class="accent-brand-600" /> Show in online shop</label>
            <label class="flex items-center gap-2 text-sm"><input v-model="f.is_featured" type="checkbox" class="accent-brand-600" /> Featured on home page</label>
          </div>

          <div v-if="!isEdit && f.type === 'standard'" class="card p-4 space-y-3">
            <h3 class="font-semibold text-sm">Opening stock</h3>
            <div class="grid grid-cols-2 gap-3">
              <div><label class="label">Quantity</label><input v-model.number="f.opening_quantity" type="number" min="0" class="input" /></div>
              <div><label class="label">Unit cost</label><input v-model="f.opening_unit_cost" class="input" inputmode="decimal" /></div>
            </div>
            <p class="text-xs text-muted">Recorded as an "opening" stock movement. Later stock comes in through Receive goods.</p>
          </div>

          <div v-if="isEdit && f.type === 'standard'" class="card p-4">
            <h3 class="font-semibold text-sm mb-2">Stock by location</h3>
            <table class="w-full text-sm"><tr v-for="l in product.stock_levels" :key="l.id"><td>{{ l.location.name }}</td><td class="num font-semibold">{{ l.quantity }}</td></tr></table>
            <p class="text-sm font-bold flex justify-between border-t border-line mt-2 pt-2"><span>Total</span><span>{{ product.total_stock }}</span></p>
            <RouterLink v-if="can('inventory.adjust')" :to="`/admin/inventory/adjust?product=${product.id}`" class="btn-outline btn-sm w-full mt-2">Adjust stock</RouterLink>
          </div>
        </section>
      </fieldset>

      <section v-if="f.type === 'bundle'" class="card p-4 space-y-3">
        <h3 class="font-semibold">Package contents</h3>
        <ProductPicker v-if="editable" type="standard" placeholder="Add a component product" @pick="addComponent" />
        <table class="tbl">
          <thead><tr><th>Component</th><th class="num">Qty per package</th><th class="num">In stock</th><th class="num">Cost each</th><th class="num">Price each</th><th></th></tr></thead>
          <tbody>
            <tr v-for="(c, i) in components" :key="c.product_id">
              <td>{{ c.name }}</td>
              <td class="num"><input v-model.number="c.quantity" type="number" min="1" class="input h-8 w-20 text-right" :disabled="!editable" /></td>
              <td class="num">{{ c.total_stock }}</td>
              <td class="num">{{ can('reports.financial') ? money(c.cost_price, false) : '-' }}</td>
              <td class="num">{{ money(c.selling_price, false) }}</td>
              <td><button v-if="editable" type="button" class="btn-ghost btn-sm" @click="components.splice(i, 1)"><Icon name="x" :size="14" /></button></td>
            </tr>
          </tbody>
        </table>
        <div class="text-sm grid sm:grid-cols-3 gap-2 bg-gray-50 rounded p-3">
          <div>Components bought separately: <b>{{ money(fromCents(compPrice)) }}</b></div>
          <div v-if="can('reports.financial')">Components cost: <b>{{ money(fromCents(compCost)) }}</b></div>
          <div>Can assemble now: <b>{{ assemble }}</b></div>
          <div v-if="can('reports.financial')" class="sm:col-span-3">Package profit at this price: <b :class="toCents(f.selling_price) - compCost < 0 ? 'text-bad' : 'text-ok'">{{ money(fromCents(toCents(f.selling_price) - compCost)) }}</b></div>
        </div>
      </section>

      <p v-if="error" class="text-bad" role="alert">{{ error }}</p>
    </form>

    <section v-if="isEdit && ready" class="card p-4">
      <h3 class="font-semibold mb-2">Images</h3>
      <div class="flex flex-wrap gap-3">
        <div v-for="im in product.images" :key="im.id" class="w-28 text-center">
          <div class="rounded border-2" :class="product.image_path === im.thumb_path ? 'border-brand-600' : 'border-line'"><ProductImage :src="im.thumb_path" alt="" /></div>
          <div v-if="editable" class="flex justify-center gap-1 mt-1">
            <button class="text-xs text-brand-700" @click="primary(im.id)">Main</button>·<button class="text-xs text-bad" @click="removeImage(im.id)">Delete</button>
          </div>
        </div>
        <label v-if="editable" class="w-28 aspect-square border-2 border-dashed border-line rounded grid place-items-center text-xs text-muted cursor-pointer hover:border-brand-500">
          <input type="file" accept="image/*" multiple class="sr-only" @change="upload" />{{ uploading ? 'Uploading…' : '+ Add photos' }}
        </label>
      </div>
      <p class="text-xs text-muted mt-2">Photos are resized and converted to WebP on this device before uploading.</p>
    </section>

    <section v-if="isEdit && ready && product.type === 'standard'" class="card overflow-x-auto">
      <h3 class="font-semibold p-4 pb-2">Stock history</h3>
      <table class="tbl">
        <thead><tr><th>When</th><th>Type</th><th class="num">Qty</th><th class="num">Balance</th><th>Location</th><th>Reason</th><th>By</th></tr></thead>
        <tbody><tr v-for="m in movements" :key="m.id">
          <td class="text-xs whitespace-nowrap">{{ new Date(m.created_at).toLocaleString() }}</td><td><span class="badge bg-gray-100">{{ m.type.replace('_', ' ') }}</span></td>
          <td class="num font-semibold" :class="m.quantity < 0 ? 'text-bad' : 'text-ok'">{{ m.quantity > 0 ? '+' : '' }}{{ m.quantity }}</td><td class="num">{{ m.balance_after }}</td>
          <td>{{ m.location?.code }}</td><td class="text-xs">{{ m.reason }}</td><td class="text-xs">{{ m.user?.name }}</td></tr>
          <tr v-if="!movements.length"><td colspan="7" class="text-muted text-center py-4">No movements yet.</td></tr>
        </tbody>
      </table>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '@/components/Icon.vue'
import ProductImage from '@/components/ProductImage.vue'
import ProductPicker from '@/components/ProductPicker.vue'
import { api, del, errorText, get, post, put } from '@/lib/api'
import { toWebp } from '@/lib/image'
import { fromCents, money, toCents } from '@/lib/money'
import { toast } from '@/lib/toast'
import { can } from '@/stores/auth'
import { loadLookups, lookups } from '@/stores/lookups'

const route = useRoute()
const router = useRouter()
const isEdit = computed(() => !!route.params.id)
// Previous / Next within the list the user came from (saved by the Products page in this tab).
const nav = computed(() => {
  try {
    const l = JSON.parse(sessionStorage.getItem('admin.products.list') || 'null')
    const i = l?.ids?.indexOf(Number(route.params.id)) ?? -1
    if (!l || i < 0) return null
    return { label: l.label, pos: l.offset + i + 1, total: l.total ?? l.offset + l.ids.length, prev: l.ids[i - 1], next: l.ids[i + 1] }
  } catch {
    return null
  }
})
const editable = can('products.manage')
const ready = ref(false)
const busy = ref(false)
const uploading = ref(false)
const error = ref('')
const loadError = ref('')
const product = ref<any>({})
const movements = ref<any[]>([])
const components = ref<any[]>([])
const f = reactive<any>({
  type: 'standard', name: '', sku: '', barcode: '', model: '', category_id: null, brand_id: null, supplier_id: null, description: '',
  specifications: [] as { label: string; value: string }[], selling_price: '', compare_at_price: '', min_stock: 0,
  is_active: true, is_featured: false, show_online: true, opening_quantity: 0, opening_unit_cost: '',
})

const compPrice = computed(() => components.value.reduce((s, c) => s + toCents(c.selling_price) * (c.quantity || 0), 0))
const compCost = computed(() => components.value.reduce((s, c) => s + Math.round(toCents(c.cost_price) * (c.quantity || 0)), 0))
const assemble = computed(() => (components.value.length ? Math.min(...components.value.map((c) => Math.floor(c.total_stock / Math.max(1, c.quantity)))) : 0))
const margin = computed(() => {
  const p = toCents(f.selling_price)
  return p ? Math.round(((p - toCents(product.value.cost_price)) / p) * 1000) / 10 : 0
})

function fill(p: any) {
  product.value = p
  Object.assign(f, {
    type: p.type, name: p.name, sku: p.sku, barcode: p.barcode ?? '', model: p.model ?? '', category_id: p.category_id, brand_id: p.brand_id,
    supplier_id: p.supplier_id, description: p.description ?? '', specifications: p.specifications ?? [], selling_price: p.selling_price,
    compare_at_price: p.compare_at_price ?? '', min_stock: p.min_stock, is_active: p.is_active, is_featured: p.is_featured, show_online: p.show_online,
  })
  components.value = (p.bundle_items ?? []).map((b: any) => ({ product_id: b.product_id, quantity: b.quantity, ...b.product }))
}

async function init() {
  await loadLookups()
  if (isEdit.value) {
    try {
      fill(await get(`/admin/products/${route.params.id}`))
      if (can('inventory.view')) movements.value = (await get('/admin/inventory/movements', { product_id: String(route.params.id), per_page: 30 })).data
    } catch (e) {
      loadError.value = errorText(e)
    }
  }
  ready.value = true
}
init()

function addComponent(p: any) {
  if (components.value.some((c) => c.product_id === p.id)) return
  components.value.push({ product_id: p.id, quantity: 1, name: p.name, total_stock: p.total_stock, cost_price: p.cost_price, selling_price: p.selling_price })
}

async function save() {
  busy.value = true
  error.value = ''
  const body: any = { ...f, compare_at_price: f.compare_at_price || null, barcode: f.barcode || null, sku: f.sku || null }
  if (f.type === 'bundle') body.components = components.value.map((c) => ({ product_id: c.product_id, quantity: c.quantity }))
  if (isEdit.value || f.type === 'bundle' || !f.opening_quantity) {
    delete body.opening_quantity
    delete body.opening_unit_cost
  }
  if (isEdit.value) delete body.type
  try {
    const p = isEdit.value ? await put(`/admin/products/${route.params.id}`, body) : await post('/admin/products', body)
    toast('Product saved')
    if (!isEdit.value) router.replace(`/admin/products/${p.id}`)
    else fill(p)
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}

async function archive() {
  if (!confirm('Archive this product? It disappears from the shop and the till; its history is kept.')) return
  await post(`/admin/products/${product.value.id}/archive`)
  fill(await get(`/admin/products/${product.value.id}`))
}
async function restore() {
  await post(`/admin/products/${product.value.id}/restore`)
  fill(await get(`/admin/products/${product.value.id}`))
}

async function upload(e: Event) {
  const files = [...((e.target as HTMLInputElement).files ?? [])]
  uploading.value = true
  try {
    for (const file of files) {
      const large = await toWebp(file, 1000, 0.8)
      const thumb = await toWebp(file, 360, 0.75)
      const ext = large.blob.type === 'image/webp' ? 'webp' : 'jpg'
      const fd = new FormData()
      fd.append('image', large.blob, `image.${ext}`)
      fd.append('thumb', thumb.blob, `thumb.${ext}`)
      fd.append('width', String(large.width))
      fd.append('height', String(large.height))
      await api(`/admin/products/${product.value.id}/images`, { method: 'POST', body: fd, timeout: 60000 })
    }
    fill(await get(`/admin/products/${product.value.id}`))
    toast('Photos uploaded')
  } catch (err) {
    toast(errorText(err), 'error')
  } finally {
    uploading.value = false
  }
}
async function primary(id: number) {
  await post(`/admin/products/${product.value.id}/images/${id}/primary`)
  fill(await get(`/admin/products/${product.value.id}`))
}
async function removeImage(id: number) {
  if (!confirm('Delete this photo?')) return
  await del(`/admin/products/${product.value.id}/images/${id}`)
  fill(await get(`/admin/products/${product.value.id}`))
}
</script>
