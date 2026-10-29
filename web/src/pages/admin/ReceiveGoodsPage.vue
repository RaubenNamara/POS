<template>
  <form class="max-w-5xl 2xl:max-w-[max(80rem,70vw)] space-y-4" @submit.prevent="submit">
    <section class="card p-4 space-y-3">
      <div v-if="po" class="rounded bg-brand-50 p-2 text-sm">Receiving against <b>{{ po.number }}</b> from <b>{{ po.supplier.name }}</b>. Enter what actually arrived.</div>
      <div class="grid sm:grid-cols-4 gap-3">
        <div v-if="!po"><label class="label">Supplier *</label><select v-model="f.supplier_id" class="input" required><option v-for="s in lookups.suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
        <div v-if="!po"><label class="label">Location</label><select v-model="f.location_id" class="input"><option v-for="l in lookups.locations" :key="l.id" :value="l.id">{{ l.name }}</option></select></div>
        <div><label class="label">Supplier invoice no.</label><input v-model="f.supplier_invoice_no" class="input" /></div>
        <div><label class="label">Received on</label><input v-model="f.received_date" type="date" class="input" :max="today" /></div>
        <div><label class="label">Payment due</label><input v-model="f.due_date" type="date" class="input" /></div>
      </div>
    </section>
    <section class="card p-4 space-y-3">
      <ProductPicker v-if="!po" type="standard" @pick="add" />
      <table class="tbl">
        <thead><tr><th>Product</th><th v-if="po" class="num">Ordered / received</th><th class="num">Qty arrived</th><th class="num">Unit cost</th><th class="num">Line</th><th></th></tr></thead>
        <tbody>
          <tr v-for="(l, i) in lines" :key="l.key">
            <td>{{ l.name }}<div class="text-xs text-muted">{{ l.sku }}</div></td>
            <td v-if="po" class="num text-muted">{{ l.ordered }} / {{ l.received }}</td>
            <td class="num"><input v-model.number="l.quantity" type="number" min="0" class="input h-8 w-20 text-right" /></td>
            <td class="num"><input v-model="l.unit_cost" class="input h-8 w-28 text-right" inputmode="decimal" /></td>
            <td class="num">{{ money(fromCents(Math.round(toCents(l.unit_cost) * (l.quantity || 0))), false) }}</td>
            <td><button v-if="!po" type="button" class="btn-ghost btn-sm" @click="lines.splice(i, 1)"><Icon name="x" :size="14" /></button></td>
          </tr>
          <tr v-if="!lines.length"><td colspan="6" class="text-center text-muted py-6">Search or scan products as they are unpacked.</td></tr>
        </tbody>
      </table>
      <p class="text-right text-lg font-bold">Invoice total {{ money(fromCents(total)) }}</p>
    </section>
    <section v-if="can('suppliers.manage')" class="card p-4 grid sm:grid-cols-3 gap-3 items-end">
      <div><label class="label">Paid now (optional)</label><input v-model="pay.amount" class="input" inputmode="decimal" placeholder="0" /></div>
      <div><label class="label">Method</label><select v-model="pay.payment_method_id" class="input"><option v-for="m in lookups.payment_methods" :key="m.id" :value="m.id">{{ m.name }}</option></select></div>
      <div><label class="label">Reference</label><input v-model="pay.reference" class="input" /></div>
      <p class="sm:col-span-3 text-xs text-muted">Unpaid amounts are added to what you owe this supplier. Stock bought is inventory capital, not an expense.</p>
    </section>
    <p v-if="error" class="text-bad">{{ error }}</p>
    <button class="btn-primary h-11" :disabled="busy || !lines.some((l) => l.quantity > 0)">{{ busy ? 'Saving…' : 'Receive goods into stock' }}</button>
  </form>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '@/components/Icon.vue'
import ProductPicker from '@/components/ProductPicker.vue'
import { errorText, get, post, uuid } from '@/lib/api'
import { fromCents, money, toCents } from '@/lib/money'
import { toast } from '@/lib/toast'
import { can } from '@/stores/auth'
import { loadLookups, lookups } from '@/stores/lookups'

const route = useRoute()
const router = useRouter()
const today = new Date().toISOString().slice(0, 10)
const po = ref<any>(null)
const lines = ref<any[]>([])
const f = reactive<any>({ supplier_id: null, location_id: null, supplier_invoice_no: '', received_date: today, due_date: '' })
const pay = reactive<any>({ amount: '', payment_method_id: null, reference: '' })
const busy = ref(false)
const error = ref('')
const key = uuid() // same key on retry: a timed-out submit can't receive the goods twice
const total = computed(() => lines.value.reduce((s, l) => s + Math.round(toCents(l.unit_cost) * (l.quantity || 0)), 0))

loadLookups().then(() => {
  f.supplier_id ??= lookups.suppliers[0]?.id
  f.location_id ??= lookups.locations[0]?.id
  pay.payment_method_id = lookups.payment_methods[0]?.id
})
if (route.query.po) {
  get(`/admin/purchase-orders/${route.query.po}`).then((o) => {
    po.value = o
    lines.value = o.items.map((it: any) => ({
      key: it.id, purchase_order_item_id: it.id, product_id: it.product_id, name: it.product.name, sku: it.product.sku,
      ordered: it.quantity_ordered, received: it.quantity_received, quantity: Math.max(0, it.quantity_ordered - it.quantity_received), unit_cost: it.unit_cost,
    }))
  })
}

function add(p: any) {
  const ex = lines.value.find((l) => l.product_id === p.id)
  if (ex) ex.quantity++
  else lines.value.push({ key: p.id, product_id: p.id, name: p.name, sku: p.sku, quantity: 1, unit_cost: p.cost_price ?? '0' })
}

async function submit() {
  busy.value = true
  error.value = ''
  try {
    const items = lines.value.filter((l) => l.quantity > 0).map((l) => ({ product_id: l.product_id, quantity: l.quantity, unit_cost: l.unit_cost, purchase_order_item_id: l.purchase_order_item_id }))
    const body: any = { client_uuid: key, ...f, due_date: f.due_date || null, items, purchase_order_id: po.value?.id }
    if (po.value) delete body.supplier_id
    if (toCents(pay.amount) > 0) body.payment = { ...pay }
    const r = await post('/admin/goods-receipts', body)
    toast(`${r.number} received. Stock updated`)
    router.push('/admin/goods-receipts')
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}
</script>
