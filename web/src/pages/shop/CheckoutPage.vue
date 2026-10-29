<template>
  <div class="max-w-5xl 2xl:max-w-[max(80rem,70vw)] mx-auto px-3 sm:px-4 py-4">
    <h1 class="text-xl font-bold mb-3">Checkout</h1>
    <div v-if="!cart.lines.length" class="card p-10 text-center">
      <p class="text-muted">Your cart is empty.</p>
      <RouterLink to="/" class="btn-primary mt-3">Continue shopping</RouterLink>
    </div>
    <form v-else class="grid md:grid-cols-[1fr_20rem] gap-4" @submit.prevent="place">
      <section class="space-y-4">
        <div class="card p-4 space-y-4">
          <div>
            <div class="label">How would you like to receive it?</div>
            <div class="grid grid-cols-2 gap-2">
              <label v-for="o in receive" :key="o.v" class="border rounded-md p-3 cursor-pointer text-sm" :class="form.fulfillment === o.v ? 'border-brand-600 bg-brand-50' : 'border-line'">
                <input v-model="form.fulfillment" type="radio" :value="o.v" class="sr-only" />
                <b>{{ o.t }}</b><br /><span class="text-muted text-xs">{{ o.d }}</span>
              </label>
            </div>
          </div>

          <!-- Delivery location: decides whether pay on delivery is possible -->
          <div v-if="form.fulfillment === 'delivery'" class="space-y-2">
            <div class="label">Delivery location</div>
            <div v-if="pin && !editingPin" class="flex items-start gap-3 rounded-md border border-line p-3 text-sm">
              <Icon name="home" class="text-brand-600 shrink-0 mt-0.5" />
              <div class="flex-1">
                <div class="font-medium">{{ zoneInfo?.district ?? zoneInfo?.label ?? `${pin.lat.toFixed(4)}, ${pin.lng.toFixed(4)}` }}</div>
                <div v-if="zoneInfo?.label && zoneInfo?.district" class="text-xs text-muted">{{ zoneInfo.label }}</div>
              </div>
              <button type="button" class="text-brand-700 text-xs font-semibold" @click="editingPin = true">Change</button>
            </div>
            <div v-else class="rounded-md border border-line p-3">
              <LocationPicker v-model="pin" :maps-key="shop.settings.google_maps_key" />
              <button v-if="pin" type="button" class="btn-outline btn-sm w-full mt-2" @click="editingPin = false">Use this location</button>
            </div>
            <div><label class="label" for="da">Directions for the rider</label>
              <input id="da" v-model="form.delivery_address" class="input" required maxlength="255" placeholder="Area, street, building, landmark" /></div>
          </div>

          <div class="grid sm:grid-cols-2 gap-3">
            <div><label class="label" for="dn">Full name</label><input id="dn" v-model="form.delivery_name" class="input" required autocomplete="name" /></div>
            <div><label class="label" for="dp">Phone</label><input id="dp" v-model="form.delivery_phone" class="input" required inputmode="tel" autocomplete="tel" /></div>
          </div>
          <div><label class="label" for="nt">Notes (optional)</label><input id="nt" v-model="form.notes" class="input" maxlength="500" /></div>
        </div>

        <!-- Payment -->
        <div class="card p-4 space-y-2">
          <div class="label">Payment</div>
          <div v-if="optionsLoading" class="skeleton h-16"></div>
          <template v-else>
            <label v-for="o in paymentChoices" :key="o.v" class="flex gap-3 border rounded-md p-3 text-sm" :class="[o.allowed ? 'cursor-pointer' : 'opacity-60 cursor-not-allowed', form.payment_option === o.v ? 'border-brand-600 bg-brand-50' : 'border-line']">
              <input v-model="form.payment_option" type="radio" :value="o.v" :disabled="!o.allowed" class="mt-1 accent-brand-600" />
              <span><b>{{ o.t }}</b><br /><span class="text-xs" :class="o.allowed ? 'text-muted' : 'text-warn'">{{ o.allowed ? o.d : o.why }}</span></span>
            </label>
            <p v-if="form.fulfillment === 'delivery' && !pin" class="text-xs text-muted">Set your delivery location to see payment options.</p>
          </template>
        </div>
      </section>

      <aside class="card p-4 h-max space-y-2 text-sm md:sticky md:top-24">
        <h2 class="font-semibold uppercase text-muted text-xs">Order summary</h2>
        <div v-if="quoteError" class="text-bad">{{ quoteError }}</div>
        <template v-if="quote">
          <div v-for="l in quote.lines" :key="l.product_id" class="flex justify-between gap-2"><span class="truncate">{{ l.quantity }} × {{ l.name }}</span><span class="shrink-0">{{ money(l.amount) }}</span></div>
          <hr class="border-line" />
          <div class="flex justify-between"><span>Subtotal</span><span>{{ money(quote.total) }}</span></div>
          <div v-if="form.fulfillment === 'delivery'" class="flex justify-between"><span>Delivery</span><span>{{ money(quote.delivery_fee) }}</span></div>
          <div class="flex justify-between text-base font-bold"><span>Total</span><span>{{ money(grandTotal) }}</span></div>
          <p v-if="quote.tax_total !== '0.00'" class="text-xs text-muted">Includes tax {{ money(quote.tax_total) }}</p>
        </template>
        <div v-else-if="!quoteError" class="skeleton h-24"></div>
        <p v-if="error" class="text-bad" role="alert">{{ error }}</p>
        <button class="btn-primary w-full h-11 mt-2" :disabled="busy || !quote || !form.payment_option">
          {{ busy ? 'Placing order…' : form.payment_option === 'online' ? `Pay ${money(grandTotal)} now` : 'Place order' }}
        </button>
        <p v-if="form.payment_option === 'online'" class="text-xs text-muted text-center">You'll be taken to Flutterwave to pay by MTN / Airtel Mobile Money or card.</p>
      </aside>
    </form>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '@/components/Icon.vue'
