<template>
  <div class="px-4 py-8 sm:py-12">
    <div class="max-w-md mx-auto bg-white rounded-xl px-5 sm:px-8 py-8">
      <div class="text-center">
        <div class="mx-auto h-16 w-16 rounded-full bg-brand-600 text-white grid place-items-center"><Icon name="pin" :size="30" /></div>
        <h1 class="text-xl font-bold mt-4">{{ isFirst ? 'Where should we deliver?' : 'Delivery location' }}</h1>
        <p class="text-sm text-muted mt-1">We use it to show your delivery and payment options.</p>
      </div>

      <form class="mt-6 space-y-5" novalidate @submit.prevent="save">
        <LocationPicker v-model="pin" :maps-key="shop.settings.google_maps_key" :label="savedLabel" />

        <!-- The rest only matters once we know where. -->
        <template v-if="pin">
          <div class="field">
            <input id="addr" v-model="address" class="input h-12" maxlength="255" placeholder=" " />
            <label for="addr">Directions for the rider (optional)</label>
            <p class="text-xs text-muted mt-1">Building, street or landmark, e.g. "Kisementi, blue gate next to the petrol station".</p>
          </div>
          <div class="field">
            <input id="ph" v-model.trim="phone" class="input h-12" inputmode="tel" autocomplete="tel" placeholder=" " />
            <label for="ph">Phone number*</label>
          </div>
        </template>

        <p v-if="error" class="text-sm text-bad" role="alert">{{ error }}</p>
        <button v-if="pin" class="btn-primary w-full h-12 text-base" :disabled="busy">{{ busy ? 'Saving…' : isFirst ? 'Save and continue' : 'Save location' }}</button>
      </form>

      <div v-if="isFirst" class="text-center mt-4">
        <button type="button" class="text-sm text-muted hover:text-ink hover:underline" @click="done">Skip for now</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '@/components/Icon.vue'
import LocationPicker from '@/components/LocationPicker.vue'
import { errorText, put } from '@/lib/api'
import { toast } from '@/lib/toast'
import { auth, loadUser } from '@/stores/auth'
import { loadHome, shop } from '@/stores/shop'

const route = useRoute()
const router = useRouter()
loadHome().catch(() => {})
const loc = auth.user?.location
const pin = ref<{ lat: number; lng: number } | null>(loc ? { lat: loc.lat, lng: loc.lng } : null)
const address = ref(loc?.address ?? '')
const phone = ref(auth.user?.phone ?? '')
const error = ref('')
const busy = ref(false)
const isFirst = computed(() => route.query.first === '1')
// The saved area's name, until the customer moves the pin.
const savedLabel = ref<string | null>(loc ? loc.district ?? loc.label : null)
watch(pin, (p, old) => old && p && (p.lat !== old.lat || p.lng !== old.lng) && (savedLabel.value = null))

async function save() {
  if (!pin.value) return
  if (!/^\+?[0-9 ]{9,16}$/.test(phone.value)) {
    error.value = 'Enter a phone number the rider can call, e.g. 0772 123456.'
    return
  }
  busy.value = true
  error.value = ''
  try {
    const r = await put('/my/location', { ...pin.value, address: address.value || null, phone: phone.value })
    await loadUser(true)
    const l = r.location
    // Tell them, in one line, what this location means for paying.
    if (l.zone === 'metro') toast(`${l.district ? `You're in ${l.district}` : "You're in our delivery area"}. You can pay on delivery or pay online.`)
    else if (l.zone === 'outside') toast(`${l.district ? `Delivering to ${l.district}` : "You're outside our pay on delivery area"}. Orders here are paid online when you order.`, 'info', 6000)
    else toast("Location saved. We couldn't confirm the area, so orders are paid online.", 'info', 6000)
    done()
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}

function done() {
  const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : '/'
  router.replace(next)
}
</script>

<style scoped>
/* Label on the border, as on the sign-in page. */
.field { position: relative; }
.field > label {
  position: absolute; left: 0.625rem; top: -0.55rem; padding: 0 0.25rem; background: #fff;
  font-size: 0.75rem; line-height: 1rem; color: var(--color-muted); pointer-events: none;
}
.field:focus-within > label { color: var(--color-brand-700); }
.field input:focus { border-color: var(--color-brand-600); }
</style>
