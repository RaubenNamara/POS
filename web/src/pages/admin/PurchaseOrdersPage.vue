<template>
  <div class="space-y-3">
    <div class="card p-3 flex flex-wrap gap-2 items-end">
      <div><label class="label">Status</label>
        <select v-model="filters.status" class="input"><option value="">All</option><option v-for="s in ['draft', 'ordered', 'partial', 'received', 'cancelled']" :key="s" :value="s">{{ s }}</option></select></div>
      <div class="flex-1"></div>
      <RouterLink v-if="can('purchases.manage')" to="/admin/purchase-orders/new" class="btn-primary"><Icon name="plus" :size="16" /> New purchase order</RouterLink>
    </div>
    <div class="card overflow-x-auto">
      <table class="tbl">
        <thead><tr><th>PO</th><th>Supplier</th><th>Date</th><th>Expected</th><th class="num">Lines</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <tr v-for="o in rows" :key="o.id">
            <td class="font-medium">{{ o.number }}</td><td>{{ o.supplier?.name }}</td><td>{{ o.order_date?.slice(0, 10) }}</td><td>{{ o.expected_date?.slice(0, 10) }}</td>
            <td class="num">{{ o.items_count }}</td><td class="num">{{ money(o.total, false) }}</td>
            <td><span class="badge" :class="o.status === 'received' ? 'bg-green-50 text-ok' : o.status === 'cancelled' ? 'bg-gray-100' : 'bg-brand-50 text-brand-700'">{{ o.status }}</span></td>
            <td class="whitespace-nowrap">
              <RouterLink v-if="['ordered', 'partial', 'draft'].includes(o.status)" :to="`/admin/receive?po=${o.id}`" class="btn-outline btn-sm">Receive</RouterLink>
              <button v-if="can('purchases.manage') && ['ordered', 'draft'].includes(o.status)" class="btn-ghost btn-sm text-bad" @click="cancel(o)">Cancel</button>
            </td>
          </tr>
          <tr v-if="!rows.length"><td colspan="8" class="text-center text-muted py-8">No purchase orders.</td></tr>
        </tbody>
      </table>
    </div>
    <Pager :page="meta.current_page" :last="meta.last_page" @go="go" />
  </div>
</template>

<script setup lang="ts">
import Icon from '@/components/Icon.vue'
import Pager from '@/components/Pager.vue'
import { errorText, post } from '@/lib/api'
import { money } from '@/lib/money'
import { toast } from '@/lib/toast'
import { usePaged } from '@/lib/usePaged'
import { can } from '@/stores/auth'
import { loadLookups } from '@/stores/lookups'

loadLookups()
const { filters, rows, meta, go, load } = usePaged<any>('/admin/purchase-orders', { status: '' })
async function cancel(o: any) {
  if (!confirm(`Cancel ${o.number}?`)) return
  try {
    await post(`/admin/purchase-orders/${o.id}/cancel`)
    load()
  } catch (e) {
    toast(errorText(e), 'error')
  }
}
</script>
