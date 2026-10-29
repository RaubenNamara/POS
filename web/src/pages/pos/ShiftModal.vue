<template>
  <Modal :open="true" :title="pos.shift ? 'Your shift' : 'Open a shift'" @close="$emit('close')">
    <form v-if="!pos.shift" class="space-y-3" @submit.prevent="open">
      <p class="text-sm text-muted">Count the cash in the drawer before you start selling.</p>
      <div><label class="label" for="fl">Opening cash (float)</label><input id="fl" v-model="float" class="input text-lg" inputmode="decimal" required /></div>
      <p v-if="error" class="text-sm text-bad">{{ error }}</p>
      <button class="btn-primary w-full" :disabled="busy">Open shift</button>
    </form>

    <div v-else>
      <div v-if="!summary" class="skeleton h-40"></div>
      <div v-else class="space-y-3 text-sm">
        <p class="text-muted">Opened {{ new Date(pos.shift.opened_at).toLocaleString() }}</p>
        <table class="tbl">
          <tr><td>Sales</td><td class="num">{{ summary.sales_count }} · {{ money(summary.sales_total) }}</td></tr>
          <tr v-for="m in summary.by_method" :key="m.id"><td>{{ m.name }}</td><td class="num">{{ money(m.net) }}</td></tr>
          <tr><td>Credit given</td><td class="num">{{ money(summary.credit_given) }}</td></tr>
          <tr><td>Opening float</td><td class="num">{{ money(summary.opening_float) }}</td></tr>
          <tr><td>Cash refunds / expenses / supplier payments</td><td class="num">{{ minus(summary.cash_refunded, false) }} / {{ minus(summary.cash_expenses, false) }} / {{ minus(summary.cash_supplier_payments, false) }}</td></tr>
          <tr class="font-bold"><td>Expected cash in drawer</td><td class="num">{{ money(summary.expected_cash) }}</td></tr>
        </table>
        <form class="space-y-2 pt-2 border-t border-line" @submit.prevent="close">
          <div><label class="label" for="cc">Counted cash</label><input id="cc" v-model="counted" class="input text-lg" inputmode="decimal" required /></div>
          <p v-if="counted !== ''" class="font-semibold" :class="diff === 0 ? 'text-ok' : 'text-bad'">
            {{ diff === 0 ? 'Drawer balances' : diff > 0 ? `Over by ${money(fromCents(diff))}` : `Short by ${money(fromCents(-diff))}` }}
          </p>
          <div><label class="label" for="cn">Notes</label><input id="cn" v-model="notes" class="input" /></div>
          <p v-if="pendingCount" class="text-warn">{{ pendingCount }} offline sale(s) not yet synced. They will be added to this shift when they sync.</p>
          <p v-if="error" class="text-bad">{{ error }}</p>
          <button class="btn-danger w-full" :disabled="busy">Close shift</button>
        </form>
      </div>
    </div>
  </Modal>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import Modal from '@/components/Modal.vue'
import { errorText, get, post } from '@/lib/api'
import { fromCents, minus, money, toCents } from '@/lib/money'
import { toast } from '@/lib/toast'
import { pendingCount, pos, refreshCatalog } from './posStore'

const emit = defineEmits<{ close: [] }>()
const float = ref('0')
const counted = ref('')
const notes = ref('')
const busy = ref(false)
const error = ref('')
const summary = ref<any>(null)
const diff = computed(() => (summary.value ? toCents(counted.value || '0') - toCents(summary.value.expected_cash) : 0))

if (pos.shift) get(`/admin/shifts/${pos.shift.id}`).then((s) => (summary.value = s)).catch((e) => (error.value = errorText(e)))

async function open() {
  busy.value = true
  try {
    pos.shift = await post('/admin/shifts', { opening_float: float.value, location_id: pos.location?.id })
    toast('Shift opened')
    emit('close')
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}

async function close() {
  if (!confirm('Close this shift? You will need to open a new one to sell.')) return
  busy.value = true
  try {
    const s = await post(`/admin/shifts/${pos.shift.id}/close`, { counted_cash: counted.value, notes: notes.value })
    toast(`Shift closed. Difference: ${money(s.shift.cash_difference)}`, Number(s.shift.cash_difference) === 0 ? 'ok' : 'info', 6000)
    pos.shift = null
    refreshCatalog().catch(() => {})
    emit('close')
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}
</script>
