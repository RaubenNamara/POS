<template>
  <Modal :open="true" title="Take payment" @close="!busy && $emit('close')">
    <div class="text-center mb-4">
      <div class="text-xs uppercase text-muted">Total to pay</div>
      <div class="text-4xl font-black num !text-center">{{ money(fromCents(cartCents.total)) }}</div>
    </div>

    <div class="space-y-2">
      <div v-for="(t, i) in tenders" :key="i" class="flex gap-2 items-end">
        <div class="w-40">
          <label class="label">Method</label>
          <select v-model.number="t.payment_method_id" class="input">
            <option v-for="m in pos.methods" :key="m.id" :value="m.id">{{ m.name }}</option>
          </select>
        </div>
        <div class="flex-1">
          <label class="label">Amount</label>
          <input :ref="(el) => i === 0 && (firstAmount = el as HTMLInputElement)" v-model="t.amount" class="input text-right text-lg font-semibold" inputmode="decimal" @keydown.enter.prevent="confirm" />
        </div>
        <div v-if="methodOf(t)?.requires_reference" class="w-32">
          <label class="label">Reference</label>
          <input v-model="t.reference" class="input" placeholder="Txn ID" />
        </div>
        <button v-if="tenders.length > 1" class="btn-ghost px-2 h-10" aria-label="Remove" @click="tenders.splice(i, 1)"><Icon name="x" :size="16" /></button>
      </div>
      <div class="flex flex-wrap gap-2">
        <button class="btn-outline btn-sm" @click="addTender">+ Split payment</button>
        <button v-for="q in quick" :key="q" class="btn-outline btn-sm" @click="setCash(q)">{{ money(fromCents(q), false) }}</button>
      </div>
    </div>

    <div class="mt-4 rounded-md bg-gray-50 p-3 text-sm space-y-1">
      <div class="flex justify-between"><span>Tendered</span><b class="num">{{ money(fromCents(tenderedCents)) }}</b></div>
      <div v-if="changeCents > 0" class="flex justify-between text-ok text-lg"><span>Change</span><b class="num">{{ money(fromCents(changeCents)) }}</b></div>
      <div v-if="dueCents > 0" class="flex justify-between text-warn"><span>Unpaid balance (credit)</span><b class="num">{{ money(fromCents(dueCents)) }}</b></div>
      <p v-if="dueCents > 0 && !pos.customer" class="text-bad text-xs">Select a customer to leave a balance on credit.</p>
    </div>

    <p v-if="error" class="mt-3 text-sm text-bad" role="alert">{{ error }}</p>
    <div v-if="offlineOffer" class="mt-3 rounded-md border border-warn bg-amber-50 p-3 text-sm">
      <b>No connection.</b> {{ offlineBlock ?? 'You can save this as a provisional cash sale; it syncs automatically and is checked against stock then.' }}
      <button v-if="!offlineBlock" class="btn-primary btn-sm mt-2 w-full" @click="saveOffline">Save offline sale & print provisional receipt</button>
    </div>

    <template #footer>
      <button class="btn-outline" :disabled="busy" @click="$emit('close')">Back</button>
      <button class="btn-primary min-w-40 h-12 text-base" :disabled="busy || !canSubmit" @click="confirm">{{ busy ? 'Confirming…' : 'Complete sale' }}</button>
    </template>
  </Modal>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { ApiError, errorText, post } from '@/lib/api'
import { fromCents, money, toCents } from '@/lib/money'
import { cartCents, clearCart, deductLocal, newSaleKey, offlineCheck, pos, queueOffline, refreshStock, salePayload } from './posStore'

const emit = defineEmits<{ close: []; done: [r: any] }>()
const cash = pos.methods.find((m) => m.type === 'cash') ?? pos.methods[0]
const tenders = reactive([{ payment_method_id: cash?.id, amount: fromCents(cartCents.value.total), reference: '' }])
const busy = ref(false)
const error = ref('')
const offlineOffer = ref(false)
const firstAmount = ref<HTMLInputElement | null>(null)
// One key for this checkout: retries after a timeout reuse it, so the server never sells twice.
const saleKey = newSaleKey()

