<template>
  <div class="space-y-3">
    <div class="card p-3 flex flex-wrap gap-2 items-end">
      <div class="flex-1 min-w-40"><label class="label">Receipt no. / customer</label><input v-model="filters.q" class="input" /></div>
      <div v-if="online"><label class="label">Order status</label>
        <select v-model="filters.status" class="input"><option value="">All</option><option value="pending">Open</option><option value="completed">Delivered</option><option value="cancelled">Cancelled</option></select></div>
      <div v-else><label class="label">Channel</label>
        <select v-model="filters.channel" class="input"><option value="">All</option><option value="pos">In store</option><option value="online">Online</option></select></div>
      <div><label class="label">Payment</label>
        <select v-model="filters.payment_status" class="input"><option value="">All</option><option value="paid">Paid</option><option value="partial">Part paid</option><option value="unpaid">Unpaid</option><option value="refunded">Refunded</option></select></div>
      <div><label class="label">From</label><input v-model="filters.from" type="date" class="input" /></div>
      <div><label class="label">To</label><input v-model="filters.to" type="date" class="input" /></div>
    </div>
    <div class="card overflow-x-auto">
      <table class="tbl" :class="{ 'opacity-60': loading }">
        <thead><tr><th>No.</th><th>Date</th><th>Customer</th><th v-if="!online">Cashier</th><th>Status</th><th>Payment</th><th class="num">Total</th><th class="num">Balance</th></tr></thead>
        <tbody>
          <tr v-for="s in rows" :key="s.id" class="hover:bg-gray-50 cursor-pointer" @click="router.push(`/admin/sales/${s.id}`)">
            <td class="font-medium">{{ s.number }} <span v-if="s.offline" class="badge bg-gray-100" title="Rung up offline">offline</span></td>
            <td class="text-xs whitespace-nowrap">{{ new Date(s.created_at).toLocaleString() }}</td>
            <td>{{ s.customer?.name ?? '-' }}</td>
            <td v-if="!online" class="text-sm">{{ s.cashier?.name ?? (s.channel === 'online' ? 'Online' : '') }}</td>
            <td><span class="badge" :class="statusClass(s)">{{ s.channel === 'online' && s.status === 'pending' ? s.fulfillment_status?.replace(/_/g, ' ') : s.status }}</span></td>
            <td><span class="badge" :class="s.payment_status === 'paid' ? 'bg-green-50 text-ok' : s.payment_status === 'refunded' ? 'bg-gray-100' : 'bg-amber-50 text-warn'">{{ s.payment_status }}</span></td>
            <td class="num">{{ money(s.total, false) }}</td>
            <td class="num" :class="Number(s.balance_due) > 0 ? 'text-warn font-semibold' : 'text-muted'">{{ money(s.balance_due, false) }}</td>
          </tr>
          <tr v-if="!rows.length && !loading"><td colspan="8" class="text-center text-muted py-8">No sales found.</td></tr>
        </tbody>
      </table>
    </div>
    <div class="flex justify-between items-center text-sm text-muted"><span>{{ meta.total }} records</span><Pager :page="meta.current_page" :last="meta.last_page" @go="go" /></div>
  </div>
</template>

<script setup lang="ts">
import { watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Pager from '@/components/Pager.vue'
import { money } from '@/lib/money'
import { usePaged } from '@/lib/usePaged'
import { loadLookups } from '@/stores/lookups'
import { pulse } from '@/stores/pulse'

const props = defineProps<{ online?: boolean }>()
const route = useRoute()
const router = useRouter()
loadLookups()
const { filters, rows, meta, loading, go, load } = usePaged<any>('/admin/sales', {
  q: '', channel: props.online ? 'online' : '', status: String(route.query.status ?? ''), payment_status: '', from: '', to: '',
})
watch(() => pulse.version, load)
const statusClass = (s: any) => (s.status === 'completed' ? 'bg-green-50 text-ok' : s.status === 'cancelled' ? 'bg-gray-100' : 'bg-brand-50 text-brand-700')
</script>
