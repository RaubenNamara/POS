<template>
  <div v-if="d" class="space-y-4 max-w-5xl 2xl:max-w-[max(80rem,70vw)]">
    <div class="flex flex-wrap items-start gap-3">
      <div class="flex-1">
        <h2 class="text-xl font-bold">{{ d.supplier.name }}</h2>
        <p class="text-sm text-muted">{{ d.supplier.contact_person }} {{ d.supplier.phone }} {{ d.supplier.email }}</p>
      </div>
      <div class="card px-4 py-2 text-right"><div class="text-xs text-muted">Balance owed</div><div class="text-xl font-bold" :class="Number(d.balance) > 0 ? 'text-warn' : ''">{{ money(d.balance) }}</div></div>
      <button class="btn-primary" :disabled="Number(d.balance) <= 0" @click="payOpen = true">Record payment</button>
      <button class="btn-outline" @click="editOpen = true">Edit</button>
    </div>
    <div class="grid lg:grid-cols-2 gap-4">
      <section class="card overflow-x-auto">
        <h3 class="font-semibold p-4 pb-2">Goods received (invoices)</h3>
        <table class="tbl"><thead><tr><th>GRN</th><th>Date</th><th>Invoice</th><th class="num">Total</th></tr></thead>
          <tbody><tr v-for="g in d.receipts" :key="g.id"><td>{{ g.number }}</td><td>{{ g.received_date?.slice(0, 10) }}</td><td>{{ g.supplier_invoice_no }}</td><td class="num">{{ money(g.total, false) }}</td></tr>
            <tr v-if="!d.receipts.length"><td colspan="4" class="text-muted text-center py-4">None</td></tr></tbody></table>
      </section>
      <section class="card overflow-x-auto">
        <h3 class="font-semibold p-4 pb-2">Payments</h3>
        <table class="tbl"><thead><tr><th>Date</th><th>Method</th><th>Ref</th><th class="num">Amount</th></tr></thead>
          <tbody><tr v-for="p in d.payments" :key="p.id"><td>{{ p.paid_date?.slice(0, 10) }}</td><td>{{ p.method?.name }}</td><td>{{ p.reference }}</td><td class="num">{{ money(p.amount, false) }}</td></tr>
            <tr v-if="!d.payments.length"><td colspan="4" class="text-muted text-center py-4">None</td></tr></tbody></table>
      </section>
    </div>
    <Modal :open="payOpen" title="Pay supplier" @close="payOpen = false">
      <form class="space-y-3" @submit.prevent="pay">
        <div><label class="label">Amount (owed {{ money(d.balance) }})</label><input v-model="p.amount" class="input" inputmode="decimal" required /></div>
        <div><label class="label">Method</label><select v-model="p.payment_method_id" class="input" required><option v-for="m in lookups.payment_methods" :key="m.id" :value="m.id">{{ m.name }}</option></select></div>
        <div><label class="label">Reference</label><input v-model="p.reference" class="input" /></div>
        <p v-if="error" class="text-sm text-bad">{{ error }}</p>
        <button class="btn-primary w-full" :disabled="busy">Save payment</button>
      </form>
    </Modal>
    <Modal :open="editOpen" title="Edit supplier" @close="editOpen = false"><SupplierForm :supplier="d.supplier" @saved="editOpen = false; load()" /></Modal>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import Modal from '@/components/Modal.vue'
import SupplierForm from '@/components/SupplierForm.vue'
import { errorText, get, post, uuid } from '@/lib/api'
import { money } from '@/lib/money'
import { toast } from '@/lib/toast'
import { loadLookups, lookups } from '@/stores/lookups'

const route = useRoute()
const d = ref<any>(null)
const payOpen = ref(false)
const editOpen = ref(false)
const busy = ref(false)
const error = ref('')
const p = reactive<any>({ amount: '', payment_method_id: null, reference: '', key: uuid() })

const load = async () => (d.value = await get(`/admin/suppliers/${route.params.id}`))
loadLookups().then(() => (p.payment_method_id = lookups.payment_methods[0]?.id))
load()

async function pay() {
  busy.value = true
  error.value = ''
  try {
    await post('/admin/supplier-payments', { client_uuid: p.key, supplier_id: d.value.supplier.id, amount: p.amount, payment_method_id: p.payment_method_id, reference: p.reference })
    toast('Payment recorded')
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
