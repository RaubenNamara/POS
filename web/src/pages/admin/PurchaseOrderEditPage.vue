<template>
  <form class="max-w-4xl 2xl:max-w-6xl space-y-4" @submit.prevent="save">
    <section class="card p-4 grid sm:grid-cols-3 gap-3">
      <div><label class="label">Supplier *</label><select v-model="f.supplier_id" class="input" required><option v-for="s in lookups.suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
      <div><label class="label">Deliver to</label><select v-model="f.location_id" class="input"><option v-for="l in lookups.locations" :key="l.id" :value="l.id">{{ l.name }}</option></select></div>
      <div><label class="label">Expected date</label><input v-model="f.expected_date" type="date" class="input" /></div>
      <div class="sm:col-span-3"><label class="label">Notes</label><input v-model="f.notes" class="input" /></div>
    </section>
    <section class="card p-4 space-y-3">
      <ProductPicker type="standard" @pick="add" />
      <table class="tbl">
        <thead><tr><th>Product</th><th class="num">In stock</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Line</th><th></th></tr></thead>
        <tbody>
          <tr v-for="(l, i) in f.items" :key="l.product_id">
            <td>{{ l.name }}</td><td class="num">{{ l.stock }}</td>
            <td class="num"><input v-model.number="l.quantity" type="number" min="1" class="input h-8 w-20 text-right" /></td>
            <td class="num"><input v-model="l.unit_cost" class="input h-8 w-28 text-right" inputmode="decimal" /></td>
            <td class="num">{{ money(fromCents(Math.round(toCents(l.unit_cost) * l.quantity)), false) }}</td>
            <td><button type="button" class="btn-ghost btn-sm" @click="f.items.splice(i, 1)"><Icon name="x" :size="14" /></button></td>
          </tr>
        </tbody>
      </table>
      <p class="text-right font-bold">Total {{ money(fromCents(total)) }}</p>
    </section>
    <p v-if="error" class="text-bad">{{ error }}</p>
    <div class="flex gap-2">
      <button class="btn-outline" :disabled="busy || !f.items.length" @click="f.status = 'draft'">Save draft</button>
      <button class="btn-primary" :disabled="busy || !f.items.length" @click="f.status = 'ordered'">Save as ordered</button>
    </div>
  </form>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '@/components/Icon.vue'
import ProductPicker from '@/components/ProductPicker.vue'
import { errorText, post } from '@/lib/api'
import { fromCents, money, toCents } from '@/lib/money'
import { toast } from '@/lib/toast'
import { loadLookups, lookups } from '@/stores/lookups'

const router = useRouter()
const f = reactive<any>({ supplier_id: null, location_id: null, expected_date: '', notes: '', status: 'ordered', items: [] })
const busy = ref(false)
const error = ref('')
const total = computed(() => f.items.reduce((s: number, l: any) => s + Math.round(toCents(l.unit_cost) * l.quantity), 0))
loadLookups().then(() => {
  f.supplier_id = lookups.suppliers[0]?.id
  f.location_id = lookups.locations[0]?.id
})

function add(p: any) {
  if (!f.items.some((l: any) => l.product_id === p.id)) f.items.push({ product_id: p.id, name: p.name, stock: p.total_stock, quantity: 1, unit_cost: p.cost_price ?? '0' })
}
async function save() {
  busy.value = true
  error.value = ''
  try {
    const po = await post('/admin/purchase-orders', { ...f, expected_date: f.expected_date || null, items: f.items.map((l: any) => ({ product_id: l.product_id, quantity: l.quantity, unit_cost: l.unit_cost })) })
    toast(`Purchase order ${po.number} saved`)
    router.push('/admin/purchase-orders')
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}
</script>