const methodOf = (t: { payment_method_id: number }) => pos.methods.find((m) => m.id === t.payment_method_id)
const tenderedCents = computed(() => tenders.reduce((s, t) => s + Math.max(0, toCents(t.amount || '0')), 0))
const cashCents = computed(() => tenders.filter((t) => methodOf(t)?.type === 'cash').reduce((s, t) => s + toCents(t.amount || '0'), 0))
const nonCashCents = computed(() => tenderedCents.value - cashCents.value)
const changeCents = computed(() => Math.max(0, Math.min(cashCents.value, tenderedCents.value - cartCents.value.total)))
const dueCents = computed(() => Math.max(0, cartCents.value.total - tenderedCents.value))
const canSubmit = computed(() => nonCashCents.value <= cartCents.value.total && (dueCents.value === 0 || !!pos.customer))
const offlineBlock = computed(() => offlineCheck(tenders.every((t) => methodOf(t)?.type === 'cash')) ?? (dueCents.value > 0 ? 'Credit sales need a connection' : null))

// Round-up cash suggestions (next 1k, 5k, 10k, 50k).
const quick = computed(() => {
  const t = cartCents.value.total
  const set = new Set<number>()
  for (const step of [100000, 500000, 1000000, 5000000]) set.add(Math.ceil(t / step) * step)
  return [...set].filter((v) => v >= t).slice(0, 4)
})

function addTender() {
  const other = pos.methods.find((m) => m.type !== 'cash') ?? cash
  tenders.push({ payment_method_id: other.id, amount: fromCents(dueCents.value), reference: '' })
}
function setCash(c: number) {
  const t = tenders.find((x) => methodOf(x)?.type === 'cash') ?? tenders[0]
  t.amount = fromCents(c - nonCashCents.value)
}

function payments() {
  return tenders.filter((t) => toCents(t.amount || '0') > 0).map((t) => ({ payment_method_id: t.payment_method_id, amount: fromCents(toCents(t.amount)), reference: t.reference || undefined }))
}

async function confirm() {
  if (busy.value || !canSubmit.value) return
  busy.value = true
  error.value = ''
  offlineOffer.value = false
  const payload = salePayload(saleKey, payments())
  try {
    const r = await post('/admin/pos/checkout', payload)
    // Only now, the server confirmed stock and recorded payment, is the sale real.
    deductLocal(payload.items)
    refreshStock(payload.items.map((i) => i.product_id))
    clearCart()
    emit('done', r.sale)
  } catch (e) {
    if (e instanceof ApiError && e.isNetwork) {
      pos.online = false
      offlineOffer.value = true
      error.value = 'Could not reach the server. Retry, or save offline.'
    } else {
      error.value = errorText(e)
      if (e instanceof ApiError && e.code === 'insufficient_stock') refreshStock(payload.items.map((i) => i.product_id))
    }
  } finally {
    busy.value = false
  }
}

async function saveOffline() {
  const payload = salePayload(saleKey, payments())
  const receipt = {
    provisional: true,
    number: `OFFLINE-${saleKey.slice(0, 8).toUpperCase()}`,
    date: new Date().toLocaleString(),
    cashier: undefined,
    items: pos.cart.map((l) => ({ name: l.name, quantity: l.qty, unit_price: l.price, discount: l.discount || '0', amount: fromCents(toCents(l.price) * l.qty - toCents(l.discount || '0')) })),
    subtotal: fromCents(cartCents.value.sub),
    discount_total: fromCents(cartCents.value.disc),
    tax_total: fromCents(cartCents.value.tax),
    total: fromCents(cartCents.value.total),
    amount_paid: fromCents(Math.min(tenderedCents.value, cartCents.value.total)),
    change_given: fromCents(changeCents.value),
    balance_due: '0.00',
    payments: payments().map((p) => ({ method: methodOf(p)?.name, kind: 'payment', amount: p.amount })),
    shop: { name: pos.settings.shop_name, address: pos.settings.shop_address, phone: pos.settings.shop_phone, footer: pos.settings.receipt_footer, return_policy: pos.settings.return_policy, tax_label: pos.settings.tax_label },
  }
  await queueOffline(payload, receipt)
  clearCart()
  emit('done', receipt)
}

onMounted(() => firstAmount.value?.select())
</script>
