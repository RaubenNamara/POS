<template>
  <div class="max-w-3xl mx-auto px-3 sm:px-4 py-4">
    <div v-if="placed" class="card p-4 mb-3 border-ok bg-green-50 text-ok flex gap-3 items-start">
      <Icon name="check" class="shrink-0" />
      <div><b>Order received.</b> We'll call {{ o?.delivery_phone }} to confirm.{{ o?.payment_option === 'cod' ? ' You pay when you receive it.' : o?.payment_option === 'pickup' ? ' You pay when you collect it.' : '' }}</div>
    </div>
    <div v-if="!o" class="card p-6"><div class="skeleton h-32"></div></div>
    <template v-else>
      <div class="card p-4">
        <div class="flex flex-wrap justify-between gap-2">
          <div>
            <h1 class="text-lg font-bold">Order {{ o.number }}</h1>
            <p class="text-xs text-muted">{{ new Date(o.created_at).toLocaleString() }}</p>
          </div>
          <span class="badge h-max" :class="o.payment_status === 'paid' ? 'bg-green-50 text-ok' : 'bg-gray-100'">{{ o.payment_status === 'paid' ? 'Paid' : 'Not yet paid' }}</span>
        </div>
        <!-- Status tracker -->
        <ol v-if="o.status !== 'cancelled'" class="grid grid-cols-6 gap-1 mt-5 text-[10px] sm:text-xs text-center">
          <li v-for="(s, i) in o.steps" :key="s">
            <div class="h-1.5 rounded-full" :class="i <= stepIndex ? 'bg-brand-600' : 'bg-gray-200'"></div>
            <div class="mt-1 capitalize" :class="i <= stepIndex ? 'text-ink font-semibold' : 'text-muted'">{{ s.replace(/_/g, ' ') }}</div>
          </li>
        </ol>
        <p v-else class="mt-4 text-bad font-semibold">This order was cancelled.</p>
      </div>
      <div v-if="o.can_pay_online" class="card p-4 mt-3 border-brand-500 bg-brand-50 text-sm">
        <b>Waiting for your payment.</b> Your items are reserved until {{ new Date(o.payment_expires_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}.
        <button class="btn-primary w-full mt-2" :disabled="paying" @click="payNow">{{ paying ? 'Opening payment…' : `Pay ${money(o.balance_due)} now` }}</button>
        <p v-if="payError" class="text-bad mt-1">{{ payError }}</p>
      </div>
      <div class="card p-4 mt-3 text-sm space-y-1.5">
        <div v-for="(it, i) in o.items" :key="i" class="flex justify-between gap-2"><span>{{ it.quantity }} × {{ it.name }}</span><span>{{ money(it.amount) }}</span></div>
        <hr class="border-line" />
        <div v-if="o.delivery_fee !== '0.00'" class="flex justify-between"><span>Delivery</span><span>{{ money(o.delivery_fee) }}</span></div>
        <div class="flex justify-between font-bold text-base"><span>Total</span><span>{{ money(o.total) }}</span></div>
        <div v-if="o.amount_paid !== '0.00'" class="flex justify-between text-ok"><span>Paid</span><span>{{ money(o.amount_paid) }}</span></div>
        <div v-if="o.balance_due !== '0.00' && o.status !== 'cancelled'" class="flex justify-between"><span>To pay</span><span>{{ money(o.balance_due) }}</span></div>
      </div>
      <div class="card p-4 mt-3 text-sm">
        <p class="mb-2"><b>Payment:</b> {{ ({ cod: 'Pay on delivery', online: 'Online (mobile money / card)', pickup: 'Pay at pickup' } as Record<string, string>)[o.payment_option] ?? '-' }}</p>
        <b>{{ o.delivery_address === 'Pickup at shop' ? 'Pick up at the shop' : 'Deliver to' }}</b>
        <p class="text-muted">{{ o.delivery_name }} · {{ o.delivery_phone }}<br />{{ o.delivery_address }}</p>
      </div>
      <div class="flex flex-wrap gap-2 mt-3">
        <RouterLink to="/account/orders" class="btn-outline">All orders</RouterLink>
        <button v-if="o.can_cancel" class="btn-outline text-bad border-red-200" :disabled="cancelling" @click="cancel">{{ cancelling ? 'Cancelling…' : 'Cancel order' }}</button>
        <RouterLink to="/help/cancel" class="btn-ghost text-sm">Help with this order</RouterLink>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import Icon from '@/components/Icon.vue'
import { errorText, get, post } from '@/lib/api'
import { money } from '@/lib/money'
import { toast } from '@/lib/toast'

const route = useRoute()
const o = ref<any>(null)
const placed = computed(() => route.query.placed === '1')
const stepIndex = computed(() => (o.value ? (o.value.status === 'completed' ? o.value.steps.length - 1 : o.value.steps.indexOf(o.value.fulfillment_status)) : -1))

const paying = ref(false)
const payError = ref('')
async function payNow() {
  paying.value = true
  payError.value = ''
  try {
    location.href = (await post(`/my/orders/${o.value.number}/pay`)).checkout_url
  } catch (e) {
    payError.value = errorText(e)
    paying.value = false
  }
}

const cancelling = ref(false)
async function cancel() {
  if (!confirm('Cancel this order? The items go back on sale.')) return
  cancelling.value = true
  try {
    o.value = await post(`/my/orders/${o.value.number}/cancel`)
    toast('Order cancelled')
  } catch (e) {
    toast(errorText(e), 'error')
  } finally {
    cancelling.value = false
  }
}

const load = () => get(`/my/orders/${route.params.number}`).then((r) => (o.value = r))
load()
// Live-ish tracking: refresh every 60 s while the page is open and the tab is visible.
const t = setInterval(() => document.visibilityState === 'visible' && o.value?.status === 'pending' && load(), 60000)
onUnmounted(() => clearInterval(t))
</script>
