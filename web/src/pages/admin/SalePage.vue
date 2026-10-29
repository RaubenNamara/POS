<template>
  <div v-if="error" class="card p-4 text-bad">{{ error }}</div>
  <div v-else-if="s" class="space-y-4 max-w-5xl 2xl:max-w-[max(80rem,70vw)]">
    <div class="flex flex-wrap items-center gap-2">
      <div class="flex-1">
        <h2 class="text-xl font-bold">{{ s.channel === 'online' ? 'Order' : 'Sale' }} {{ s.number }}</h2>
        <p class="text-sm text-muted">{{ new Date(s.created_at).toLocaleString() }} · {{ s.location?.name }} · {{ s.cashier?.name ?? 'Online shop' }}<span v-if="s.offline"> · rung up offline {{ s.offline_created_at }}</span></p>
      </div>
      <RouterLink :to="`/print/sale/${s.id}`" target="_blank" class="btn-outline btn-sm"><Icon name="printer" :size="14" /> Reprint</RouterLink>
      <a :href="`/api/admin/sales/${s.id}/invoice.pdf`" target="_blank" class="btn-outline btn-sm"><Icon name="download" :size="14" /> PDF</a>
      <button v-if="can('customers.collect') && Number(s.balance_due) > 0 && s.status !== 'cancelled'" class="btn-primary btn-sm" @click="payOpen = true">Record payment</button>
      <button v-if="can('sales.refund') && s.status === 'completed' && returnable" class="btn-outline btn-sm" @click="openReturn">Return / refund</button>
    </div>

    <section v-if="s.channel === 'online' && s.status === 'pending' && can('orders.manage')" class="card p-4">
      <h3 class="font-semibold text-sm mb-2">Order progress</h3>
      <div class="flex flex-wrap gap-2">
        <button v-for="st in steps" :key="st" class="btn-sm btn" :class="s.fulfillment_status === st ? 'bg-brand-600 text-white' : 'btn-outline'" :disabled="busy" @click="setStatus(st)">{{ st.replace(/_/g, ' ') }}</button>
        <button class="btn-sm btn-danger ml-auto" :disabled="busy" @click="setStatus('cancelled')">Cancel order</button>
      </div>
      <p v-if="s.fulfillment_status === 'awaiting_payment'" class="text-sm text-warn mb-2">Waiting for the customer's online payment (stock held until {{ new Date(s.payment_expires_at).toLocaleString() }}). Only cancelling is possible until it is paid.</p>
      <p class="text-xs text-muted mt-2">Payment: <b>{{ ({ cod: 'pay on delivery', online: 'online (Flutterwave)', pickup: 'pay at pickup' } as Record<string, string>)[s.payment_option] ?? '-' }}</b><span v-if="s.delivery_district"> · district {{ s.delivery_district }}</span>
        <a v-if="s.delivery_lat" :href="`https://www.google.com/maps?q=${s.delivery_lat},${s.delivery_lng}`" target="_blank" rel="noopener" class="text-brand-700 ml-1">open pin in Google Maps</a></p>
      <p class="text-xs text-muted mt-1">Deliver to: {{ s.delivery_name }} · {{ s.delivery_phone }} · {{ s.delivery_address }}. Revenue is counted when marked delivered; record the money received first.</p>
    </section>

    <div class="grid lg:grid-cols-[2fr_1fr] gap-4">
      <section class="card overflow-x-auto">
        <table class="tbl">
          <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Returned</th><th class="num">Price</th><th class="num">Discount</th><th class="num">Amount</th><th v-if="can('reports.financial')" class="num">Cost</th></tr></thead>
          <tbody>
            <template v-for="i in s.items" :key="i.id">
              <tr><td>{{ i.name }}<div class="text-xs text-muted">{{ i.sku }}</div></td><td class="num">{{ i.quantity }}</td><td class="num">{{ i.returned_quantity || '' }}</td>
                <td class="num">{{ money(i.unit_price, false) }}</td><td class="num">{{ Number(i.discount) ? money(i.discount, false) : '' }}</td><td class="num">{{ money(i.amount, false) }}</td>
                <td v-if="can('reports.financial')" class="num text-muted">{{ money(i.cost_total, false) }}</td></tr>
              <tr v-for="c in i.components" :key="`c${c.id}`" class="text-xs text-muted"><td class="pl-6" colspan="7">↳ {{ c.quantity }} × {{ c.product?.name }}</td></tr>
            </template>
          </tbody>
        </table>
      </section>
      <section class="card p-4 text-sm space-y-1.5 h-max">
        <Row l="Subtotal" :v="s.subtotal" /><Row v-if="Number(s.discount_total)" l="Discounts" :v="`-${s.discount_total}`" />
        <Row v-if="Number(s.delivery_fee)" l="Delivery" :v="s.delivery_fee" /><Row v-if="Number(s.tax_total)" l="Tax" :v="s.tax_total" />
        <Row l="Total" :v="s.total" b /><Row v-if="Number(s.refunded_total)" l="Returned" :v="`-${s.refunded_total}`" />
        <Row l="Paid (net)" :v="s.amount_paid" /><Row v-if="Number(s.change_given)" l="Change given" :v="s.change_given" />
        <Row l="Balance due" :v="s.balance_due" b />
        <Row v-if="can('reports.financial')" l="Gross profit" :v="profit" />
        <div class="pt-2"><span class="badge bg-gray-100 mr-1">{{ s.status }}</span><span class="badge bg-gray-100">{{ s.payment_status }}</span></div>
        <div v-if="s.customer" class="pt-2 border-t border-line">Customer: <RouterLink :to="`/admin/customers/${s.customer.id}`" class="text-brand-700">{{ s.customer.name }}</RouterLink></div>
      </section>
    </div>

    <section class="card overflow-x-auto">
      <h3 class="font-semibold p-4 pb-2">Payments</h3>
      <table class="tbl"><thead><tr><th>When</th><th>Method</th><th>Kind</th><th>Reference</th><th>By</th><th class="num">Amount</th></tr></thead>
        <tbody><tr v-for="p in s.payments" :key="p.id"><td class="text-xs">{{ new Date(p.paid_at).toLocaleString() }}</td><td>{{ p.method?.name }}</td><td>{{ p.kind }}</td><td>{{ p.reference }}</td><td>{{ p.user?.name }}</td>
          <td class="num" :class="p.kind === 'refund' ? 'text-bad' : ''">{{ p.kind === 'refund' ? '-' : '' }}{{ money(p.amount, false) }}</td></tr>
          <tr v-if="!s.payments.length"><td colspan="6" class="text-muted text-center py-4">No money received yet.</td></tr></tbody></table>
    </section>
    <section v-if="s.returns.length" class="card overflow-x-auto">
      <h3 class="font-semibold p-4 pb-2">Returns</h3>
      <table class="tbl"><thead><tr><th>No.</th><th>When</th><th>Reason</th><th>By</th><th class="num">Value</th><th class="num">Cash back</th></tr></thead>
        <tbody><tr v-for="r in s.returns" :key="r.id"><td>{{ r.number }}</td><td class="text-xs">{{ new Date(r.created_at).toLocaleString() }}</td><td>{{ r.reason }}</td><td>{{ r.user?.name }}</td><td class="num">{{ money(r.refund_total, false) }}</td><td class="num">{{ money(r.cash_refunded, false) }}</td></tr></tbody></table>
    </section>

    <Modal :open="payOpen" title="Record payment" @close="payOpen = false">
      <form class="space-y-3" @submit.prevent="addPayment">
        <div><label class="label">Amount (balance {{ money(s.balance_due) }})</label><input v-model="pay.amount" class="input" inputmode="decimal" required /></div>
        <div><label class="label">Method</label><select v-model="pay.payment_method_id" class="input"><option v-for="m in lookups.payment_methods" :key="m.id" :value="m.id">{{ m.name }}</option></select></div>
        <div><label class="label">Reference {{ methodNeedsRef ? '(required)' : '' }}</label><input v-model="pay.reference" class="input" :required="methodNeedsRef" /></div>
        <p v-if="actionError" class="text-sm text-bad">{{ actionError }}</p>
        <button class="btn-primary w-full" :disabled="busy">Confirm money received</button>
      </form>
    </Modal>

    <Modal :open="retOpen" title="Return items" wide @close="retOpen = false">
      <form class="space-y-3" @submit.prevent="doReturn">
        <table class="tbl"><thead><tr><th>Item</th><th class="num">Can return</th><th class="num">Return qty</th><th>Back to stock?</th></tr></thead>
          <tbody><tr v-for="l in ret.lines" :key="l.sale_item_id"><td>{{ l.name }}</td><td class="num">{{ l.max }}</td>
            <td class="num"><input v-model.number="l.quantity" type="number" min="0" :max="l.max" class="input h-8 w-20 text-right" /></td>
            <td><label class="text-sm flex items-center gap-1"><input v-model="l.restock" type="checkbox" class="accent-brand-600" /> {{ l.restock ? 'Resell' : 'Damaged (write off)' }}</label></td></tr></tbody></table>
        <div><label class="label">Reason *</label><input v-model="ret.reason" class="input" required /></div>
        <div><label class="label">Refund paid out by (if money goes back)</label><select v-model="ret.payment_method_id" class="input"><option v-for="m in lookups.payment_methods" :key="m.id" :value="m.id">{{ m.name }}</option></select></div>
        <p class="text-xs text-muted">Any unpaid balance on this sale is reduced first; only money the customer actually paid beyond the new total is refunded.</p>
        <p v-if="actionError" class="text-sm text-bad">{{ actionError }}</p>
        <button class="btn-primary w-full" :disabled="busy || !ret.lines.some((l: any) => l.quantity > 0)">Process return</button>
      </form>
    </Modal>
  </div>
  <div v-else class="skeleton h-64 max-w-5xl 2xl:max-w-[max(80rem,70vw)]"></div>
