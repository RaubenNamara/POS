<template>
  <form class="max-w-4xl 2xl:max-w-6xl space-y-4" @submit.prevent="submit">
    <section class="card p-4 space-y-3">
      <div class="grid sm:grid-cols-3 gap-2">
        <label v-for="t in types" :key="t.v" class="border rounded-md p-2 text-sm cursor-pointer" :class="f.type === t.v ? 'border-brand-600 bg-brand-50' : 'border-line'">
          <input v-model="f.type" type="radio" :value="t.v" class="sr-only" /><b>{{ t.l }}</b><div class="text-xs text-muted">{{ t.d }}</div>
        </label>
      </div>
      <div class="grid sm:grid-cols-3 gap-3">
        <div><label class="label">{{ f.type === 'transfer' ? 'From location' : 'Location' }}</label>
          <select v-model="f.location_id" class="input"><option v-for="l in lookups.locations" :key="l.id" :value="l.id">{{ l.name }}</option></select></div>
        <div v-if="f.type === 'transfer'"><label class="label">To location</label>
          <select v-model="f.to_location_id" class="input" required><option v-for="l in lookups.locations.filter((x) => x.id !== f.location_id)" :key="l.id" :value="l.id">{{ l.name }}</option></select></div>
        <div class="sm:col-span-2" :class="{ 'sm:col-span-1': f.type === 'transfer' }"><label class="label">Reason (required)</label><input v-model="f.reason" class="input" required placeholder="e.g. Dropped on delivery, monthly count" /></div>
      </div>
    </section>

    <section class="card p-4 space-y-3">
      <ProductPicker type="standard" @pick="addLine" />
      <table class="tbl">
        <thead><tr><th>Product</th><th class="num">Current</th><th class="num">{{ qtyLabel }}</th><th v-if="f.type === 'opening'" class="num">Unit cost</th><th v-if="f.type === 'count'" class="num">Difference</th><th></th></tr></thead>
        <tbody>
          <tr v-for="(l, i) in lines" :key="l.product_id">
            <td>{{ l.name }}<div class="text-xs text-muted">{{ l.sku }}</div></td>
            <td class="num">{{ l.current }}</td>
            <td class="num"><input v-model.number="l.quantity" type="number" :min="f.type === 'adjustment' ? undefined : 0" class="input h-8 w-24 text-right" required /></td>
            <td v-if="f.type === 'opening'" class="num"><input v-model="l.unit_cost" class="input h-8 w-28 text-right" inputmode="decimal" required /></td>
            <td v-if="f.type === 'count'" class="num font-semibold" :class="l.quantity - l.current < 0 ? 'text-bad' : 'text-ok'">{{ l.quantity - l.current > 0 ? '+' : '' }}{{ l.quantity - l.current }}</td>
            <td><button type="button" class="btn-ghost btn-sm" @click="lines.splice(i, 1)"><Icon name="x" :size="14" /></button></td>
          </tr>
          <tr v-if="!lines.length"><td colspan="6" class="text-center text-muted py-6">Search or scan products above.</td></tr>
        </tbody>
      </table>
      <p class="text-xs text-muted">{{ hint }}</p>
    </section>
    <p v-if="error" class="text-bad">{{ error }}</p>
    <button class="btn-primary" :disabled="busy || !lines.length">{{ busy ? 'Saving…' : 'Record stock change' }}</button>
  </form>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import Icon from '@/components/Icon.vue'
import ProductPicker from '@/components/ProductPicker.vue'
import { errorText, get, post } from '@/lib/api'
import { toast } from '@/lib/toast'
import { loadLookups, lookups } from '@/stores/lookups'

const route = useRoute()
const types = [
  { v: 'count', l: 'Stock count', d: 'Set to what is physically on the shelf' },
  { v: 'damaged', l: 'Damaged', d: 'Write off broken items' },
  { v: 'missing', l: 'Missing / theft', d: 'Write off lost items' },
  { v: 'adjustment', l: 'Correction', d: '+ found or − removed' },
  { v: 'opening', l: 'Opening stock', d: 'Initial quantity with cost' },
  { v: 'transfer', l: 'Transfer', d: 'Move between locations' },
]
const f = reactive<any>({ type: 'count', location_id: null, to_location_id: null, reason: '' })
const lines = ref<any[]>([])
const busy = ref(false)
const error = ref('')
const qtyLabel = computed(() => ({ count: 'Counted', adjustment: '+/− qty', opening: 'Qty in' } as any)[f.type] ?? 'Qty out')
const hint = computed(() =>
  f.type === 'count' ? 'The difference is recorded as a movement and valued at average cost in the profit report.'
    : f.type === 'adjustment' ? 'Use negative numbers to remove stock.' : 'Every change is logged with your name and the reason.')

loadLookups().then(() => (f.location_id = lookups.locations[0]?.id))

function stockAt(p: any) {
  return p.stock_levels?.find((s: any) => s.location_id === f.location_id)?.quantity ?? 0
}
async function addLine(p: any) {
  if (lines.value.some((l) => l.product_id === p.id)) return
  const full = await get(`/admin/products/${p.id}`)
  const current = stockAt(full)
  lines.value.push({ product_id: p.id, name: p.name, sku: p.sku, current, quantity: f.type === 'count' ? current : 1, unit_cost: full.cost_price ?? '' })
}
if (route.query.product) get(`/admin/products/${route.query.product}`).then((p) => addLine(p))

async function submit() {
  busy.value = true
  error.value = ''
  try {
    const r = await post('/admin/inventory/adjust', { ...f, lines: lines.value.map((l) => ({ product_id: l.product_id, quantity: l.quantity, unit_cost: f.type === 'opening' ? l.unit_cost : undefined })) })
    toast(r.message)
    lines.value = []
    f.reason = ''
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}
</script>