import LocationPicker from '@/components/LocationPicker.vue'
import { errorText, post, uuid } from '@/lib/api'
import { fromCents, money, toCents } from '@/lib/money'
import { auth } from '@/stores/auth'
import { cart, clearCart } from '@/stores/cart'
import { loadHome, shop } from '@/stores/shop'

const router = useRouter()
loadHome().catch(() => {})
const receive = [
  { v: 'delivery', t: 'Delivery', d: 'Brought to your door' },
  { v: 'pickup', t: 'Pick up', d: 'Collect at the shop' },
]
const saved = auth.user?.location
const form = reactive({
  fulfillment: 'delivery', delivery_name: auth.user?.name ?? '', delivery_phone: auth.user?.phone ?? '',
  delivery_address: saved?.address ?? '', notes: '', payment_option: '' as '' | 'cod' | 'online' | 'pickup',
})
const pin = ref<{ lat: number; lng: number } | null>(saved ? { lat: saved.lat, lng: saved.lng } : null)
const editingPin = ref(!saved)
const zoneInfo = ref<any>(saved ? { district: saved.district, label: saved.label, zone: saved.zone } : null)
const allowed = ref<string[]>([])
const optionsLoading = ref(false)
const quote = ref<any>(null)
const quoteError = ref('')
const error = ref('')
const busy = ref(false)
const grandTotal = computed(() => (quote.value ? fromCents(toCents(quote.value.total) + (form.fulfillment === 'delivery' ? toCents(quote.value.delivery_fee) : 0)) : '0'))
const districts = computed(() => shop.settings.cod_districts || 'Kampala, Wakiso')

const paymentChoices = computed(() => {
  const online = { v: 'online', t: 'Pay now: mobile money or card', d: 'MTN / Airtel Mobile Money, Visa or Mastercard via Flutterwave', allowed: allowed.value.includes('online'), why: 'Online payment is not available at the moment' }
  if (form.fulfillment === 'pickup') {
    return [online, { v: 'pickup', t: 'Pay when you collect', d: 'Cash or mobile money at the shop', allowed: allowed.value.includes('pickup'), why: 'Not available' }]
  }
  return [online, {
    v: 'cod', t: 'Pay on delivery', d: 'Cash or mobile money when the rider arrives', allowed: allowed.value.includes('cod'),
    why: zoneInfo.value?.zone === 'unknown' ? "We couldn't confirm this location, so pay on delivery isn't offered" : `Only available in ${districts.value}. Your location is outside this area.`,
  }]
})

const items = () => cart.lines.map((l) => ({ product_id: l.product_id, quantity: l.quantity }))

// Server-priced preview: the shopper sees exactly what will be charged.
post('/shop/quote', { items: items() }).then((q) => (quote.value = q)).catch((e) => (quoteError.value = errorText(e)))

// Ask the server which payment options this delivery pin allows.
async function refreshOptions() {
  if (form.fulfillment === 'delivery' && !pin.value) {
    allowed.value = []
    return
  }
  optionsLoading.value = true
  try {
    const r = await post('/my/delivery-options', { fulfillment: form.fulfillment, lat: pin.value?.lat, lng: pin.value?.lng })
    allowed.value = r.options
    if (r.zone) zoneInfo.value = r.zone
  } catch (e) {
    error.value = errorText(e)
  } finally {
    optionsLoading.value = false
  }
  if (!allowed.value.includes(form.payment_option)) form.payment_option = (allowed.value.includes('cod') ? 'cod' : allowed.value[0] ?? '') as any
}
watch([() => form.fulfillment, pin], refreshOptions, { immediate: true })

// One idempotency key per checkout attempt, kept across retries so a double tap
// or a flaky connection can never create two orders.
const KEY = 'shop.checkout.uuid'
function orderKey() {
  let k = sessionStorage.getItem(KEY)
  if (!k) sessionStorage.setItem(KEY, (k = uuid()))
  return k
}

async function place() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    const body: any = { client_uuid: orderKey(), items: items(), ...form }
    if (form.fulfillment === 'delivery') Object.assign(body, { delivery_lat: pin.value?.lat, delivery_lng: pin.value?.lng })
    const r = await post('/my/orders', body)
    sessionStorage.removeItem(KEY)
    clearCart()
    if (r.checkout_url) {
      location.href = r.checkout_url // Flutterwave hosted payment page
      return
    }
    router.replace(`/account/orders/${r.order.number}?placed=1`)
  } catch (e: any) {
    if (e?.status === 422) sessionStorage.removeItem(KEY) // business-rule failure: a new attempt needs a new key
    error.value = errorText(e)
    if (e?.code === 'payment_option_not_allowed') refreshOptions()
  } finally {
    busy.value = false
  }
}
</script>
