<template>
  <div class="space-y-3">
    <div class="card p-3 flex flex-wrap gap-2 items-end">
      <div><label class="label">Type</label>
        <select v-model="filters.type" class="input"><option value="">All</option><option v-for="t in types" :key="t" :value="t">{{ t.replace('_', ' ') }}</option></select></div>
      <div><label class="label">Location</label>
        <select v-model="filters.location_id" class="input"><option value="">All</option><option v-for="l in lookups.locations" :key="l.id" :value="l.id">{{ l.name }}</option></select></div>
      <div><label class="label">From</label><input v-model="filters.from" type="date" class="input" /></div>
      <div><label class="label">To</label><input v-model="filters.to" type="date" class="input" /></div>
      <div class="flex-1"></div>
      <RouterLink v-if="can('inventory.adjust')" to="/admin/inventory/adjust" class="btn-primary">Adjust / count stock</RouterLink>
    </div>
    <div class="card overflow-x-auto">
      <table class="tbl" :class="{ 'opacity-60': loading }">
        <thead><tr><th>When</th><th>Product</th><th>Type</th><th class="num">Qty</th><th class="num">Balance</th><th>Loc.</th><th>Reason / reference</th><th>By</th></tr></thead>
        <tbody>
          <tr v-for="m in rows" :key="m.id">
            <td class="text-xs whitespace-nowrap">{{ new Date(m.created_at).toLocaleString() }}</td>
            <td><RouterLink :to="`/admin/products/${m.product_id}`" class="hover:text-brand-700">{{ m.product?.name }}</RouterLink><div class="text-xs text-muted">{{ m.product?.sku }}</div></td>
            <td><span class="badge" :class="m.quantity < 0 ? 'bg-red-50 text-bad' : 'bg-green-50 text-ok'">{{ m.type.replace('_', ' ') }}</span></td>
            <td class="num font-semibold">{{ m.quantity > 0 ? '+' : '' }}{{ m.quantity }}</td>
            <td class="num">{{ m.balance_after }}</td>
            <td>{{ m.location?.code }}</td>
            <td class="text-xs">{{ m.reason }}<RouterLink v-if="m.reference_type === 'sale'" :to="`/admin/sales/${m.reference_id}`" class="text-brand-700 ml-1">view sale</RouterLink></td>
            <td class="text-xs">{{ m.user?.name }}</td>
          </tr>
          <tr v-if="!rows.length && !loading"><td colspan="8" class="text-center text-muted py-8">No movements.</td></tr>
        </tbody>
      </table>
    </div>
    <Pager :page="meta.current_page" :last="meta.last_page" @go="go" />
  </div>
</template>

<script setup lang="ts">
import Pager from '@/components/Pager.vue'
import { usePaged } from '@/lib/usePaged'
import { can } from '@/stores/auth'
import { loadLookups, lookups } from '@/stores/lookups'

loadLookups()
const types = ['opening', 'purchase', 'sale', 'sale_return', 'order_cancel', 'adjustment', 'damaged', 'missing', 'count', 'transfer_in', 'transfer_out']
const { filters, rows, meta, loading, go } = usePaged<any>('/admin/inventory/movements', { type: '', location_id: '', from: '', to: '' })
</script>
