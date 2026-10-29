<template>
  <div :class="format === 'a4' ? 'a4' : 'thermal'" class="receipt text-ink bg-white">
    <div class="text-center">
      <img v-if="r.shop?.logo" :src="img(r.shop.logo)" alt="" class="mx-auto mb-1 max-h-12" />
      <div class="font-bold" :class="format === 'a4' ? 'text-2xl' : 'text-base'">{{ r.shop?.name }}</div>
      <div v-if="r.shop?.address">{{ r.shop.address }}</div>
      <div v-if="r.shop?.phone">Tel: {{ r.shop.phone }}</div>
      <div v-if="format === 'a4' && r.shop?.email">{{ r.shop.email }}</div>
    </div>
    <div v-if="r.provisional" class="my-2 border-2 border-black text-center font-bold p-1">PROVISIONAL: NOT YET CONFIRMED<br /><span class="font-normal">Recorded offline; becomes final when synced.</span></div>
    <div v-else-if="!r.is_final_paid && r.status === 'completed'" class="my-2 border border-black text-center font-bold p-1">{{ r.balance_due !== '0.00' ? 'BALANCE OUTSTANDING' : 'NOT PAID' }}</div>
    <div class="my-2 border-y border-dashed border-gray-400 py-1 flex flex-wrap justify-between gap-x-4">
      <span>{{ format === 'a4' ? 'Invoice / Receipt' : 'Receipt' }} <b>{{ r.number }}</b></span>
      <span>{{ r.date }}</span>
      <span v-if="r.cashier">Cashier: {{ r.cashier }}</span>
      <span v-if="r.customer">Customer: {{ r.customer.name }}{{ r.customer.phone ? ` (${r.customer.phone})` : '' }}</span>
    </div>
    <table class="w-full">
      <thead><tr class="border-b border-gray-400"><th class="text-left">Item</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead>
      <tbody>
        <tr v-for="(it, i) in r.items" :key="i" class="align-top">
          <td>{{ it.name }}<div v-if="it.discount && it.discount !== '0' && it.discount !== '0.00'" class="text-[0.85em]">  discount -{{ m(it.discount) }}</div></td>
          <td class="text-right">{{ it.quantity }}</td>
          <td class="text-right">{{ m(it.unit_price) }}</td>
          <td class="text-right">{{ m(it.amount) }}</td>
        </tr>
      </tbody>
    </table>
    <div class="border-t border-dashed border-gray-400 mt-1 pt-1 space-y-0.5">
      <Row label="Subtotal" :v="r.subtotal" />
      <Row v-if="nz(r.discount_total)" label="Discounts" :v="`-${r.discount_total}`" />
      <Row v-if="nz(r.delivery_fee)" label="Delivery" :v="r.delivery_fee" />
      <Row v-if="nz(r.tax_total)" :label="`${r.shop?.tax_label ?? 'Tax'}${r.shop?.tax_inclusive ? ' (included)' : ''}`" :v="r.tax_total" />
      <Row label="TOTAL" :v="r.total" bold />
      <Row v-for="(p, i) in r.payments" :key="i" :label="`${p.kind === 'refund' ? 'Refund' : 'Paid'}: ${p.method}${p.reference ? ` (${p.reference})` : ''}`" :v="p.kind === 'refund' ? `-${p.amount}` : p.amount" />
      <Row v-if="nz(r.change_given)" label="Change" :v="r.change_given" />
      <Row v-if="nz(r.refunded_total)" label="Returned" :v="`-${r.refunded_total}`" />
      <Row v-if="nz(r.balance_due)" label="BALANCE DUE" :v="r.balance_due" bold />
    </div>
    <div class="mt-3 text-center text-[0.9em]">
      <p v-if="r.shop?.return_policy">{{ r.shop.return_policy }}</p>
      <p class="font-semibold mt-1">{{ r.shop?.footer }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { defineComponent, h } from 'vue'
import { img } from '@/lib/api'
import { money } from '@/lib/money'

defineProps<{ r: any; format?: 'thermal' | 'a4' }>()
const m = (v: string) => money(v, false)
const nz = (v?: string) => !!v && Number(v) !== 0

const Row = defineComponent({
  props: { label: String, v: String, bold: Boolean },
  setup: (p) => () => h('div', { class: ['flex justify-between gap-2', p.bold && 'font-bold text-[1.1em]'] }, [h('span', p.label), h('span', money(p.v ?? '0'))]),
})
</script>

<style scoped>
.thermal { width: 72mm; font: 12px/1.35 ui-monospace, Menlo, Consolas, monospace; padding: 2mm; margin: 0 auto; }
.a4 { max-width: 190mm; font: 13px/1.45 system-ui, sans-serif; padding: 10mm; margin: 0 auto; }
.a4 table td, .a4 table th { padding: 4px 6px; }
</style>
