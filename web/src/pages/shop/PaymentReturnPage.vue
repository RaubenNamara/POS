<template>
  <div class="max-w-md mx-auto px-4 py-10">
    <div class="card p-6 text-center space-y-3">
      <template v-if="state === 'checking'">
        <div class="skeleton h-10 w-10 rounded-full mx-auto"></div>
        <p class="font-semibold">Confirming your payment with Flutterwave…</p>
        <p class="text-sm text-muted">Mobile money can take a minute. Please keep this page open.</p>
      </template>
      <template v-else-if="state === 'successful'">
        <div class="h-12 w-12 rounded-full bg-green-50 text-ok grid place-items-center mx-auto"><Icon name="check" :size="28" /></div>
        <p class="text-lg font-bold">Payment received</p>
        <p class="text-sm text-muted">Order {{ order?.number }} is paid and being prepared.</p>
      </template>
      <template v-else>
        <div class="h-12 w-12 rounded-full bg-red-50 text-bad grid place-items-center mx-auto"><Icon name="alert" :size="26" /></div>
        <p class="text-lg font-bold">{{ heading }}</p>
        <p class="text-sm text-muted">{{ detail }}</p>
      </template>
      <div class="flex gap-2 justify-center pt-2">
        <button v-if="order?.can_pay_online && state !== 'checking'" class="btn-primary" :disabled="busy" @click="retry">Try paying again</button>
        <RouterLink v-if="order" :to="`/account/orders/${order.number}`" class="btn-outline">View order</RouterLink>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import Icon from '@/components/Icon.vue'
import { errorText, post } from '@/lib/api'

const route = useRoute()
const state = ref<'checking' | 'successful' | 'failed' | 'pending' | 'mismatch' | 'late' | 'error'>('checking')
const order = ref<any>(null)
const message = ref('')
const busy = ref(false)
const txRef = String(route.query.tx_ref ?? '')
const txId = route.query.transaction_id ? String(route.query.transaction_id) : undefined

const heading = computed(() => ({ failed: 'Payment not completed', pending: 'Payment still processing', mismatch: 'Payment needs checking', late: 'Payment arrived after the order expired', error: 'Could not confirm payment' } as Record<string, string>)[state.value] ?? '')
const detail = computed(() => ({
  failed: 'No money was taken for this order. You can try again.',
  pending: "We haven't received confirmation yet. If money left your account, the order updates automatically within a few minutes.",
  mismatch: 'The amount received differs from the order. Our staff will contact you.',
  late: 'We received your money after the order was released. Our staff will contact you about a refund or re-order.',
  error: message.value,
} as Record<string, string>)[state.value] ?? '')

let tries = 0
let timer: number | undefined
async function check() {
  try {
    // The redirect's own "status" parameter is ignored: only the server's check with Flutterwave counts.
    const r = await post('/my/payments/confirm', { tx_ref: txRef, transaction_id: txId })
    order.value = r.order
    if (r.status === 'pending' && ++tries < 10) {
      timer = window.setTimeout(check, 4000)
      return
    }
    state.value = r.status
  } catch (e) {
    message.value = errorText(e)
    state.value = 'error'
  }
}
if (txRef) check()
else {
  message.value = 'Missing payment reference.'
  state.value = 'error'
}
onUnmounted(() => clearTimeout(timer))

async function retry() {
  busy.value = true
  try {
    const r = await post(`/my/orders/${order.value.number}/pay`)
    location.href = r.checkout_url
  } catch (e) {
    message.value = errorText(e)
    state.value = 'error'
  } finally {
    busy.value = false
  }
}
</script>
