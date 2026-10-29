<template>
  <div v-if="d" class="space-y-4 max-w-5xl 2xl:max-w-[max(80rem,70vw)]">
    <div class="flex flex-wrap items-start gap-3">
      <div class="flex-1">
        <h2 class="text-xl font-bold">{{ d.customer.name }}</h2>
        <p class="text-sm text-muted">{{ d.customer.phone }} {{ d.customer.email }} · credit limit {{ money(d.customer.credit_limit) }}</p>
      </div>
      <div class="card px-4 py-2 text-right"><div class="text-xs text-muted">Owes</div><div class="text-xl font-bold" :class="Number(d.balance) > 0 ? 'text-warn' : ''">{{ money(d.balance) }}</div></div>
      <button v-if="can('customers.collect')" class="btn-primary" :disabled="Number(d.balance) <= 0" @click="payOpen = true">Collect payment</button>
      <button v-if="can('customers.manage')" class="btn-outline" @click="editOpen = true">Edit</button>
    </div>
    <section class="card overflow-x-auto">
      <h3 class="font-semibold p-4 pb-2">Purchase history</h3>
      <table class="tbl"><thead><tr><th>No.</th><th>Date</th><th>Status</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th></tr></thead>
        <tbody><tr v-for="s in d.sales" :key="s.id" class="hover:bg-gray-50 cursor-pointer" @click="router.push(`/admin/sales/${s.id}`)">
          <td>{{ s.number }}</td><td class="text-xs">{{ new Date(s.created_at).toLocaleString() }}</td><td>{{ s.status }} · {{ s.payment_status }}</td>
          <td class="num">{{ money(s.total, false) }}</td><td class="num">{{ money(s.amount_paid, false) }}</td><td class="num" :class="Number(s.balance_due) > 0 ? 'text-warn font-semibold' : ''">{{ money(s.balance_due, false) }}</td></tr>
          <tr v-if="!d.sales.length"><td colspan="6" class="text-center text-muted py-4">No purchases yet.</td></tr></tbody></table>
    </section>
    <section class="card overflow-x-auto">
      <h3 class="font-semibold p-4 pb-2">Payment history</h3>
      <table class="tbl"><thead><tr><th>When</th><th>Sale</th><th>Method</th><th>Kind</th><th class="num">Amount</th></tr></thead>
        <tbody><tr v-for="p in d.payments" :key="p.id"><td class="text-xs">{{ new Date(p.paid_at).toLocaleString() }}</td><td>{{ p.sale?.number }}</td><td>{{ p.method?.name }}</td><td>{{ p.kind }}</td><td class="num">{{ money(p.amount, false) }}</td></tr></tbody></table>
    </section>
    <Modal :open="payOpen" title="Collect payment" @close="payOpen = false">
      <form class="space-y-3" @submit.prevent="collect">
        <p class="text-sm text-muted">Applied to the oldest unpaid sales first.</p>
        <div><label class="label">Amount (owes {{ money(d.balance) }})</label><input v-model="p.amount" class="input" inputmode="decimal" required /></div>
        <div><label class="label">Method</label><select v-model="p.payment_method_id" class="input"><option v-for="m in lookups.payment_methods" :key="m.id" :value="m.id">{{ m.name }}</option></select></div>
        <div><label class="label">Reference</label><input v-model="p.reference" class="input" /></div>
        <p v-if="error" class="text-sm text-bad">{{ error }}</p>
        <button class="btn-primary w-full" :disabled="busy">Confirm money received</button>
      </form>
    </Modal>
    <Modal :open="editOpen" title="Edit customer" @close="editOpen = false"><CustomerForm :customer="d.customer" @saved="editOpen = false; load()" /></Modal>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import CustomerForm from '@/components/CustomerForm.vue'
import Modal from '@/components/Modal.vue'
import { errorText, get, post, uuid } from '@/lib/api'
import { money } from '@/lib/money'
import { toast } from '@/lib/toast'
import { can } from '@/stores/auth'
import { loadLookups, lookups } from '@/stores/lookups'

const route = useRoute()
const router = useRouter()
const d = ref<any>(null)
const payOpen = ref(false)
const editOpen = ref(false)
const busy = ref(false)
const error = ref('')
const p = reactive<any>({ amount: '', payment_method_id: null, reference: '', key: uuid() })
const load = async () => (d.value = await get(`/admin/customers/${route.params.id}`))
loadLookups().then(() => (p.payment_method_id = lookups.payment_methods[0]?.id))
load()

async function collect() {
  busy.value = true
  error.value = ''
  try {
    const r = await post(`/admin/customers/${d.value.customer.id}/collect`, { client_uuid: p.key, amount: p.amount, payment_method_id: p.payment_method_id, reference: p.reference || null })
    toast(`Payment recorded. Remaining balance ${money(r.balance)}`)
    Object.assign(p, { amount: '', reference: '', key: uuid() })
    payOpen.value = false
    load()
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}
</script>
