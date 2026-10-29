<template>
  <div class="space-y-3">
    <div class="card overflow-x-auto">
      <table class="tbl">
        <thead><tr><th>Cashier</th><th>Opened</th><th>Closed</th><th class="num">Float</th><th class="num">Expected cash</th><th class="num">Counted</th><th class="num">Difference</th><th>Status</th></tr></thead>
        <tbody>
          <tr v-for="s in rows" :key="s.id" class="hover:bg-gray-50 cursor-pointer" @click="show(s)">
            <td>{{ s.user?.name }}</td><td class="text-xs">{{ new Date(s.opened_at).toLocaleString() }}</td><td class="text-xs">{{ s.closed_at ? new Date(s.closed_at).toLocaleString() : '' }}</td>
            <td class="num">{{ money(s.opening_float, false) }}</td><td class="num">{{ s.expected_cash ? money(s.expected_cash, false) : '' }}</td><td class="num">{{ s.counted_cash ? money(s.counted_cash, false) : '' }}</td>
            <td class="num font-semibold" :class="s.cash_difference && Number(s.cash_difference) !== 0 ? 'text-bad' : 'text-ok'">{{ s.cash_difference !== null ? money(s.cash_difference, false) : '' }}</td>
            <td><span class="badge" :class="s.status === 'open' ? 'bg-brand-50 text-brand-700' : 'bg-gray-100'">{{ s.status }}</span></td>
          </tr>
          <tr v-if="!rows.length"><td colspan="8" class="text-center text-muted py-8">No shifts yet.</td></tr>
        </tbody>
      </table>
    </div>
    <Pager :page="meta.current_page" :last="meta.last_page" @go="go" />
    <Modal :open="!!sum" title="Shift summary" @close="sum = null">
      <table v-if="sum" class="tbl">
        <tr><td>Sales</td><td class="num">{{ sum.sales_count }} · {{ money(sum.sales_total) }}</td></tr>
        <tr v-for="m in sum.by_method" :key="m.id"><td>{{ m.name }} (net)</td><td class="num">{{ money(m.net) }}</td></tr>
        <tr><td>Credit given</td><td class="num">{{ money(sum.credit_given) }}</td></tr>
        <tr><td>Opening float</td><td class="num">{{ money(sum.opening_float) }}</td></tr>
        <tr><td>Cash refunds</td><td class="num">{{ minus(sum.cash_refunded) }}</td></tr>
        <tr><td>Cash expenses</td><td class="num">{{ minus(sum.cash_expenses) }}</td></tr>
        <tr><td>Cash supplier payments</td><td class="num">{{ minus(sum.cash_supplier_payments) }}</td></tr>
        <tr class="font-bold"><td>Expected cash</td><td class="num">{{ money(sum.expected_cash) }}</td></tr>
        <tr v-if="sum.shift.counted_cash"><td>Counted</td><td class="num">{{ money(sum.shift.counted_cash) }}</td></tr>
        <tr v-if="sum.shift.closing_notes"><td colspan="2" class="text-muted">{{ sum.shift.closing_notes }}</td></tr>
      </table>
    </Modal>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import Modal from '@/components/Modal.vue'
import Pager from '@/components/Pager.vue'
import { get } from '@/lib/api'
import { minus, money } from '@/lib/money'
import { usePaged } from '@/lib/usePaged'
import { loadLookups } from '@/stores/lookups'

loadLookups()
const sum = ref<any>(null)
const { rows, meta, go } = usePaged<any>('/admin/shifts')
const show = async (s: any) => (sum.value = await get(`/admin/shifts/${s.id}`))
</script>