</template>

<script setup lang="ts">
import { computed, defineComponent, h, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { errorText, get, post, uuid } from '@/lib/api'
import { fromCents, money, toCents } from '@/lib/money'
import { toast } from '@/lib/toast'
import { can } from '@/stores/auth'
import { loadLookups, lookups } from '@/stores/lookups'

const route = useRoute()
const s = ref<any>(null)
const error = ref('')
const actionError = ref('')
const busy = ref(false)
const payOpen = ref(false)
const retOpen = ref(false)
const steps = ['confirmed', 'processing', 'ready', 'out_for_delivery', 'delivered']
const pay = reactive<any>({ amount: '', payment_method_id: null, reference: '', key: uuid() })
const ret = reactive<any>({ lines: [], reason: '', payment_method_id: null, key: uuid() })
const returnable = computed(() => s.value?.items.some((i: any) => i.quantity > i.returned_quantity))
const methodNeedsRef = computed(() => !!lookups.payment_methods.find((m) => m.id === pay.payment_method_id)?.requires_reference)
const profit = computed(() => fromCents(toCents(s.value.total) - toCents(s.value.tax_total) - toCents(s.value.refunded_total) + toCents(s.value.refunded_tax) - toCents(s.value.cost_total) + toCents(s.value.returned_cost_total)))

const load = () => get(`/admin/sales/${route.params.id}`).then((r) => (s.value = r)).catch((e) => (error.value = errorText(e)))
loadLookups().then(() => {
  pay.payment_method_id = lookups.payment_methods[0]?.id
  ret.payment_method_id = lookups.payment_methods[0]?.id
})
load()

async function act(fn: () => Promise<any>, ok: string) {
  busy.value = true
  actionError.value = ''
  try {
    await fn()
    toast(ok)
    await load()
    return true
  } catch (e) {
    actionError.value = errorText(e)
    toast(actionError.value, 'error')
    return false
  } finally {
    busy.value = false
  }
}

async function addPayment() {
  if (await act(() => post(`/admin/sales/${s.value.id}/payments`, { client_uuid: pay.key, payment_method_id: pay.payment_method_id, amount: pay.amount, reference: pay.reference || null }), 'Payment recorded')) {
    payOpen.value = false
    Object.assign(pay, { amount: '', reference: '', key: uuid() })
  }
}
function openReturn() {
  ret.lines = s.value.items.filter((i: any) => i.quantity > i.returned_quantity).map((i: any) => ({ sale_item_id: i.id, name: i.name, max: i.quantity - i.returned_quantity, quantity: 0, restock: true }))
  retOpen.value = true
}
async function doReturn() {
  const items = ret.lines.filter((l: any) => l.quantity > 0).map((l: any) => ({ sale_item_id: l.sale_item_id, quantity: l.quantity, restock: l.restock }))
  if (await act(() => post(`/admin/sales/${s.value.id}/returns`, { client_uuid: ret.key, reason: ret.reason, payment_method_id: ret.payment_method_id, items }), 'Return processed')) {
    retOpen.value = false
    Object.assign(ret, { reason: '', key: uuid() })
  }
}
function setStatus(st: string) {
  if (st === 'cancelled' && !confirm('Cancel this order? Its items go back into stock.')) return
  act(() => post(`/admin/orders/${s.value.id}/status`, { status: st }), `Order ${st.replace(/_/g, ' ')}`)
}

const Row = defineComponent({
  props: { l: String, v: String, b: Boolean },
  setup: (p) => () => h('div', { class: ['flex justify-between', p.b && 'font-bold'] }, [h('span', p.l), h('span', { class: 'num' }, money(p.v ?? '0'))]),
})
</script>
