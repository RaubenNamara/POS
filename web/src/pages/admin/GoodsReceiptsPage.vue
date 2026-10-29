<template>
  <div class="space-y-3">
    <div class="flex justify-end"><RouterLink to="/admin/receive" class="btn-primary"><Icon name="plus" :size="16" /> Receive goods</RouterLink></div>
    <div class="card overflow-x-auto">
      <table class="tbl">
        <thead><tr><th>GRN</th><th>Date</th><th>Supplier</th><th>Invoice no.</th><th>Received by</th><th class="num">Total</th><th class="num">Paid</th></tr></thead>
        <tbody>
          <tr v-for="g in rows" :key="g.id" class="hover:bg-gray-50 cursor-pointer" @click="show(g.id)">
            <td class="font-medium">{{ g.number }}</td><td>{{ g.received_date?.slice(0, 10) }}</td><td>{{ g.supplier?.name }}</td><td>{{ g.supplier_invoice_no }}</td>
            <td>{{ g.user?.name }}</td><td class="num">{{ money(g.total, false) }}</td><td class="num">{{ money(g.payments_sum_amount ?? '0', false) }}</td>
          </tr>
          <tr v-if="!rows.length"><td colspan="7" class="text-center text-muted py-8">Nothing received yet.</td></tr>
        </tbody>
      </table>
    </div>
    <Pager :page="meta.current_page" :last="meta.last_page" @go="go" />
    <Modal :open="!!detail" :title="detail?.number ?? ''" wide @close="detail = null">
      <template v-if="detail">
        <p class="text-sm text-muted mb-2">{{ detail.supplier?.name }} · {{ detail.received_date?.slice(0, 10) }} · {{ detail.purchase_order ? `PO ${detail.purchase_order.number}` : 'no PO' }}</p>
        <table class="tbl"><thead><tr><th>Product</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Line</th></tr></thead>
          <tbody><tr v-for="i in detail.items" :key="i.id"><td>{{ i.product?.name }}</td><td class="num">{{ i.quantity }}</td><td class="num">{{ money(i.unit_cost, false) }}</td><td class="num">{{ money(i.line_total, false) }}</td></tr></tbody></table>
        <p class="text-right font-bold mt-2">{{ money(detail.total) }}</p>
      </template>
    </Modal>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import Pager from '@/components/Pager.vue'
import { get } from '@/lib/api'
import { money } from '@/lib/money'
import { usePaged } from '@/lib/usePaged'
import { loadLookups } from '@/stores/lookups'

loadLookups()
const detail = ref<any>(null)
const { rows, meta, go } = usePaged<any>('/admin/goods-receipts')
const show = async (id: number) => (detail.value = await get(`/admin/goods-receipts/${id}`))
</script>
